<?php

namespace App\Tests\Controller;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Consultation, fiche, anonymisation et liste Philibert des bulletins.
 * Ecrit en base de test : la table des bulletins y est videe a chaque cas.
 */
final class MembershipAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** Adresse propre a chaque cas : le plafond de confirmations par destinataire survit entre deux lancements. */
    private string $paperEmail;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->paperEmail = 'a-'.bin2hex(random_bytes(5)).'@papier.example.test';
        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class)->execute();
        $this->logIn('admin@example.test');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class)->execute();
        parent::tearDown();
    }

    private function logIn(string $identifier): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier($identifier));
    }

    private function create(string $email, bool $philibert = false, string $first = 'Camille'): MembershipApplication
    {
        $application = (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName($first)
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail($email)
            ->setMembershipType(MembershipType::Individual)
            ->setPhilibertConsent($philibert)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($first.' Durand');
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $application;
    }

    public function testDetailPageShowsTheBulletin(): void
    {
        $application = $this->create('camille@example.test');

        $this->client->request('GET', '/admin/adhesions/'.$application->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Camille Durand');
        self::assertSelectorExists('a[href$="/fiche"]');
    }

    public function testPrintableSheetIsAStandalonePage(): void
    {
        $application = $this->create('camille@example.test');

        $crawler = $this->client->request('GET', sprintf('/admin/adhesions/%d/fiche', $application->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Fiche d'adhésion");
        self::assertSame(0, $crawler->filter('nav[aria-label="Administration"]')->count(), 'Pas de barre latérale sur la fiche.');
    }

    public function testAnonymizingFromTheDetailPageErasesTheBulletin(): void
    {
        $application = $this->create('camille@example.test');
        $id = $application->getId();

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$id);
        $this->client->submit($crawler->filter(sprintf('form[action$="/%d/anonymiser"]', $id))->form());

        self::assertResponseRedirects('/admin/adhesions/'.$id);
        $this->entityManager->clear();
        $stored = $this->entityManager->find(MembershipApplication::class, $id);
        self::assertTrue($stored->isAnonymized());
        self::assertSame('ANONYMISÉ', $stored->getLastName());
        self::assertStringEndsWith('@anonyme.invalid', (string) $stored->getEmail());
    }

    public function testAnonymizedBulletinHasNoSheetToPrint(): void
    {
        $application = $this->create('camille@example.test')->anonymize();
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/admin/adhesions/%d/fiche', $application->getId()));

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
    }

    public function testAnonymizationWithAnInvalidTokenIsRefused(): void
    {
        $application = $this->create('camille@example.test');
        $id = $application->getId();

        $this->client->request('POST', sprintf('/admin/adhesions/%d/anonymiser', $id), ['_token' => 'faux']);

        $this->entityManager->clear();
        self::assertFalse($this->entityManager->find(MembershipApplication::class, $id)->isAnonymized());
    }

    public function testBulkAnonymizationOnlyTouchesTheTickedBulletins(): void
    {
        $a = $this->create('a@example.test', first: 'Alice');
        $b = $this->create('b@example.test', first: 'Bruno');
        $c = $this->create('c@example.test', first: 'Chloé');

        $crawler = $this->client->request('GET', '/admin/adhesions');
        $form = $crawler->filter('form[action$="/anonymiser"]')->form();
        $form['ids'][0]->tick();
        $form['ids'][2]->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/adhesions');
        $this->entityManager->clear();
        $state = fn (MembershipApplication $x) => $this->entityManager->find(MembershipApplication::class, $x->getId())->isAnonymized();
        self::assertTrue($state($a));
        self::assertFalse($state($b), 'Le bulletin non coché reste intact.');
        self::assertTrue($state($c));
    }

    public function testBulkAnonymizationIgnoresForgedIdentifiers(): void
    {
        $application = $this->create('camille@example.test');

        $crawler = $this->client->request('GET', '/admin/adhesions');
        $token = $crawler->filter('form[action$="/anonymiser"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/adhesions/anonymiser', [
            '_token' => $token,
            'ids' => ['abc', '-5', '0', '1; DROP TABLE membership_application'],
        ]);

        self::assertResponseRedirects('/admin/adhesions');
        $this->entityManager->clear();
        self::assertFalse($this->entityManager->find(MembershipApplication::class, $application->getId())->isAnonymized());
    }

    public function testPhilibertListHoldsOnlyConsentingNonAnonymizedAddresses(): void
    {
        $this->create('oui@example.test', philibert: true);
        $this->create('non@example.test', philibert: false);
        $this->create('anonyme@example.test', philibert: true)->anonymize();
        $this->create('OUI@Example.test', philibert: true, first: 'Doublon');
        $this->entityManager->flush();

        $this->client->request('GET', '/admin/adhesions/philibert.csv');
        $response = $this->client->getResponse();
        $lines = array_values(array_filter(explode("\n", ltrim((string) $this->client->getInternalResponse()->getContent(), "\xEF\xBB\xBF"))));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame(['email', 'oui@example.test'], array_map('trim', $lines));
    }

    public function testPhilibertListContainsNothingButTheEmail(): void
    {
        $this->create('oui@example.test', philibert: true, first: 'Camille');

        $this->client->request('GET', '/admin/adhesions/philibert.csv');
        $content = (string) $this->client->getInternalResponse()->getContent();

        // L'autorisation donnee porte sur l'adresse e-mail seule.
        self::assertStringNotContainsString('Camille', $content);
        self::assertStringNotContainsString('Durand', $content);
    }

    /**
     * Le suivi des adhesions appartient au bureau entier : un contributeur
     * lit les bulletins, les valide et les annule. Le refus porte desormais
     * sur les seuls comptes d'administration (voir AdminAccessTest).
     */
    public function testAContributorReachesEveryBulletinPage(): void
    {
        $application = $this->create('camille@example.test', philibert: true);
        $this->logIn('contributeur@example.test');

        foreach (['/admin/adhesions', '/admin/adhesions/philibert.csv', '/admin/adhesions/'.$application->getId(), sprintf('/admin/adhesions/%d/fiche', $application->getId())] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    /**
     * @param array<string, string|array<string>> $overrides
     */
    private function submitPaperForm(array $overrides = [], bool $attest = true, bool $sendMail = true): void
    {
        $crawler = $this->client->request('GET', '/admin/adhesions/nouveau');
        $form = $crawler->selectButton("Inscrire l'adhérent")->form();

        $values = array_merge([
            'paper_membership[submittedAt]' => '2026-10-10',
            'paper_membership[lastName]' => 'Leroy',
            'paper_membership[firstName]' => 'Alice',
            'paper_membership[birthDate]' => '1985-03-12',
            'paper_membership[postalCode]' => '89300',
            'paper_membership[city]' => 'Joigny',
            'paper_membership[email]' => $this->paperEmail,
        ], $overrides);
        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }
        $form['paper_membership[membershipType]']->select('individuelle');
        if ($attest) {
            $form['paper_membership[paperConfirmed]']->tick();
        }
        if (!$sendMail) {
            $form['paper_membership[sendConfirmation]']->untick();
        }

        $this->client->submit($form);
    }

    public function testAnAdministratorCanRegisterAPaperForm(): void
    {
        $this->submitPaperForm();

        self::assertResponseRedirects();
        $stored = $this->entityManager->getRepository(MembershipApplication::class)->findOneBy(['email' => $this->paperEmail]);
        self::assertNotNull($stored);
        self::assertTrue($stored->isPaper());
        self::assertSame('2026-10-10', $stored->getSubmittedAt()->format('Y-m-d'), 'La date est celle de la fiche papier.');
        self::assertTrue($stored->isStatutesAccepted(), 'L\'acceptation figure sur le papier.');
        self::assertTrue($stored->isRulesAccepted());
    }

    public function testThePaperFormIsRefusedWithoutTheAttestation(): void
    {
        $this->submitPaperForm(attest: false);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->entityManager->getRepository(MembershipApplication::class)->findOneBy(['email' => $this->paperEmail]));
    }

    public function testThePaperFormKeepsTheSameRulesAsTheOnlineOne(): void
    {
        $this->submitPaperForm(['paper_membership[postalCode]' => '12']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAMinorOnPaperNeedsAGuardian(): void
    {
        $this->submitPaperForm(['paper_membership[birthDate]' => (new \DateTimeImmutable('-9 years'))->format('Y-m-d')]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testConfirmationEmailIsSentUnlessUnticked(): void
    {
        $this->submitPaperForm();
        $queued = static::getContainer()->get('messenger.transport.async')->getSent();
        self::assertCount(1, $queued, 'Un courriel de confirmation part.');

        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class)->execute();
        static::getContainer()->get('messenger.transport.async')->reset();
        $this->submitPaperForm(sendMail: false);
        self::assertCount(0, static::getContainer()->get('messenger.transport.async')->getSent());
    }

    public function testPaperBulletinIsBadgedInTheList(): void
    {
        $this->submitPaperForm();

        $this->client->request('GET', '/admin/adhesions');

        self::assertSelectorTextContains('tbody', 'Fiche papier');
    }

    public function testAContributorCanRegisterAMember(): void
    {
        $this->logIn('contributeur@example.test');

        $this->client->request('GET', '/admin/adhesions/nouveau');

        self::assertResponseIsSuccessful();
    }
}
