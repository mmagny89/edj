<?php

namespace App\Tests\Service;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\MailingAudience;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Qui recoit un message a une saison. La saison 2031 (octobre 2030 a septembre
 * 2031) n'appartient a aucun autre test : les comptes ne dependent que de ce
 * fichier.
 */
final class MailingAudienceTest extends KernelTestCase
{
    private const SEASON = 2031;
    private const MAIL_SUFFIX = '@audience.example.test';

    private EntityManagerInterface $em;
    private MailingAudience $audience;
    private int $serial = 0;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->audience = static::getContainer()->get(MailingAudience::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testOnlyValidatedBulletinsOfTheSeasonAreReached(): void
    {
        $this->bulletin('valide');
        $this->bulletin('attente', validated: false);
        $this->bulletin('autre-saison', submittedAt: '2029-12-01');
        $this->em->flush();

        $result = $this->audience->resolve(self::SEASON, true, true);

        self::assertSame(['valide'.self::MAIL_SUFFIX], $this->addresses($result));
    }

    public function testTheChoiceOfAudienceIsRespected(): void
    {
        $this->bulletin('annuel');
        $this->bulletin('consommateur', type: MembershipType::Consumer);
        $this->em->flush();

        self::assertSame(['annuel'.self::MAIL_SUFFIX], $this->addresses($this->audience->resolve(self::SEASON, true, false)));
        self::assertSame(['consommateur'.self::MAIL_SUFFIX], $this->addresses($this->audience->resolve(self::SEASON, false, true)));
        self::assertSame(2, $this->audience->resolve(self::SEASON, true, true)->count());
        self::assertSame(0, $this->audience->resolve(self::SEASON, false, false)->count());
    }

    public function testSomeoneWhoUnsubscribedIsNeverWrittenTo(): void
    {
        $this->bulletin('abonne');
        $this->bulletin('desinscrit')->getMember()->optOutOfMailings();
        $this->em->flush();

        $result = $this->audience->resolve(self::SEASON, true, true);

        self::assertSame(['abonne'.self::MAIL_SUFFIX], $this->addresses($result));
        self::assertSame(1, $result->skippedOptedOut);
    }

    public function testSomeoneWithoutAnAddressIsCountedNotWrittenTo(): void
    {
        $this->bulletin('joignable');
        $this->bulletin('sans-adresse')->setEmail(null);
        $this->em->flush();

        $result = $this->audience->resolve(self::SEASON, true, true);

        self::assertCount(1, $result->recipients);
        self::assertSame(1, $result->skippedWithoutAddress);
    }

    public function testAMinorsGuardianIsWrittenToToo(): void
    {
        $minor = $this->bulletin('enfant', type: MembershipType::Family);
        $minor->setBirthDate(new \DateTimeImmutable('-10 years'))->setGuardianEmail('parent'.self::MAIL_SUFFIX);
        $this->bulletin('adulte')->setGuardianEmail('ignore'.self::MAIL_SUFFIX);
        $this->em->flush();

        $addresses = $this->addresses($this->audience->resolve(self::SEASON, true, true));

        self::assertContains('parent'.self::MAIL_SUFFIX, $addresses);
        self::assertContains('enfant'.self::MAIL_SUFFIX, $addresses);
        self::assertNotContains('ignore'.self::MAIL_SUFFIX, $addresses, 'Le responsable legal d\'un adulte n\'a rien a recevoir.');
    }

    public function testASharedAddressReceivesOneMessage(): void
    {
        $this->bulletin('parent', email: 'foyer'.self::MAIL_SUFFIX);
        $this->bulletin('enfant', email: 'FOYER'.self::MAIL_SUFFIX);
        $this->em->flush();

        $result = $this->audience->resolve(self::SEASON, true, true);

        self::assertCount(1, $result->recipients);
        self::assertSame(1, $result->sharedAddress);
        self::assertSame(2, $result->members);
    }

    /**
     * Passer de consommateur a annuel dans la saison laisse deux bulletins valides :
     * la personne est un adherent annuel, et n'est ecrite qu'une fois.
     */
    public function testSomeoneWhoUpgradedIsAnAnnualMemberAndCountedOnce(): void
    {
        $consumer = $this->bulletin('passage', type: MembershipType::Consumer);
        $annual = (new MembershipApplication())
            ->setFirstName('Passage')->setLastName('Test')
            ->setBirthDate(new \DateTimeImmutable('1990-01-01'))->setPostalCode('89300')->setCity('Joigny')
            ->setEmail('passage'.self::MAIL_SUFFIX)->setMembershipType(MembershipType::Individual)
            ->setStatutesAccepted(true)->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')->setSignatureName('Passage Test')
            ->setSubmittedAt(new \DateTimeImmutable('2030-12-15'))
            ->setMember($consumer->getMember());
        $annual->validate(PaymentMethod::Cash, null, new \DateTimeImmutable('2030-12-15'));
        $this->em->persist($annual);
        $this->em->flush();

        self::assertSame(1, $this->audience->resolve(self::SEASON, true, false)->count());
        self::assertSame(0, $this->audience->resolve(self::SEASON, false, true)->count(), 'Il n\'est plus un consommateur.');
    }

    public function testAnAnonymizedBulletinIsNeverReached(): void
    {
        $this->bulletin('efface')->anonymize();
        $this->em->flush();

        self::assertSame(0, $this->audience->resolve(self::SEASON, true, true)->count());
    }

    /**
     * @return list<string>
     */
    private function addresses(\App\Service\MailingAudienceResult $result): array
    {
        $addresses = array_map(static fn ($r) => $r->address, $result->recipients);
        sort($addresses);

        return $addresses;
    }

    private function bulletin(
        string $name,
        bool $validated = true,
        string $submittedAt = '2030-12-01',
        MembershipType $type = MembershipType::Individual,
        ?string $email = null,
    ): MembershipApplication {
        ++$this->serial;
        $member = new Member('EDJ-9'.str_pad((string) $this->serial, 3, '0', \STR_PAD_LEFT).random_int(10, 99));
        $this->em->persist($member);

        $application = (new MembershipApplication())
            ->setFirstName(ucfirst($name))->setLastName('Test')
            ->setBirthDate(new \DateTimeImmutable('1985-01-01'))->setPostalCode('89300')->setCity('Joigny')
            ->setEmail($email ?? $name.self::MAIL_SUFFIX)->setMembershipType($type)
            ->setStatutesAccepted(true)->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')->setSignatureName(ucfirst($name).' Test')
            ->setSubmittedAt(new \DateTimeImmutable($submittedAt))
            ->setMember($member);
        if ($validated) {
            $application->validate(MembershipType::Consumer === $type ? null : PaymentMethod::Cash, null, new \DateTimeImmutable($submittedAt));
        }
        $this->em->persist($application);

        return $application;
    }

    private function purge(): void
    {
        $this->em->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.signatureName LIKE :s')->setParameter('s', '% Test')->execute();
        $this->em->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
        $this->em->clear();
    }
}
