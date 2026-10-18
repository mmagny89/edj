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
        bool $philibert = false,
        bool $imagesRefused = false,
        PaymentMethod $payment = PaymentMethod::Cheque,
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
            ->setPhilibertConsent($philibert)
            ->setImageRightsRefused($imagesRefused)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($firstName.' '.$lastName)
            ->setSubmittedAt(new \DateTimeImmutable($submittedAt))
            ->setMember($member);

        if ($validated) {
            $application->validate($payment, null);
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

    /**
     * La liste s'ouvre sur la saison en cours : c'est celle dont on s'occupe, et
     * les saisons passees noyaient la liste. Toutes les saisons : un choix.
     */
    public function testTheListOpensOnTheCurrentSeason(): void
    {
        // Dates relatives a aujourd'hui : les trois bulletins du setUp ont des
        // dates fixes, qui ne sont plus de la saison en cours des l'an prochain.
        $this->create('Courant', 'Zoe', MembershipType::Individual, 'today');
        $this->create('Ancien', 'Alain', MembershipType::Individual, '-3 years', validated: true);

        $body = $this->names('');

        self::assertStringContainsString('Zoe', $body);
        self::assertStringNotContainsString('Alain', $body);
    }

    public function testAllSeasonsIsAnExplicitChoice(): void
    {
        $this->create('Ancien', 'Alain', MembershipType::Individual, '-3 years', validated: true);

        $body = $this->names('saison=toutes');

        self::assertStringContainsString('Camille', $body);
        self::assertStringContainsString('Alain', $body);
    }

    public function testSearchingANameKeepsOnlyThatHousehold(): void
    {
        $body = $this->names('saison=toutes&q=durand');

        self::assertStringContainsString('Camille', $body);
        self::assertStringNotContainsString('Alice', $body);
    }

    /**
     * Le nom de l'adherent principal fait partie de la recherche : taper le
     * nom du parent qui porte la cotisation doit remonter tout le foyer.
     */
    public function testSearchingThePrincipalBringsBackTheWholeHousehold(): void
    {
        $body = $this->names('saison=toutes&q=louis');

        self::assertStringContainsString('Camille', $body);
        self::assertStringNotContainsString('Alice', $body);
    }

    public function testFilteringOnPendingKeepsOnlyWhatIsUnpaid(): void
    {
        $body = $this->names('saison=toutes&etat=en_attente');

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
        $body = $this->names('saison=toutes&formule=famille');

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

    /**
     * Philibert, droit a l'image et moyen de reglement : « les cheques », « les
     * refus de photo », « ceux dont l'adresse part chez Philibert ».
     */
    public function testFilteringOnThePhilibertBox(): void
    {
        $this->create('Phil', 'Oui', MembershipType::Individual, 'today', philibert: true);
        $this->create('Phil', 'Non', MembershipType::Individual, 'today');

        $with = $this->names('philibert=oui');
        $without = $this->names('philibert=non');

        self::assertStringContainsString('Oui', $with);
        self::assertStringNotContainsString('>Non', $with);
        self::assertStringContainsString('Non', $without);
        self::assertStringNotContainsString('Oui Phil', $without);
    }

    public function testFilteringOnImageRights(): void
    {
        $this->create('Photo', 'Refus', MembershipType::Individual, 'today', imagesRefused: true);
        $this->create('Photo', 'Libre', MembershipType::Individual, 'today');

        $refused = $this->names('photos=refused');
        $allowed = $this->names('photos=allowed');

        self::assertStringContainsString('Refus Photo', $refused);
        self::assertStringNotContainsString('Libre Photo', $refused);
        self::assertStringContainsString('Libre Photo', $allowed);
        self::assertStringNotContainsString('Refus Photo', $allowed);
    }

    public function testFilteringOnThePaymentMethod(): void
    {
        $this->create('Reglement', 'Cheque', MembershipType::Individual, 'today', validated: true, payment: PaymentMethod::Cheque);
        $this->create('Reglement', 'Especes', MembershipType::Individual, 'today', validated: true, payment: PaymentMethod::Cash);
        $this->create('Reglement', 'Attente', MembershipType::Individual, 'today');

        $cheque = $this->names('paiement=cheque');
        $none = $this->names('paiement=none');

        self::assertStringContainsString('Cheque Reglement', $cheque);
        self::assertStringNotContainsString('Especes Reglement', $cheque);
        self::assertStringContainsString('Attente Reglement', $none, 'Sans paiement : en attente, annule ou gratuit.');
        self::assertStringNotContainsString('Cheque Reglement', $none);
    }

    /**
     * L'URL se modifie a la main : une valeur inconnue vaut « pas de filtre ».
     */
    public function testAnUnknownFilterValueIsIgnored(): void
    {
        $this->create('Inconnu', 'Zoe', MembershipType::Individual, 'today');

        $body = $this->names('paiement=bidon&photos=bidon&philibert=bidon&etat=bidon');

        self::assertStringContainsString('Zoe Inconnu', $body);
    }

    public function testTheExportButtonCarriesTheCurrentFilters(): void
    {
        $this->client->request('GET', '/admin/adhesions?saison=toutes&paiement=cheque');

        self::assertSelectorExists('a[href*="export.csv"][href*="paiement=cheque"][href*="saison=toutes"]');
    }
}
