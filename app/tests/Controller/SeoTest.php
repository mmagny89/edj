<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Balises de referencement, donnees structurees, robots.txt et sitemap.xml.
 * L'environnement de test n'est pas « prod » : le site s'y declare non
 * indexable, ce que les cas ci-dessous verifient aussi.
 */
final class SeoTest extends WebTestCase
{
    public function testHomePageCarriesTheMetaTags(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($crawler->filter('meta[name="description"]')->attr('content'));
        self::assertLessThanOrEqual(160, mb_strlen((string) $crawler->filter('meta[name="description"]')->attr('content')));
        self::assertSame('http://localhost/', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/img/og-image.png', $crawler->filter('meta[property="og:image"]')->attr('content'));
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        self::assertSame(1, $crawler->filter('h1')->count(), 'Un seul h1 sur l\'accueil.');
    }

    public function testStructuredDataIsValidJsonDescribingTheAssociation(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $data = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('https://schema.org', $data['@context']);
        self::assertSame('Organization', $data['@graph'][0]['@type']);
        self::assertSame('Envie de Jouer', $data['@graph'][0]['name']);
    }

    public function testNonProductionEnvironmentsAreNotIndexable(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        $client->request('GET', '/robots.txt');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("Disallow: /\n", (string) $client->getResponse()->getContent());
    }

    public function testSitemapListsThePublicPagesOnly(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/xml', (string) $client->getResponse()->headers->get('Content-Type'));
        $xml = simplexml_load_string((string) $client->getResponse()->getContent());
        $all = [];
        foreach ($xml->url as $url) {
            $all[] = (string) $url->loc;
        }

        self::assertContains('http://localhost/', $all);
        self::assertContains('http://localhost/adhesion/bulletin', $all);
        foreach ($all as $url) {
            self::assertStringNotContainsString('/admin', $url);
            self::assertStringNotContainsString('/login', $url);
            self::assertStringNotContainsString('/merci', $url);
        }
    }

    public function testThankYouPageIsNeverIndexed(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/adhesion/bulletin/merci');

        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testEveryPublicPageHasAUniqueTitleAndAnH1(): void
    {
        $client = static::createClient();
        $titles = [];

        foreach (['/', '/adhesion/bulletin', '/mentions-legales', '/donnees-personnelles'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertGreaterThanOrEqual(1, $crawler->filter('h1')->count(), $path);
            $titles[] = $crawler->filter('title')->text();
        }

        self::assertSame($titles, array_values(array_unique($titles)), 'Chaque page a son propre titre.');
    }

    public function testUnknownPageRendersTheFriendly404(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);
        $client->request('GET', '/une-page-qui-nexiste-pas');

        self::assertResponseStatusCodeSame(404);
    }
}
