<?php

namespace App\Tests\Controller;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mime\Email;

/**
 * Mot de passe oublie de l'administration, de bout en bout : la demande, le
 * courriel, le lien, le nouveau mot de passe, et ce qui ne doit jamais se voir.
 *
 * Ecrit en base de test. Les courriels partent sur le transport asynchrone, qui
 * est en memoire dans cet environnement : on lit ce qui y a ete poste.
 */
final class ResetPasswordTest extends WebTestCase
{
    private const EMAIL = 'oubli@reset.example.test';
    private const STRONG = 'une phrase de passe vraiment longue 2026';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    /**
     * Courriels recueillis apres chaque requete : le client redemarre le noyau
     * entre deux requetes, et le transport en memoire de la precedente est
     * perdu avec lui.
     *
     * @var list<Email>
     */
    private array $emails = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testTheLoginPageOffersTheLink(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSame('/mot-de-passe-oublie', $crawler->selectLink('Mot de passe oublié ?')->attr('href'));
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNoEmail(): void
    {
        $this->request('inconnu@reset.example.test');

        self::assertResponseRedirects('/mot-de-passe-oublie/envoye');
        self::assertCount(0, $this->sentEmails());
    }

    public function testAKnownAddressReceivesAMessageWithALink(): void
    {
        $this->createUser();

        $this->request(self::EMAIL);

        self::assertResponseRedirects('/mot-de-passe-oublie/envoye');
        $emails = $this->sentEmails();
        self::assertCount(1, $emails);
        self::assertSame(self::EMAIL, $emails[0]->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#/mot-de-passe-oublie/reinitialiser/[A-Za-z0-9]+#', (string) $emails[0]->getTextBody());
        self::assertStringContainsString('30 minutes', (string) $emails[0]->getTextBody());
    }

    public function testTheAddressIsMatchedWhateverItsCase(): void
    {
        $this->createUser();

        $this->request(mb_strtoupper(self::EMAIL));

        self::assertCount(1, $this->sentEmails());
    }

    public function testABlockedAccountReceivesNothingAndSaysNothing(): void
    {
        $this->createUser(blocked: true);

        $this->request(self::EMAIL);

        self::assertResponseRedirects('/mot-de-passe-oublie/envoye');
        self::assertCount(0, $this->sentEmails());
    }

    /**
     * Une seconde demande, tout de suite apres, n'envoie rien de plus — et la
     * reponse ne le dit pas : sinon elle trahirait que le compte existe.
     */
    public function testARepeatedRequestSendsNothingMoreAndAnswersTheSame(): void
    {
        $this->createUser();

        $this->request(self::EMAIL);
        $this->request(self::EMAIL);

        self::assertResponseRedirects('/mot-de-passe-oublie/envoye');
        self::assertCount(1, $this->sentEmails());
    }

    public function testTheLinkChangesThePasswordAndOnlyOnce(): void
    {
        $user = $this->createUser();
        $this->request(self::EMAIL);
        $link = $this->linkFromEmail();

        // Le jeton quitte l'adresse : redirection vers une page sans jeton.
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/mot-de-passe-oublie/reinitialiser');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Enregistrer le mot de passe')->form([
            'change_password[newPassword][first]' => self::STRONG,
            'change_password[newPassword][second]' => self::STRONG,
        ]));
        self::assertResponseRedirects('/login');

        $this->entityManager->clear();
        $saved = $this->entityManager->find(User::class, $user->getId());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($saved, self::STRONG), 'Le nouveau mot de passe est en place.');
        self::assertFalse($saved->mustChangePassword());
        self::assertSame(0, $this->entityManager->getRepository(ResetPasswordRequest::class)->count(['user' => $saved]));

        // Le meme lien ne sert plus.
        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    public function testAWeakPasswordIsRefusedAndTheLinkStaysUsable(): void
    {
        $user = $this->createUser();
        $this->request(self::EMAIL);
        $this->client->request('GET', $this->linkFromEmail());
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Enregistrer le mot de passe')->form([
            'change_password[newPassword][first]' => 'court',
            'change_password[newPassword][second]' => 'court',
        ]));

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        self::assertSame(1, $this->entityManager->getRepository(ResetPasswordRequest::class)->count(['user' => $user->getId()]));
    }

    public function testAnInvalidLinkIsSentBackToTheRequestPage(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie/reinitialiser/'.str_repeat('a', 40));
        $this->client->followRedirect();

        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    public function testBlockingTheAccountAfterTheLinkWasSentRefusesIt(): void
    {
        $user = $this->createUser();
        $this->request(self::EMAIL);
        $link = $this->linkFromEmail();

        // La requete a remis le gestionnaire a zero : on relit le compte avant de le bloquer.
        $this->entityManager->clear();
        $this->entityManager->find(User::class, $user->getId())->block();
        $this->entityManager->flush();

        $this->client->request('GET', $link);
        $this->client->followRedirect();

        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    public function testTheResetPageNeedsALink(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie/reinitialiser');

        self::assertResponseStatusCodeSame(404);
    }

    private function request(string $email): void
    {
        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Recevoir le lien')->form(['reset_password_request[email]' => $email]));
        $this->collectEmails();
    }

    /**
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return $this->emails;
    }

    private function collectEmails(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendEmailMessage && $message->getMessage() instanceof Email) {
                $this->emails[] = $message->getMessage();
            }
        }
    }

    private function linkFromEmail(): string
    {
        $emails = $this->sentEmails();
        self::assertNotEmpty($emails, 'Un courriel devrait avoir ete envoye.');
        self::assertSame(1, preg_match('#https?://[^/\s]+(/mot-de-passe-oublie/reinitialiser/[A-Za-z0-9]+)#', (string) $emails[0]->getTextBody(), $found));

        return $found[1];
    }

    private function createUser(bool $blocked = false): User
    {
        $user = (new User())->setEmail(self::EMAIL)->setRoles(['ROLE_CONTRIBUTOR'])->setPassword('ancien-hash');
        $user->setMustChangePassword(true);
        if ($blocked) {
            $user->block();
        }
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function purge(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :e')->setParameter('e', self::EMAIL)->execute();
        $this->entityManager->clear();
    }
}
