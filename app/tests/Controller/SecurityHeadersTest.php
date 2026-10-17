<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * En-tetes de securite, en mode non debug : la politique de contenu n'est posee
 * qu'hors debug (la barre du profileur injecte ses propres scripts).
 */
final class SecurityHeadersTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'bulletin' => ['/adhesion/bulletin'];
        yield 'connexion' => ['/login'];
        yield 'mentions' => ['/mentions-legales'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testEveryPageCarriesTheSecurityHeaders(string $path): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', $path, [], [], ['HTTPS' => 'on']);
        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
        self::assertSame('DENY', $headers->get('X-Frame-Options'), $path);
        self::assertNotNull($headers->get('Referrer-Policy'), $path);
        self::assertStringContainsString('max-age=', (string) $headers->get('Strict-Transport-Security'), $path);
    }

    public function testContentSecurityPolicyIsStrictAndUsesTheNonceOfThePage(): void
    {
        $client = static::createClient(['debug' => false]);
        $crawler = $client->request('GET', '/');
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("script-src 'self';", $csp, 'Aucun script en ligne ni eval.');
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $csp);
        self::assertStringNotContainsString("'unsafe-eval'", $csp);

        $nonce = $crawler->filter('meta[name="csp-nonce"]')->attr('content');
        self::assertNotEmpty($nonce);
        self::assertStringContainsString("'nonce-".$nonce."'", $csp, 'Le nonce de l\'en-tête est celui de la page.');
    }

    public function testNoExternalResourceIsLoadedByThePublicPages(): void
    {
        $client = static::createClient(['debug' => false]);
        $crawler = $client->request('GET', '/');

        // Feuilles de style, scripts et images : ce que le navigateur charge. Les
        // liens (canonical, og:image) sont des adresses, pas des chargements.
        foreach ($crawler->filter('link[rel="stylesheet"], script[src], img[src]') as $node) {
            $url = $node->getAttribute('href') ?: $node->getAttribute('src');
            self::assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, 'Ressource externe : '.$url);
        }
    }

    public function testAdministrationIsNeverCached(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/login');

        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
