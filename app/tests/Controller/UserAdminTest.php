<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * Gestion des comptes d'administration. Ecrit en base de test.
 *
 * Le connecte est « admin@example.test », fournisseur en memoire : il n'existe
 * pas dans la table, ce qui permet de verifier que l'on peut agir sur tous les
 * comptes de la table sans qu'aucun ne soit « soi-meme ».
 */
final class UserAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
        $this->logIn('admin@example.test');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
        parent::tearDown();
    }

    private function logIn(string $identifier): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier($identifier));
    }

    private function create(string $email, string $role = User::ROLE_CONTRIBUTOR): User
    {
        $user = (new User())->setEmail($email)->setRoles([$role])->setPassword('x');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        $this->entityManager->clear();

        return $this->entityManager->find(User::class, $user->getId());
    }

    private function token(string $id): string
    {
        $crawler = $this->client->request('GET', '/admin/utilisateurs');

        return (string) $crawler->filter(sprintf('form[action$="%s"] input[name="_token"]', $id))->attr('value');
    }

    public function testCreatingAnAccountEmailsAPasswordLinkAndNoPassword(): void
    {
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');
        $form = $crawler->selectButton('Créer le compte')->form();
        $form['user_creation[email]'] = 'Nouveau@Example.test';
        $form['user_creation[role]']->select('ROLE_CONTRIBUTOR');
        $this->client->submit($form);

        // Le client redemarre le noyau entre deux requetes : on lit le transport
        // en memoire avant de suivre la redirection.
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $mails = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendEmailMessage && $message->getMessage() instanceof Email) {
                $mails[] = $message->getMessage();
            }
        }

        self::assertResponseRedirects('/admin/utilisateurs');
        $this->client->followRedirect();
        self::assertSelectorNotExists('[role="alert"] code', 'Aucun mot de passe n\'est affiché.');
        self::assertSelectorTextContains('[role="status"], .flash-success, main', 'nouveau@example.test');

        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.test']);
        self::assertNotNull($stored, 'L\'adresse est normalisée en minuscules.');

        self::assertCount(1, $mails);
        self::assertSame('nouveau@example.test', $mails[0]->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#/mot-de-passe-oublie/reinitialiser/[A-Za-z0-9]+#', (string) $mails[0]->getTextBody());
        self::assertMatchesRegularExpression('#/mot-de-passe-oublie/reinitialiser/[A-Za-z0-9]+#', (string) $mails[0]->getHtmlBody());
        self::assertStringNotContainsStringIgnoringCase('mot de passe provisoire', (string) $mails[0]->getTextBody());
    }

    public function testListShowsTheAccounts(): void
    {
        $this->create('a@example.test');

        $this->client->request('GET', '/admin/utilisateurs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'a@example.test');
    }

    public function testCreatingAnExistingAddressIsRefused(): void
    {
        $this->create('deja@example.test');

        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');
        $form = $crawler->selectButton('Créer le compte')->form();
        $form['user_creation[email]'] = 'deja@example.test';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un compte existe déjà');
    }

    public function testBlockingAndUnblocking(): void
    {
        $user = $this->create('a@example.test');
        $id = $user->getId();

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/bloquer', $id), ['_token' => $this->token("/$id/bloquer")]);
        self::assertTrue($this->reload($user)->isBlocked());

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/debloquer', $id), ['_token' => $this->token("/$id/debloquer")]);
        self::assertFalse($this->reload($user)->isBlocked());
    }

    public function testActionsWithAnInvalidTokenAreRefused(): void
    {
        $user = $this->create('a@example.test');

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/bloquer', $user->getId()), ['_token' => 'faux']);

        self::assertFalse($this->reload($user)->isBlocked());
    }

    public function testTheLastActiveSuperAdminCannotBeBlockedNorDemoted(): void
    {
        $only = $this->create('seul@example.test', User::ROLE_SUPER_ADMIN);
        $id = $only->getId();

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/bloquer', $id), ['_token' => $this->token("/$id/bloquer")]);
        self::assertFalse($this->reload($only)->isBlocked());

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/role', $id), ['_token' => $this->token("/$id/role"), 'role' => User::ROLE_CONTRIBUTOR]);
        self::assertTrue($this->reload($only)->isSuperAdmin());
    }

    public function testASuperAdminCanBeBlockedWhenAnotherRemains(): void
    {
        $this->create('un@example.test', User::ROLE_SUPER_ADMIN);
        $two = $this->create('deux@example.test', User::ROLE_SUPER_ADMIN);
        $id = $two->getId();

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/bloquer', $id), ['_token' => $this->token("/$id/bloquer")]);

        self::assertTrue($this->reload($two)->isBlocked());
    }

    public function testChangingTheRole(): void
    {
        $user = $this->create('a@example.test');
        $id = $user->getId();

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/role', $id), ['_token' => $this->token("/$id/role"), 'role' => User::ROLE_SUPER_ADMIN]);

        self::assertTrue($this->reload($user)->isSuperAdmin());
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        $user = $this->create('a@example.test');
        $id = $user->getId();

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/role', $id), ['_token' => $this->token("/$id/role"), 'role' => 'ROLE_ROOT']);

        self::assertSame([User::ROLE_CONTRIBUTOR, 'ROLE_USER'], array_values($this->reload($user)->getRoles()));
    }

    public function testResettingThePasswordEmailsALinkAndCutsTheOldPassword(): void
    {
        $user = $this->create('a@example.test');
        $id = $user->getId();
        $before = $user->getPassword();

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/mot-de-passe', $id), ['_token' => $this->token("/$id/mot-de-passe")]);
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $mails = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendEmailMessage && $message->getMessage() instanceof Email) {
                $mails[] = $message->getMessage();
            }
        }
        self::assertResponseRedirects('/admin/utilisateurs');
        $this->client->followRedirect();

        self::assertNotSame($before, $this->reload($user)->getPassword(), 'L\'ancien mot de passe est coupé.');
        self::assertSelectorNotExists('[role="alert"] code');
        self::assertCount(1, $mails);
        self::assertSame('a@example.test', $mails[0]->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#/mot-de-passe-oublie/reinitialiser/[A-Za-z0-9]+#', (string) $mails[0]->getTextBody());
    }

    public function testDeletingAContributorButNeverASuperAdmin(): void
    {
        $contributor = $this->create('contrib@example.test');
        $admin = $this->create('autre-admin@example.test', User::ROLE_SUPER_ADMIN);
        $cid = $contributor->getId();
        $aid = $admin->getId();

        // Pas de bouton pour un super-administrateur : la requete forgee est refusee, jeton ou non.
        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/supprimer', $aid), ['_token' => 'x']);
        self::assertResponseRedirects('/admin/utilisateurs');
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(User::class, $aid), 'Un super-administrateur ne se supprime pas.');

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/supprimer', $cid), ['_token' => $this->token("/$cid/supprimer")]);
        self::assertResponseRedirects('/admin/utilisateurs');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(User::class, $cid));
    }

    public function testAContributorCannotReachTheAdministratorPages(): void
    {
        $this->logIn('contributeur@example.test');

        foreach (['/admin/utilisateurs', '/admin/utilisateurs/nouveau'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path);
        }

        $user = $this->create('a@example.test');
        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/bloquer', $user->getId()), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($user)->isBlocked());
    }
}
