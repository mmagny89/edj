<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Controle d'acces de l'administration. Aucun de ces cas n'ecrit en base : le
 * fournisseur d'utilisateurs est en memoire dans l'environnement de test, et
 * les pages interrogees refusent l'acces avant d'atteindre un depot.
 */
final class AdminAccessTest extends WebTestCase
{
    /**
     * L'utilisateur vient du fournisseur et non d'un « new InMemoryUser » : le
     * fournisseur fabrique un mot de passe interne aux comptes declares sans
     * mot de passe, et un utilisateur construit a la main ne lui serait plus
     * egal au rafraichissement de la session — il serait deconnecte.
     */
    private function logIn(KernelBrowser $client, string $identifier): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $client->loginUser($provider->loadUserByIdentifier($identifier));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privatePaths(): iterable
    {
        yield 'tableau de bord' => ['/admin'];
        yield 'evenements' => ['/admin/evenements'];
        yield 'nouvel evenement' => ['/admin/evenements/nouveau'];
        yield 'generation' => ['/admin/evenements/generer'];
        yield 'adhesions' => ['/admin/adhesions'];
    }

    #[DataProvider('privatePaths')]
    public function testAnonymousVisitorIsSentToTheLoginPage(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * Le suivi des adhesions est le travail courant du bureau : un
     * contributeur y accede. Seuls les comptes d'administration restent au
     * super-administrateur.
     */
    public function testMembershipApplicationsAreOpenToAContributor(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'contributeur@example.test');
        $client->request('GET', '/admin/adhesions');

        self::assertResponseIsSuccessful();
    }

    public function testUserAdministrationIsForbiddenToAContributor(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'contributeur@example.test');
        $client->request('GET', '/admin/utilisateurs');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletionRefusesAGetRequest(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.test');
        $client->request('GET', '/admin/evenements/1/supprimer');

        self::assertResponseStatusCodeSame(405);
    }

    public function testLoginPageDoesNotExposeTheAdministrationMenu(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('nav')->count());
        self::assertStringNotContainsString('/admin', (string) $client->getResponse()->getContent());
    }

    public function testPrivatePagesAreNeitherCachedNorIndexed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $headers = $client->getResponse()->headers;

        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
    }

    public function testLogoutRefusesARequestWithoutToken(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.test');
        $client->request('GET', '/logout');

        // Sans jeton valide, la deconnexion est refusee : une image ou un lien
        // piege ne ferme plus la session d'un administrateur.
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Le sixieme essai echoue en erreur de limitation, pas en « identifiants
     * invalides » : le compteur ne depend ni de l'existence du compte ni du
     * mot de passe. Adresse IP et identifiant propres a chaque execution, car
     * les compteurs survivent entre deux lancements.
     */
    public function testRepeatedFailedLoginsAreThrottled(): void
    {
        $client = static::createClient();
        $server = [
            'REMOTE_ADDR' => '203.0.113.'.random_int(1, 254),
            'HTTP_ORIGIN' => 'http://localhost',
        ];
        $username = 'inconnu-'.bin2hex(random_bytes(4)).'@example.test';
        $last = '';

        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $client->request('POST', '/login', [
                '_username' => $username,
                '_password' => 'mauvais',
                '_csrf_token' => 'csrf-token',
            ], [], $server);
            $client->followRedirect();
            $last = (string) $client->getResponse()->getContent();
        }

        self::assertStringContainsString('Plusieurs tentatives de connexion ont échoué', $last);
    }
}
