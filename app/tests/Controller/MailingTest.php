<?php

namespace App\Tests\Controller;

use App\Entity\MailCampaign;
use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * Ecrire aux adherents d'une saison, de bout en bout : l'apercu, l'essai,
 * l'envoi, et ce qui doit l'empecher de partir.
 *
 * La saison 2031 n'appartient a aucun autre test. Les courriels partent sur le
 * transport asynchrone, en memoire dans cet environnement.
 */
final class MailingTest extends WebTestCase
{
    private const SEASON = 2031;
    private const MAIL_SUFFIX = '@courriels.example.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<Email> */
    private array $emails = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnonymousIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin/courriels');

        self::assertResponseRedirects();
    }

    public function testThePagesDisplay(): void
    {
        $this->logIn();
        $this->client->request('GET', '/admin/courriels');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Courriels aux adhérents');

        $this->client->request('GET', '/admin/courriels/nouveau');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '{{prenom}}');
        // L'apercu et l'essai repondent par un 200 a un POST, que Turbo n'affiche pas :
        // sans cet attribut, le formulaire semble ne rien faire (aucun test PHP ne le voit).
        self::assertSelectorExists('form[name="mailing"][data-turbo="false"]');
    }

    public function testAnUnknownVariableIsRefusedBeforeAnythingIsSent(): void
    {
        $this->logIn();
        $this->bulletin('ada');

        $this->submit('preview', ['subject' => 'Bonjour {{prenon}}', 'body' => 'Texte']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Variable inconnue');
        self::assertCount(0, $this->emails);
    }

    public function testAMessageNeedsAnAudience(): void
    {
        $this->logIn();
        // Une saison n'est proposee que si elle a des bulletins.
        $this->bulletin('ada');

        $this->submit('preview', ['includeAnnual' => false, 'includeConsumers' => false]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choisissez à qui écrire');
    }

    public function testThePreviewCountsWithoutSendingAnything(): void
    {
        $this->logIn();
        $this->bulletin('ada');
        $this->bulletin('bob');
        $this->bulletin('sans-adresse')->setEmail(null);
        $this->bulletin('desinscrit')->getMember()->optOutOfMailings();
        $this->em->flush();

        $crawler = $this->submit('preview');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#titre-apercu', '2 messages');
        self::assertSelectorTextContains('body', 'Sans adresse e-mail');
        self::assertCount(0, $this->emails, 'Un apercu n\'envoie rien.');
        self::assertSame(0, $this->em->getRepository(MailCampaign::class)->count([]));
        self::assertGreaterThan(0, $crawler->selectButton('Envoyer à 2 adresses')->count());
        // Chaque bouton n'apparait qu'une fois : form_end() ne doit pas rendre de doublons.
        self::assertCount(1, $crawler->filter('form[name="mailing"] button[name="mailing[preview]"]'));
        self::assertCount(1, $crawler->filter('form[name="mailing"] button[name="mailing[test]"]'));
        self::assertCount(1, $crawler->filter('form[name="mailing"] button[name="mailing[send]"]'));
    }

    public function testATestGoesToTheAuthorOnlyWithSampleValues(): void
    {
        $this->logIn();
        $this->bulletin('ada');

        $this->submit('test', ['subject' => 'Bonjour {{prenom}}', 'body' => 'Numéro {{numero}}']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->emails);
        self::assertSame('contributeur@example.test', $this->emails[0]->getTo()[0]->getAddress());
        self::assertSame('[ESSAI] Bonjour Camille', $this->emails[0]->getSubject());
        self::assertStringContainsString('Numéro EDJ-0042', (string) $this->emails[0]->getTextBody());
        self::assertSame(0, $this->em->getRepository(MailCampaign::class)->count([]), 'Un essai n\'est pas un envoi.');
    }

    public function testSendingQueuesOneMessagePerAddressAndRecordsTheCampaign(): void
    {
        $this->logIn();
        $ada = $this->bulletin('ada');
        $this->bulletin('bob');
        $this->em->flush();
        $adaNumber = $ada->getMember()->getNumber();

        $crawler = $this->submit('preview', ['subject' => 'Soirée de {{prenom}}', 'body' => "Bonjour {{prenom}} {{nom}}, n° {{numero}}.\n\nÀ mardi !"]);
        $this->sendFrom($crawler, 2);

        self::assertResponseRedirects();
        $this->collectEmails();
        self::assertCount(2, $this->emails);

        $byAddress = [];
        foreach ($this->emails as $email) {
            $byAddress[$email->getTo()[0]->getAddress()] = $email;
        }
        $mail = $byAddress['ada'.self::MAIL_SUFFIX];
        self::assertSame('Soirée de Ada', $mail->getSubject());
        self::assertStringContainsString("Bonjour Ada Test, n° $adaNumber.", (string) $mail->getTextBody());
        self::assertStringContainsString('/adhesion/desinscription/'.$adaNumber, (string) $mail->getTextBody());
        self::assertStringContainsString('<p ', (string) $mail->getHtmlBody());

        $headers = $mail->getHeaders();
        self::assertStringContainsString('/adhesion/desinscription/'.$adaNumber, $headers->get('List-Unsubscribe')->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')->getBodyAsString());

        $this->em->clear();
        $campaigns = $this->em->getRepository(MailCampaign::class)->findAll();
        self::assertCount(1, $campaigns);
        self::assertSame(2, $campaigns[0]->getRecipientCount());
        self::assertSame(self::SEASON, $campaigns[0]->getSeason());
        self::assertSame("Bonjour {{prenom}} {{nom}}, n° {{numero}}.\n\nÀ mardi !", $campaigns[0]->getBody(), 'Le texte garde ses variables.');
    }

    public function testSomeoneWhoUnsubscribedReceivesNothing(): void
    {
        $this->logIn();
        $this->bulletin('ada');
        $this->bulletin('desinscrit')->getMember()->optOutOfMailings();
        $this->em->flush();

        $crawler = $this->submit('preview');
        $this->sendFrom($crawler, 1);
        $this->collectEmails();

        self::assertCount(1, $this->emails);
        self::assertSame('ada'.self::MAIL_SUFFIX, $this->emails[0]->getTo()[0]->getAddress());
    }

    public function testASendWithoutAPreviewIsRefused(): void
    {
        $this->logIn();
        $this->bulletin('ada');

        $this->submit('send', ['expected' => 1]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Relisez l\'aperçu');
        self::assertCount(0, $this->emails);
    }

    /**
     * Un double clic, une page rechargee : le second POST ne part pas.
     */
    public function testTheSameSendCannotBeRepeated(): void
    {
        $this->logIn();
        $this->bulletin('ada');
        $this->em->flush();

        $crawler = $this->submit('preview');
        $form = $crawler->selectButton('Envoyer à 1 adresse')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->collectEmails();

        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        $this->collectEmails();

        self::assertCount(1, $this->emails, 'Un seul message, pas deux.');
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(MailCampaign::class)->count([]));
    }

    public function testAListThatChangedSinceThePreviewIsNotSent(): void
    {
        $this->logIn();
        $this->bulletin('ada');
        $this->em->flush();

        $crawler = $this->submit('preview');
        $form = $crawler->selectButton('Envoyer à 1 adresse')->form();

        // Une adhesion est validee entre l'apercu et l'envoi.
        $this->em->clear();
        $this->bulletin('retardataire');
        $this->em->flush();

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La liste a changé');
        $this->collectEmails();
        self::assertCount(0, $this->emails);
    }

    public function testACampaignPageShowsWhatLeft(): void
    {
        $this->logIn();
        $this->bulletin('ada');
        $this->em->flush();
        $crawler = $this->submit('preview', ['subject' => 'Rentrée', 'body' => 'À bientôt']);
        $this->sendFrom($crawler, 1);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rentrée');
        self::assertSelectorTextContains('body', 'À bientôt');

        $this->client->request('GET', '/admin/courriels');
        self::assertSelectorTextContains('body', 'Rentrée');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function submit(string $button, array $values = []): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/courriels/nouveau');
        // Le formulaire par son nom, sans passer par un bouton : un bouton retrouve
        // par selectButton() partirait avec lui et l'emporterait sur celui que l'on veut.
        $form = $crawler->filter('form[name="mailing"]')->form();

        $defaults = ['subject' => 'Objet', 'body' => 'Bonjour {{prenom}}'];
        foreach ($values + $defaults + ['season' => (string) self::SEASON] as $field => $value) {
            if (\in_array($field, ['includeAnnual', 'includeConsumers'], true)) {
                $value ? $form['mailing['.$field.']']->tick() : $form['mailing['.$field.']']->untick();

                continue;
            }
            $form['mailing['.$field.']'] = (string) $value;
        }
        $form['mailing[season]'] = (string) self::SEASON;
        if (!isset($values['includeAnnual'])) {
            $form['mailing[includeAnnual]']->tick();
        }

        // Le bouton voulu, ajoute aux valeurs du formulaire : c'est sa presence qui
        // dit a Symfony lequel a ete clique. (Le troisieme argument de submit() porte
        // les parametres du serveur, pas des champs.)
        $values = $form->getPhpValues();
        $values['mailing'][$button] = '';
        $crawler = $this->client->request('POST', $form->getUri(), $values);
        $this->collectEmails();

        return $crawler;
    }

    private function sendFrom(Crawler $preview, int $count): void
    {
        $label = sprintf('Envoyer à %d adresse%s', $count, $count > 1 ? 's' : '');
        $this->client->submit($preview->selectButton($label)->form());
    }

    private function collectEmails(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendEmailMessage && $message->getMessage() instanceof Email && !\in_array($message->getMessage(), $this->emails, true)) {
                $this->emails[] = $message->getMessage();
            }
        }
    }

    private function logIn(): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));
    }

    private function bulletin(string $name, MembershipType $type = MembershipType::Individual): MembershipApplication
    {
        static $serial = 0;
        ++$serial;
        $member = new Member('EDJ-8'.str_pad((string) $serial, 3, '0', \STR_PAD_LEFT).random_int(10, 99));
        $this->em->persist($member);

        $application = (new MembershipApplication())
            ->setFirstName(ucfirst($name))->setLastName('Test')
            ->setBirthDate(new \DateTimeImmutable('1985-01-01'))->setPostalCode('89300')->setCity('Joigny')
            ->setEmail($name.self::MAIL_SUFFIX)->setMembershipType($type)
            ->setStatutesAccepted(true)->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')->setSignatureName(ucfirst($name).' Test')
            ->setSubmittedAt(new \DateTimeImmutable('2030-12-01'))
            ->setMember($member);
        $application->validate(PaymentMethod::Cash, null, new \DateTimeImmutable('2030-12-01'));
        $this->em->persist($application);
        $this->em->flush();

        return $application;
    }

    private function purge(): void
    {
        $this->em->createQuery('DELETE FROM '.MailCampaign::class)->execute();
        $this->em->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.signatureName LIKE :s')->setParameter('s', '% Test')->execute();
        $this->em->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
        $this->em->clear();
    }
}
