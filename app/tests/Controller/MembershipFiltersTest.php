<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Filtres de la liste des adhesions : retrouver une personne, un foyer, les
 * impayes, une saison.
 *
 * Les filtres passent par l'URL : une liste filtree se partage par son lien,
 * et c'est ce lien que ces cas interrogent.
 */
final class MembershipFiltersTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();

        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier('admin@example.test'));

        // Trois bulletins qui se distinguent sur chaque critere.
        $this->create('Durand', 'Louis', MembershipType::Individual, '2026-10-05', validated: true);
        $this->create('Durand', 'Camille', MembershipType::Family, '2026-10-06', principal: 'Louis Durand');
        $this->create('Lefevre', 'Alice', MembershipType::Individual, '2025-11-12', validated: true);
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

    private function create(
        string $lastName,
        string $firstName,
        MembershipType $type,
        string $submittedAt,
        bool $validated = false,
        ?string $principal = null,
    ): MembershipApplication {
        $member = new Member('EDJ-'.random_int(100000, 999999));
        $application = (new MembershipApplication())
            ->setLastName($lastName)
            ->setFirstName($firstName)
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail(mb_strtolower($firstName).'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setMembershipType($type)
            ->setPrincipalMemberName($principal)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($firstName.' '.$lastName)
            ->setSubmittedAt(new \DateTimeImmutable($submittedAt))
            ->setMember($member);

        if ($validated) {
            $application->validate(PaymentMethod::Cheque, null);
        }

        $this->entityManager->persist($member);
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $application;
    }

    private function names(string $query): string
    {
        $this->client->request('GET', '/admin/adhesions?'.$query);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    public function testWithoutAFilterEveryBulletinIsListed(): void
    {
        $body = $this->names('');

        self::assertStringContainsString('Louis', $body);
        self::assertStringContainsString('Camille', $body);
        self::assertStringContainsString('Alice', $body);
    }

    public function testSearchingANameKeepsOnlyThatHousehold(): void
    {
        $body = $this->names('q=durand');

        self::assertStringContainsString('Camille', $body);
        self::assertStringNotContainsString('Alice', $body);
    }

    /**
     * Le nom de l'adherent principal fait partie de la recherche : taper le
     * nom du parent qui porte la cotisation doit remonter tout le foyer.
     */
    public function testSearchingThePrincipalBringsBackTheWholeHousehold(): void
    {
        $body = $this->names('q=louis');

        self::assertStringContainsString('Camille', $body);
        self::assertStringNotContainsString('Alice', $body);
    }

    public function testFilteringOnPendingKeepsOnlyWhatIsUnpaid(): void
    {
        $body = $this->names('etat=en_attente');

        self::assertStringContainsString('Camille', $body);
        self::assertStringNotContainsString('Alice', $body);
    }

    /**
     * La saison 2027 court du 1er octobre 2026 au 30 septembre 2027 : les deux
     * bulletins d'octobre 2026 y sont, celui de novembre 2025 non.
     */
    public function testFilteringOnASeasonKeepsItsBulletins(): void
    {
        $body = $this->names('saison=2027');

        self::assertStringContainsString('Camille', $body);
        self::assertStringContainsString('Louis', $body);
        self::assertStringNotContainsString('Alice', $body);
    }

    public function testFilteringOnAFormulaKeepsItsBulletins(): void
    {
        $body = $this->names('formule=famille');

        self::assertStringContainsString('Camille', $body);
        self::assertStringNotContainsString('Alice', $body);
    }

    public function testFiltersCombine(): void
    {
        $body = $this->names('saison=2027&etat=validee');

        self::assertStringContainsString('Louis', $body);
        self::assertStringNotContainsString('Camille', $body);
        self::assertStringNotContainsString('Alice', $body);
    }

    public function testAnEmptyResultSaysSo(): void
    {
        $this->client->request('GET', '/admin/adhesions?q=introuvable');

        self::assertSelectorTextContains('body', 'Aucun bulletin ne correspond à ces filtres.');
    }
}
