<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\MemberAccessLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Passage a l'adhesion annuelle par l'adherent consommateur, depuis son espace.
 */
final class MemberUpgradeTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Member::class)->execute();
    }

    private function create(MembershipType $type, bool $validated = true): MembershipApplication
    {
        $member = new Member('EDJ-'.random_int(100000, 999999));
        $application = (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName('Camille')
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail('camille-'.bin2hex(random_bytes(5)).'@example.test')
            ->setMembershipType($type)
            ->setSignaturePlace('Joigny')
            ->setSignatureName('Camille Durand')
            ->setMember($member);
        if ($validated) {
            $application->validate(PaymentMethod::Cheque, null);
        }
        $this->entityManager->persist($member);
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $application;
    }

    private function upgradeUrl(MembershipApplication $application): string
    {
        return static::getContainer()->get(MemberAccessLink::class)->upgradeUrl($application->getMember());
    }

    private function spaceUrl(MembershipApplication $application): string
    {
        return static::getContainer()->get(MemberAccessLink::class)->sheetUrl($application->getMember());
    }

    public function testTheSpaceOffersTheUpgradeToAConsumerOnly(): void
    {
        $this->client->request('GET', $this->spaceUrl($this->create(MembershipType::Consumer)));
        self::assertSelectorExists('a[href*="/passer-annuel"]');

        $this->client->request('GET', $this->spaceUrl($this->create(MembershipType::Individual)));
        self::assertSelectorNotExists('a[href*="/passer-annuel"]');
    }

    public function testAConsumerBecomesAnnualAndTheBoardIsTold(): void
    {
        $application = $this->create(MembershipType::Consumer);
        $id = $application->getId();

        $crawler = $this->client->request('GET', $this->upgradeUrl($application));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton("Passer à l'adhésion annuelle")->form();
        $form['member_upgrade[membershipType]']->select('individuelle');
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "C'est enregistré");

        $this->entityManager->clear();
        $saved = $this->entityManager->find(MembershipApplication::class, $id);
        self::assertSame(MembershipType::Individual, $saved->getMembershipType());
        // Une cotisation est due : l'adhesion n'est plus validee.
        self::assertSame(MembershipStatus::Pending, $saved->getStatus());

        $sent = array_filter(array_map(
            static fn ($envelope) => $envelope->getMessage()->getMessage(),
            static::getContainer()->get('messenger.transport.async')->getSent(),
        ), static fn ($message) => $message instanceof Email);
        self::assertNotEmpty($sent);
    }

    public function testFamilyNeedsAPrincipalMember(): void
    {
        $application = $this->create(MembershipType::Consumer);

        $crawler = $this->client->request('GET', $this->upgradeUrl($application));
        $form = $crawler->selectButton("Passer à l'adhésion annuelle")->form();
        $form['member_upgrade[membershipType]']->select('famille');
        $this->client->submit($form);

        self::assertSelectorExists('[role="alert"]');
    }

    public function testAnAnnualMemberCannotUpgrade(): void
    {
        $this->client->request('GET', $this->upgradeUrl($this->create(MembershipType::Individual)));

        self::assertSelectorTextContains('h1', 'ne peut pas passer');
    }

    public function testAnUnsignedUrlIsRefused(): void
    {
        $application = $this->create(MembershipType::Consumer);

        $this->client->request('GET', '/adhesion/espace/'.$application->getMemberNumber().'/passer-annuel');

        self::assertResponseStatusCodeSame(403);
    }
}
