<?php

namespace App\Tests\Service;

use App\Entity\Event;
use App\Entity\EventAttendance;
use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\EventType;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\Accounting;
use App\Service\MembershipSeasons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le journal de caisse : ce qui est compte comme recette, ce qui est compte
 * comme creance, et ce qui n'est ni l'un ni l'autre.
 */
final class AccountingTest extends KernelTestCase
{
    private const MAIL_SUFFIX = '@compta.example.test';

    private EntityManagerInterface $em;
    private Accounting $accounting;
    private int $season;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->accounting = static::getContainer()->get(Accounting::class);
        $this->season = MembershipSeasons::of(new \DateTimeImmutable());
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnAnnualMembershipIsARecipe(): void
    {
        $this->application('Camille', 'Annuelle', MembershipType::Individual, PaymentMethod::Cheque);
        $this->em->flush();

        $summary = $this->summary();

        self::assertSame(1000, $summary['cotisationsCents']);
        self::assertSame(1000, $summary['byMethod']['cheque']);
        self::assertSame(0, $summary['unpaidCents']);
    }

    public function testAPaidPassageIsOneEuro(): void
    {
        $consumer = $this->application('Alix', 'Consommatrice', MembershipType::Consumer);
        $this->attendance($consumer, paid: true);
        $this->em->flush();

        $summary = $this->summary();

        self::assertSame(100, $summary['passagesCents']);
        self::assertSame(1, $summary['passages']);
        self::assertSame(100, $summary['byMethod']['especes']);
    }

    public function testAnUnpaidPassageIsACreance(): void
    {
        $consumer = $this->application('Alix', 'Consommatrice', MembershipType::Consumer);
        $this->attendance($consumer, paid: false);
        $this->em->flush();

        $summary = $this->summary();

        self::assertSame(0, $summary['passagesCents']);
        self::assertSame(100, $summary['unpaidCents']);
        self::assertCount(1, $this->mine($this->accounting->unpaid($this->season)));
    }

    /**
     * Une adhesion consommateur ne doit rien a l'annee : elle ne doit pas
     * apparaitre comme un impaye de 0 €, qui ferait chercher un reglement.
     */
    public function testAConsumerMembershipIsNeitherRecipeNorCreance(): void
    {
        $this->application('Alix', 'Consommatrice', MembershipType::Consumer);
        $this->em->flush();

        $summary = $this->summary();

        self::assertSame(0, $summary['totalCents']);
        self::assertSame(0, $summary['unpaidCount']);
    }

    /**
     * La purge nocturne supprime les evenements passes : un euro deja encaisse
     * ne doit pas disparaitre de la caisse avec la soiree qui l'a vu naitre.
     */
    public function testAPassageSurvivesThePurgeOfItsEvent(): void
    {
        $consumer = $this->application('Alix', 'Consommatrice', MembershipType::Consumer);
        $this->attendance($consumer, paid: true);
        $this->em->flush();

        $this->em->createQuery('DELETE FROM '.Event::class.' e WHERE e.title = :t')
            ->setParameter('t', 'Soirée de compta')->execute();
        $this->em->clear();

        $lines = $this->mine($this->accounting->entries($this->season));

        self::assertCount(1, $lines);
        self::assertSame('Soirée de compta', $lines[0]['label']);
        self::assertSame(100, $this->accounting->summary($this->season, $lines)['passagesCents']);
        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $lines[0]['date']->format('Y-m-d'),
        );
    }

    public function testTheJournalPutsTheMostRecentFirst(): void
    {
        $consumer = $this->application('Alix', 'Consommatrice', MembershipType::Consumer);
        $this->attendance($consumer, paid: true);
        $this->application('Camille', 'Annuelle', MembershipType::Individual, PaymentMethod::Cash);
        $this->em->flush();

        $dates = array_map(static fn (array $line) => $line['date'], $this->mine($this->accounting->entries($this->season)));

        self::assertGreaterThanOrEqual(2, \count($dates));
        for ($i = 1; $i < \count($dates); ++$i) {
            self::assertGreaterThanOrEqual($dates[$i], $dates[$i - 1], 'Le journal va du plus recent au plus ancien.');
        }
    }

    /**
     * @return array{cotisationsCents: int, passagesCents: int, totalCents: int,
     *     passages: int, byMethod: array<string, int>, unpaidCents: int, unpaidCount: int}
     */
    private function summary(): array
    {
        return $this->accounting->summary($this->season, $this->mine($this->accounting->entries($this->season)));
    }

    /**
     * Les seules lignes de ce test : la base porte les adhesions de
     * demonstration, et un total brut dependrait d'elles.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function mine(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            static fn (array $entry) => \in_array($entry['person'], ['Camille Annuelle', 'Alix Consommatrice'], true),
        ));
    }

    private function attendance(MembershipApplication $consumer, bool $paid): void
    {
        $event = (new Event())
            ->setTitle('Soirée de compta')
            ->setType(EventType::Weekly)
            ->setStartsAt(new \DateTimeImmutable('today'))
            ->setTimeLabel('18h30 - 00h');
        $this->em->persist($event);

        $attendance = new EventAttendance(new \DateTimeImmutable('today'), $consumer, $event);
        if ($paid) {
            $attendance->markPaid(PaymentMethod::Cash);
        }
        $this->em->persist($attendance);
    }

    private function application(string $firstName, string $lastName, MembershipType $type, ?PaymentMethod $method = null): MembershipApplication
    {
        $member = new Member('EDJ-'.random_int(1000, 9999));
        $this->em->persist($member);

        $application = (new MembershipApplication())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setBirthDate(new \DateTimeImmutable('1990-01-01'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail(mb_strtolower($lastName).self::MAIL_SUFFIX)
            ->setMembershipType($type)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($firstName.' '.$lastName)
            ->setMember($member);
        $application->validate($method, null);
        $this->em->persist($application);

        return $application;
    }

    private function purge(): void
    {
        $this->em->createQuery(
            'DELETE FROM '.EventAttendance::class.' p WHERE p.application IN ('
            .'SELECT a.id FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d)'
        )->setParameter('d', '%'.self::MAIL_SUFFIX)->execute();
        $this->em->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d')
            ->setParameter('d', '%'.self::MAIL_SUFFIX)->execute();
        $this->em->createQuery('DELETE FROM '.Event::class.' e WHERE e.title = :t')
            ->setParameter('t', 'Soirée de compta')->execute();
        $this->em->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
        $this->em->clear();
    }
}
