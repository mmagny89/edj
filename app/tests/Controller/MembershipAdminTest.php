<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipEventType;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\MemberDirectory;
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
        // Les adherents nes de ces bulletins : sans cela, ils s'accumulent d'un cas
        // a l'autre et d'une execution a l'autre, et fausseraient tout comptage.
        $this->entityManager->createQuery('DELETE FROM '.Member::class)->execute();
        $this->logIn('admin@example.test');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class)->execute();
        // Les adherents nes de ces bulletins : sans cela, ils s'accumulent d'un cas
        // a l'autre et d'une execution a l'autre, et fausseraient tout comptage.
        $this->entityManager->createQuery('DELETE FROM '.Member::class)->execute();
        parent::tearDown();
    }

    private function logIn(string $identifier): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier($identifier));
    }

    private function create(string $email, bool $philibert = false, string $first = 'Camille', bool $validated = false, ?string $submittedAt = null, MembershipType $type = MembershipType::Individual): MembershipApplication
    {
        $application = (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName($first)
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail($email)
            ->setMembershipType($type)
            ->setPhilibertConsent($philibert)
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($first.' Durand');
        if (null !== $submittedAt) {
            $application->setSubmittedAt(new \DateTimeImmutable($submittedAt));
        }
        if ($validated) {
            $application->validate(PaymentMethod::Cheque, null);
        }
        static::getContainer()->get(MemberDirectory::class)->attach($application);
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

    public function testAFicheCanBeCorrectedAndTheCorrectionIsLogged(): void
    {
        $application = $this->create('camille@example.test', validated: true);
        $id = $application->getId();

        $crawler = $this->client->request('GET', sprintf('/admin/adhesions/%d/modifier', $id));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer la correction')->form([
            'edit_membership[city]' => 'Auxerre',
            'edit_membership[phone]' => '06 98 76 54 32',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/adhesions/'.$id);
        $this->entityManager->clear();
        $saved = $this->entityManager->find(MembershipApplication::class, $id);
        self::assertSame('Auxerre', $saved->getCity());
        self::assertSame('06 98 76 54 32', $saved->getPhone());
        self::assertSame(MembershipEventType::Edited, $saved->getHistory()->last()->getType());
        self::assertSame(MembershipStatus::Validated, $saved->getStatus(), 'Corriger une fiche ne change pas son etat.');
    }

    /**
     * Corriger un nom change l'identite : le numero reste, et le renouvellement
     * saisi sous le vrai nom retrouve la personne au lieu de lui donner un
     * second numero.
     */
    public function testCorrectingTheNameKeepsTheNumberAndMovesTheIdentity(): void
    {
        $application = $this->create('camille@example.test', validated: true);
        $id = $application->getId();
        $number = $application->getMember()->getNumber();

        $crawler = $this->client->request('GET', sprintf('/admin/adhesions/%d/modifier', $id));
        $this->client->submit($crawler->selectButton('Enregistrer la correction')->form(['edit_membership[lastName]' => 'Durant']));

        self::assertResponseRedirects();
        $this->entityManager->clear();
        $saved = $this->entityManager->find(MembershipApplication::class, $id);
        self::assertSame($number, $saved->getMember()->getNumber());
        self::assertSame(
            MemberDirectory::identityKey('Durant', 'Camille', new \DateTimeImmutable('1990-04-02')),
            $saved->getMember()->getIdentityKey(),
        );
    }

    public function testCorrectionIsRefusedWhenItWouldMergeTwoPeople(): void
    {
        $this->create('camille@example.test', first: 'Camille', validated: true);
        $other = $this->create('zoe@example.test', first: 'Zoé', validated: true);

        $crawler = $this->client->request('GET', sprintf('/admin/adhesions/%d/modifier', $other->getId()));
        $this->client->submit($crawler->selectButton('Enregistrer la correction')->form(['edit_membership[firstName]' => 'Camille']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'porte déjà ce nom');
    }

    public function testAnAnonymizedFicheCannotBeCorrected(): void
    {
        $application = $this->create('camille@example.test');
        $application->anonymize(null);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/admin/adhesions/%d/modifier', $application->getId()));

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
    }

    /**
     * Une personne reprise d'un fichier peut ne pas avoir de date de naissance :
     * sa fiche, sa liste et sa correction doivent s'afficher sans elle.
     */
    public function testAnImportedFicheWithoutBirthDateDisplaysAndCanBeCompleted(): void
    {
        $application = $this->create('sans-date@example.test', validated: true);
        $application->setBirthDate(null)->enterFromImport(null);
        $this->entityManager->flush();
        $id = $application->getId();

        $this->client->request('GET', '/admin/adhesions/'.$id);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/adhesions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Date de naissance non renseignée');
        $this->client->request('GET', sprintf('/admin/adhesions/%d/fiche', $id));
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', sprintf('/admin/adhesions/%d/modifier', $id));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Enregistrer la correction')->form(['edit_membership[birthDate]' => '1985-06-12']));

        self::assertResponseRedirects('/admin/adhesions/'.$id);
        $this->entityManager->clear();
        self::assertSame('1985-06-12', $this->entityManager->find(MembershipApplication::class, $id)->getBirthDate()?->format('Y-m-d'));
    }

    /**
     * La date reste obligatoire pour une fiche saisie a la main.
     */
    public function testAPaperFicheStillRequiresABirthDate(): void
    {
        $crawler = $this->client->request('GET', '/admin/adhesions/nouveau');
        $form = $crawler->selectButton("Inscrire l'adhérent")->form([
            'paper_membership[lastName]' => 'Durand',
            'paper_membership[firstName]' => 'Zoé',
            'paper_membership[postalCode]' => '89300',
            'paper_membership[city]' => 'Joigny',
            'paper_membership[membershipType]' => 'individuelle',
            'paper_membership[paperConfirmed]' => '1',
        ]);
        $form['paper_membership[birthDate]'] = '';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La date de naissance est obligatoire');
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

    /**
     * La liste part chez un tiers : elle ne nomme que des adherents actifs.
     * Chaque ligne ci-dessous est une raison d'etre exclu, sauf la premiere.
     */
    public function testPhilibertListHoldsOnlyConsentingNonAnonymizedAddresses(): void
    {
        $this->create('oui@example.test', philibert: true, validated: true);
        $this->create('non@example.test', philibert: false, validated: true);
        $this->create('anonyme@example.test', philibert: true, validated: true)->anonymize();
        $this->create('OUI@Example.test', philibert: true, first: 'Doublon', validated: true);
        $this->entityManager->flush();

        $lines = $this->philibertLines();

        self::assertSame('email;date_adhesion', $lines[0]);
        self::assertCount(2, $lines);
        self::assertSame('oui@example.test;'.date('Y-m-d'), $lines[1]);
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testPhilibertListExcludesAnApplicationNotYetPaid(): void
    {
        $this->create('attente@example.test', philibert: true);

        self::assertSame(['email;date_adhesion'], $this->philibertLines());
    }

    public function testPhilibertListExcludesACancelledApplication(): void
    {
        $this->create('annulee@example.test', philibert: true, validated: true)->cancel(null);
        $this->entityManager->flush();

        self::assertSame(['email;date_adhesion'], $this->philibertLines());
    }

    /**
     * Une adhesion d'une saison passee n'engage plus personne : transmettre
     * son adresse serait communiquer celle de quelqu'un qui n'est plus
     * adherent.
     */
    public function testPhilibertListExcludesAPastSeason(): void
    {
        $this->create('ancien@example.test', philibert: true, validated: true, submittedAt: '-2 years');

        self::assertSame(['email;date_adhesion'], $this->philibertLines());
    }

    /**
     * La formule consommateur, gratuite a l'annee, n'ouvre pas droit a
     * l'avantage : meme validee, meme autorisee, elle n'est pas dans la liste.
     */
    public function testPhilibertListExcludesConsumers(): void
    {
        $this->create('conso@example.test', philibert: true, validated: true, type: MembershipType::Consumer);
        $this->create('annuel@example.test', philibert: true, validated: true);

        $lines = $this->philibertLines();

        self::assertCount(2, $lines);
        self::assertStringStartsWith('annuel@example.test;', $lines[1]);
    }

    /**
     * Un foyer qui a plusieurs bulletins valides sur une meme adresse ne la
     * recoit qu'une fois, quelle que soit la casse, avec sa premiere date.
     */
    public function testPhilibertListHasNoDuplicate(): void
    {
        $this->create('foyer@example.test', philibert: true, validated: true);
        $this->create('FOYER@example.test', philibert: true, first: 'Alice', validated: true);

        $lines = $this->philibertLines();

        self::assertCount(2, $lines);
        self::assertStringStartsWith('foyer@example.test;', $lines[1]);
    }

    /**
     * @return list<string>
     */
    private function philibertLines(): array
    {
        $this->client->request('GET', '/admin/adhesions/philibert.csv');
        self::assertResponseIsSuccessful();

        return array_map('trim', array_values(array_filter(explode("\n", ltrim((string) $this->client->getInternalResponse()->getContent(), "\xEF\xBB\xBF")))));
    }

    public function testPhilibertListContainsNothingButTheEmail(): void
    {
        $this->create('oui@example.test', philibert: true, first: 'Camille', validated: true);

        $this->client->request('GET', '/admin/adhesions/philibert.csv');
        $content = (string) $this->client->getInternalResponse()->getContent();

        // L'autorisation porte sur l'adresse e-mail, a laquelle s'ajoute la date
        // d'adhesion : ni nom, ni telephone.
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

    public function testTheSheetShowsWhatTheMemberOwes(): void
    {
        $application = $this->create('camille@example.test');
        $application->setMembershipType(MembershipType::Family);
        $this->entityManager->flush();

        $this->client->request('GET', '/admin/adhesions/'.$application->getId());

        self::assertSelectorTextContains('body', 'Montant dû');
        self::assertSelectorTextContains('body', '5 €');
    }

    public function testTheCardIsShownInlineFromTheAdministration(): void
    {
        $application = $this->create('camille@example.test');

        $this->client->request('GET', sprintf('/admin/adhesions/%d/carte.pdf', $application->getId()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('inline;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAnonymizedBulletinHasNoCard(): void
    {
        $application = $this->create('camille@example.test');
        $application->anonymize();
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/admin/adhesions/%d/carte.pdf', $application->getId()));

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
    }

    public function testThePaperFormLabelsTheFamilyFormulaWithWhatIsOwed(): void
    {
        $crawler = $this->client->request('GET', '/admin/adhesions/nouveau');

        self::assertStringContainsString(
            'Adhésion famille annuelle — 5 € par personne supplémentaire',
            $crawler->filter('main')->text(),
        );
    }

    /**
     * La fiche montre le parcours de la personne : tous ses bulletins, saison
     * apres saison, avec un lien vers chacun. C'est ce qui dit, devant un
     * renouvellement, depuis quand elle adhere.
     */
    public function testTheSheetListsEveryBulletinOfThePerson(): void
    {
        $old = $this->create('camille@example.test', validated: true, submittedAt: '2024-11-05');
        $new = $this->create('camille@example.test');

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$new->getId());

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#t-parcours')->ancestors()->first();
        self::assertStringContainsString('2025', $section->text(), 'Un depot de novembre 2024 vaut pour la saison 2025.');
        self::assertStringContainsString('cette fiche', $section->text());
        self::assertCount(1, $section->filter(sprintf('a[href$="/admin/adhesions/%d"]', $old->getId())));
    }

    public function testASingleBulletinHasNoPathSection(): void
    {
        $application = $this->create('camille@example.test');

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$application->getId());

        self::assertCount(0, $crawler->filter('#t-parcours'));
    }

    /**
     * Un ancien adherent : le bulletin d'une saison passee, valide. C'est a lui
     * que le bouton « Renouveler » s'adresse.
     */
    private function former(?string $email = null): MembershipApplication
    {
        return $this->create($email ?? 'ancien-'.bin2hex(random_bytes(4)).'@example.test', validated: true, submittedAt: '-14 months');
    }

    private function submitRenewal(MembershipApplication $application, array $overrides = []): void
    {
        $crawler = $this->client->request('GET', sprintf('/admin/adhesions/%d/renouveler', $application->getId()));
        $form = $crawler->selectButton('Enregistrer le renouvellement')->form();
        foreach ($overrides as $name => $value) {
            $form[$name] = $value;
        }
        $form['paper_membership[paperConfirmed]']->tick();
        $form['paper_membership[sendConfirmation]']->untick();

        $this->client->submit($form);
    }

    public function testTheRenewButtonIsOfferedToAFormerMember(): void
    {
        $application = $this->former();

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$application->getId());

        self::assertCount(1, $crawler->filter(sprintf('a[href$="/admin/adhesions/%d/renouveler"]', $application->getId())));
    }

    public function testNoRenewButtonOnceTheSeasonIsTaken(): void
    {
        $application = $this->former('meme@example.test');
        $current = $this->create('meme@example.test');

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$current->getId());

        self::assertCount(0, $crawler->filter('a[href$="/renouveler"]'));
        self::assertSame($application->getMemberNumber(), $current->getMemberNumber());
    }

    public function testTheRenewalFormArrivesPrefilledUnderTheSameNumber(): void
    {
        $application = $this->former();

        $crawler = $this->client->request('GET', sprintf('/admin/adhesions/%d/renouveler', $application->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', (string) $application->getMemberNumber());
        $form = $crawler->selectButton('Enregistrer le renouvellement')->form();
        self::assertSame('Durand', $form['paper_membership[lastName]']->getValue());
        self::assertSame($application->getEmail(), $form['paper_membership[email]']->getValue());
    }

    /**
     * Le coeur de l'action : un nouveau bulletin, en attente, sous le meme
     * numero — jamais un second adherent.
     */
    public function testRenewingKeepsTheNumberAndWaitsForPayment(): void
    {
        $application = $this->former();
        $number = $application->getMemberNumber();

        $this->submitRenewal($application);

        self::assertResponseRedirects();
        $this->entityManager->clear();
        $all = $this->entityManager->getRepository(MembershipApplication::class)->findBy([], ['id' => 'ASC']);

        self::assertCount(2, $all);
        self::assertSame($number, $all[1]->getMemberNumber());
        self::assertTrue($all[1]->isPending());
        self::assertTrue($all[1]->isPaper(), 'Un renouvellement saisi par le bureau est une fiche papier.');
        self::assertCount(1, $this->entityManager->getRepository(Member::class)->findAll());
    }

    public function testASeasonCannotBeRenewedTwice(): void
    {
        $application = $this->former();
        $this->submitRenewal($application);

        $this->client->request('GET', sprintf('/admin/adhesions/%d/renouveler', $application->getId()));

        self::assertResponseRedirects();
        $this->entityManager->clear();
        self::assertCount(2, $this->entityManager->getRepository(MembershipApplication::class)->findAll());
    }

    /**
     * La date de la fiche peut viser une autre saison que celle d'aujourd'hui :
     * viser la saison deja prise est refuse a l'enregistrement.
     */
    public function testTheChosenDateCannotTargetASeasonAlreadyTaken(): void
    {
        $application = $this->former();

        $this->submitRenewal($application, [
            'paper_membership[submittedAt]' => $application->getSubmittedAt()->format('Y-m-d'),
        ]);

        self::assertSelectorTextContains('main', 'a déjà une adhésion pour la saison');
        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(MembershipApplication::class)->findAll());
    }

    public function testAnAnonymizedBulletinCannotBeRenewed(): void
    {
        $application = $this->former();
        $application->anonymize();
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/admin/adhesions/%d/renouveler', $application->getId()));

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
    }

    /**
     * L'export de la liste : exactement ce qu'on voit, avec toutes les
     * informations d'un bulletin.
     *
     * @return list<array<string, string>>
     */
    private function exportRows(string $query = ''): array
    {
        $this->client->request('GET', '/admin/adhesions/export.csv'.$query);
        self::assertResponseIsSuccessful();

        $lines = array_values(array_filter(explode("\n", ltrim((string) $this->client->getInternalResponse()->getContent(), "\xEF\xBB\xBF"))));
        $header = str_getcsv(array_shift($lines), ';', '"', '');

        return array_map(static fn (string $line) => array_combine($header, str_getcsv($line, ';', '"', '')), $lines);
    }

    public function testTheExportCarriesEveryInformation(): void
    {
        $this->create('camille@example.test', philibert: true, validated: true);

        $rows = $this->exportRows();

        self::assertCount(1, $rows);
        self::assertSame('Durand', $rows[0]['nom']);
        self::assertSame('Camille', $rows[0]['prenom']);
        self::assertSame('camille@example.test', $rows[0]['email']);
        self::assertSame('Joigny', $rows[0]['ville']);
        self::assertSame('Validée', $rows[0]['etat']);
        self::assertSame('Chèque', $rows[0]['moyen_reglement']);
        self::assertSame('oui', $rows[0]['philibert']);
        self::assertSame('10', $rows[0]['montant_du']);
        self::assertMatchesRegularExpression('/^EDJ-\d+$/', $rows[0]['numero']);
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    /**
     * Les memes filtres que la liste, lus dans la meme URL : l'export est ce
     * qu'on voit. Par defaut, la saison en cours.
     */
    public function testTheExportFollowsTheFiltersAndDefaultsToTheCurrentSeason(): void
    {
        $this->create('courant@example.test', validated: true);
        $this->create('ancien@example.test', validated: true, submittedAt: '-3 years', first: 'Ancien');

        self::assertCount(1, $this->exportRows(), 'Sans filtre de saison : la saison en cours.');
        self::assertCount(2, $this->exportRows('?saison=toutes'));
    }

    public function testTheExportKeepsOnlyWhatTheFiltersKeep(): void
    {
        $this->create('cheque@example.test', validated: true);
        $this->create('attente@example.test', first: 'Attente');

        $rows = $this->exportRows('?etat=en_attente');

        self::assertCount(1, $rows);
        self::assertSame('attente@example.test', $rows[0]['email']);
    }

    /**
     * Une cellule qui commence par = + - @ est lue comme une formule par un
     * tableur : le fichier sort d'ici pour etre ouvert ailleurs.
     */
    public function testTheExportNeutralizesSpreadsheetFormulas(): void
    {
        $application = $this->create('camille@example.test', validated: true, first: '=HYPERLINK("http://mal.example")');

        $rows = $this->exportRows();

        self::assertStringStartsWith("'", $rows[0]['prenom']);
    }

    public function testAContributorCanExport(): void
    {
        $this->logIn('contributeur@example.test');

        $this->client->request('GET', '/admin/adhesions/export.csv');

        self::assertResponseIsSuccessful();
    }

    // ------------------------------------------------------------------
    // Consommateur -> adhesion annuelle
    // ------------------------------------------------------------------

    private function consumer(bool $validated = true, ?string $submittedAt = null): MembershipApplication
    {
        return $this->create('conso-'.bin2hex(random_bytes(4)).'@example.test', validated: $validated, submittedAt: $submittedAt, type: MembershipType::Consumer);
    }

    /**
     * @param array<string, string> $values
     */
    private function submitUpgrade(MembershipApplication $application, array $values): void
    {
        $crawler = $this->client->request('GET', sprintf('/admin/adhesions/%d/passer-annuel', $application->getId()));
        $form = $crawler->selectButton("Passer à l'annuelle")->form();
        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }
        $this->client->submit($form);
    }

    public function testTheUpgradeButtonIsOfferedToACurrentConsumer(): void
    {
        $application = $this->consumer();

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$application->getId());

        self::assertCount(1, $crawler->filter('a[href$="/passer-annuel"]'));
    }

    public function testNoUpgradeButtonForAnAnnualMember(): void
    {
        $application = $this->create('annuel@example.test', validated: true);

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$application->getId());

        self::assertCount(0, $crawler->filter('a[href$="/passer-annuel"]'));
    }

    /**
     * L'adhesion consommateur, deja validee, etait gratuite. L'annuelle ne l'est pas :
     * sans encaissement, elle repasse en attente — elle ne reste pas « validee »
     * pour 0 € quand elle en vaut 10.
     */
    public function testUpgradingWithoutPaymentPutsTheBulletinBackOnHold(): void
    {
        $application = $this->consumer();

        $this->submitUpgrade($application, ['upgrade_membership[membershipType]' => 'individuelle']);

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
        $saved = $this->reload($application);
        self::assertSame(MembershipType::Individual, $saved->getMembershipType());
        self::assertTrue($saved->isPending());
        self::assertNull($saved->getPaymentMethod());
    }

    public function testUpgradingWithPaymentValidatesAtOnce(): void
    {
        $application = $this->consumer();

        $this->submitUpgrade($application, [
            'upgrade_membership[membershipType]' => 'individuelle',
            'upgrade_membership[paymentMethod]' => 'especes',
        ]);

        $saved = $this->reload($application);
        self::assertTrue($saved->isValidated());
        self::assertSame(PaymentMethod::Cash, $saved->getPaymentMethod());
        self::assertSame(10, $saved->amountDue());
    }

    /**
     * Meme saison, meme personne, meme numero : un bulletin, pas deux.
     */
    public function testUpgradingKeepsTheSameBulletinAndNumber(): void
    {
        $application = $this->consumer();
        $number = $application->getMemberNumber();

        $this->submitUpgrade($application, ['upgrade_membership[membershipType]' => 'individuelle']);

        $this->entityManager->clear();
        $all = $this->entityManager->getRepository(MembershipApplication::class)->findAll();
        self::assertCount(1, $all);
        self::assertSame($number, $all[0]->getMemberNumber());
    }

    public function testTheHistoryKeepsTheFormulaChange(): void
    {
        $application = $this->consumer();

        $this->submitUpgrade($application, ['upgrade_membership[membershipType]' => 'individuelle']);

        $types = array_map(static fn ($e) => $e->getType()->value, $this->reload($application)->getHistory()->toArray());
        self::assertContains('formule', $types);
        $event = array_values(array_filter($this->reload($application)->getHistory()->toArray(), static fn ($e) => 'formule' === $e->getType()->value))[0];
        self::assertSame('consommateur → individuelle', $event->getDetail());
    }

    public function testTheFamilyFormulaNeedsAnotherPrincipal(): void
    {
        $application = $this->consumer();

        $this->submitUpgrade($application, ['upgrade_membership[membershipType]' => 'famille']);
        self::assertSelectorTextContains('main', "Indiquez le prénom et le nom de l'adhérent principal");

        $this->submitUpgrade($application, [
            'upgrade_membership[membershipType]' => 'famille',
            'upgrade_membership[principalMemberName]' => 'Camille Durand',
        ]);
        self::assertSelectorTextContains('main', 'ce ne peut pas être vous');

        self::assertSame(MembershipType::Consumer, $this->reload($application)->getMembershipType());
    }

    public function testTheFamilyFormulaWithAPrincipalIsAccepted(): void
    {
        $application = $this->consumer();

        $this->submitUpgrade($application, [
            'upgrade_membership[membershipType]' => 'famille',
            'upgrade_membership[principalMemberName]' => 'Louis Durand',
            'upgrade_membership[paymentMethod]' => 'cheque',
        ]);

        $saved = $this->reload($application);
        self::assertSame(MembershipType::Family, $saved->getMembershipType());
        self::assertSame('Louis Durand', $saved->getPrincipalMemberName());
        self::assertSame(5, $saved->amountDue());
    }

    public function testAnAnnualMemberCannotBeUpgraded(): void
    {
        $application = $this->create('annuel@example.test', validated: true);

        $this->client->request('GET', sprintf('/admin/adhesions/%d/passer-annuel', $application->getId()));

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
    }

    /**
     * Une adhesion d'une saison passee ne se transforme pas : elle se renouvelle,
     * ce qui ouvre un bulletin pour la saison en cours.
     */
    public function testAPastSeasonConsumerCannotBeUpgraded(): void
    {
        $application = $this->consumer(submittedAt: '-3 years');

        $this->client->request('GET', sprintf('/admin/adhesions/%d/passer-annuel', $application->getId()));

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
    }

    public function testACancelledConsumerCannotBeUpgraded(): void
    {
        $application = $this->consumer();
        $application->cancel(null);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/admin/adhesions/%d/passer-annuel', $application->getId()));

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
    }

    public function testEveryBulletinCarriesAMemberNumber(): void
    {
        $application = $this->create('camille@example.test');

        $this->client->request('GET', '/admin/adhesions/'.$application->getId());

        self::assertMatchesRegularExpression('/^EDJ-\\d{4,}$/', (string) $application->getMemberNumber());
        self::assertSelectorTextContains('body', (string) $application->getMemberNumber());
    }

    /**
     * Deux bulletins de la meme personne — une readhesion la saison suivante —
     * partagent le numero : c'est tout l'objet de la table des adherents.
     */
    public function testAReapplicationKeepsTheSameNumber(): void
    {
        $first = $this->create('camille@example.test');
        $second = $this->create('camille.autre@example.test');

        self::assertSame($first->getMemberNumber(), $second->getMemberNumber());
    }

    public function testValidationRecordsThePaymentMethod(): void
    {
        $application = $this->create('camille@example.test');

        $this->submitStatus($application, "Valider l'adhésion", ['paymentMethod' => 'cheque']);

        self::assertResponseRedirects('/admin/adhesions/'.$application->getId());
        $saved = $this->reload($application);
        self::assertTrue($saved->isValidated());
        self::assertSame(PaymentMethod::Cheque, $saved->getPaymentMethod());
    }

    /**
     * Le formulaire impose le moyen de reglement, mais il se fabrique a la
     * main : le refus doit venir du serveur.
     */
    public function testValidationIsRefusedWithoutAPaymentMethod(): void
    {
        $application = $this->create('camille@example.test');

        $this->submitStatus($application, "Valider l'adhésion", remove: ['paymentMethod']);

        self::assertTrue($this->reload($application)->isPending());
    }

    /**
     * Qui a decide quoi, et quand : l'historique se lit sur la fiche, y
     * compris apres l'anonymisation — il ne porte aucune donnee de l'adherent.
     */
    public function testTheHistoryRecordsEveryDecision(): void
    {
        $application = $this->create('camille@example.test');

        $this->submitStatus($application, "Valider l'adhésion", ['paymentMethod' => 'cheque']);
        $this->submitStatus($application, "Annuler l'adhésion");

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$application->getId());
        $text = $crawler->filter('#t-historique')->ancestors()->first()->text();

        self::assertStringContainsString('Adhésion validée', $text);
        self::assertStringContainsString('Adhésion annulée', $text);
    }

    public function testAnonymizationIsAddedToTheHistory(): void
    {
        $application = $this->create('camille@example.test');
        $crawler = $this->client->request('GET', '/admin/adhesions/'.$application->getId());
        $this->client->submit($crawler->selectButton('Anonymiser')->form());

        $crawler = $this->client->request('GET', '/admin/adhesions/'.$application->getId());

        self::assertStringContainsString('Bulletin anonymisé', $crawler->filter('#t-historique')->ancestors()->first()->text());
    }

    public function testCancellationLeavesTheBulletinReadable(): void
    {
        $application = $this->create('camille@example.test');

        $this->submitStatus($application, "Annuler l'adhésion");

        $saved = $this->reload($application);
        self::assertTrue($saved->isCancelled());
        self::assertFalse($saved->isAnonymized());
    }

    public function testAStatusChangeWithoutAValidTokenIsRefused(): void
    {
        $application = $this->create('camille@example.test');

        $this->client->request('POST', sprintf('/admin/adhesions/%d/annuler', $application->getId()), ['_token' => 'faux']);

        self::assertTrue($this->reload($application)->isPending());
    }

    /**
     * Relit le bulletin apres une requete. Le client redemarre le noyau entre
     * deux requetes : l'entite de depart n'est plus geree, et le gestionnaire
     * d'entites de setUp() non plus.
     */
    private function reload(MembershipApplication $application): MembershipApplication
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        // Detache tout : apres une requete refusee, l'entite que la requete a
        // modifiee en memoire est encore dans l'identite du gestionnaire — la
        // relire sans vider lirait ce que la page a tente, pas ce qui est en base.
        $manager->clear();
        $saved = $manager->find(MembershipApplication::class, $application->getId());
        self::assertInstanceOf(MembershipApplication::class, $saved);

        return $saved;
    }

    /**
     * Passe par la fiche plutot que par un POST fabrique : le jeton CSRF se
     * recupere ainsi comme un utilisateur le fait, sans monter de session a la
     * main.
     *
     * @param array<string, string> $values
     * @param list<string>          $remove
     */
    private function submitStatus(MembershipApplication $application, string $button, array $values = [], array $remove = []): void
    {
        $crawler = $this->client->request('GET', '/admin/adhesions/'.$application->getId());
        $form = $crawler->selectButton($button)->form();

        foreach ($remove as $field) {
            $form->remove($field);
        }

        $this->client->submit($form, $values);
    }

    public function testAContributorCanRegisterAMember(): void
    {
        $this->logIn('contributeur@example.test');

        $this->client->request('GET', '/admin/adhesions/nouveau');

        self::assertResponseIsSuccessful();
    }
}
