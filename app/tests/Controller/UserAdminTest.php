<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    public function testListShowsTheAccounts(): void
    {
        $this->create('a@example.test');

        $this->client->request('GET', '/admin/utilisateurs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'a@example.test');
    }

    public function testCreatingAnAccountShowsTheTemporaryPasswordOnlyOnce(): void
    {
        $crawler = $this->client->request('GET', '/admin/utilisateurs/nouveau');
        $form = $crawler->selectButton('Créer le compte')->form();
        $form['user_creation[email]'] = 'Nouveau@Example.test';
        $form['user_creation[role]']->select('ROLE_CONTRIBUTOR');
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/utilisateurs');
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"] code', 'Le mot de passe provisoire est affiché.');
        $password = $crawler->filter('[role="alert"] code')->text();
        self::assertMatchesRegularExpression('/^[A-Za-z2-9]{4}(-[A-Za-z2-9]{4}){3}$/', $password);

        $stored = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.test']);
        self::assertNotNull($stored, 'L\'adresse est normalisée en minuscules.');
        self::assertTrue($stored->mustChangePassword());
        self::assertNotSame($password, $stored->getPassword(), 'Seul un condensé est conservé.');
        self::assertStringNotContainsString($password, (string) $stored->getPassword());

        $crawler = $this->client->request('GET', '/admin/utilisateurs');
        self::assertSelectorNotExists('[role="alert"] code', 'Le mot de passe ne se relit pas.');
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

    public function testResettingThePasswordGeneratesANewTemporaryOne(): void
    {
        $user = $this->create('a@example.test');
        $id = $user->getId();

        $this->client->request('POST', sprintf('/admin/utilisateurs/%d/mot-de-passe', $id), ['_token' => $this->token("/$id/mot-de-passe")]);
        self::assertResponseRedirects('/admin/utilisateurs');
        $crawler = $this->client->followRedirect();

        $reloaded = $this->reload($user);
        self::assertTrue($reloaded->mustChangePassword());
        self::assertNotSame('x', $reloaded->getPassword());
        self::assertSelectorExists('[role="alert"] code');
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
