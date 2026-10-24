<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le menu de l'administration : ses blocs, leur ordre, et qui voit quoi.
 */
final class AdminMenuTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    public function testAContributorSeesTheThreeFirstBlocksInOrder(): void
    {
        $crawler = $this->logInAndOpen('contributeur@example.test', '/admin');

        self::assertSame(['Adhérents', 'Séances et caisse'], $crawler->filter('nav[aria-label="Administration"] p[id^="menu-groupe-"]')->each(fn ($n) => trim($n->text())));
        self::assertSame(
            ['Tableau de bord', 'Adhésions', 'Émargement', 'Courriels', 'Évènements', 'Pointage', 'Comptabilité'],
            $this->labels($crawler),
        );
    }

    /**
     * Le bloc d'administration n'existe pas pour un contributeur : ni son
     * intitule, ni ses liens — pas un lien qui mene a un refus.
     */
    public function testAContributorDoesNotSeeTheAdministrationBlock(): void
    {
        $crawler = $this->logInAndOpen('contributeur@example.test', '/admin');

        self::assertSelectorTextNotContains('nav[aria-label="Administration"]', 'Administrateurs');
        self::assertCount(0, $crawler->filter('nav[aria-label="Administration"] a[href="/admin/utilisateurs"], nav[aria-label="Administration"] a[href="/admin/import"]'));
    }

    public function testASuperAdminAlsoGetsTheAdministrationBlockLast(): void
    {
        $crawler = $this->logInAndOpen('admin@example.test', '/admin');

        self::assertSame(['Adhérents', 'Séances et caisse', 'Administration'], $crawler->filter('nav[aria-label="Administration"] p[id^="menu-groupe-"]')->each(fn ($n) => trim($n->text())));
        self::assertSame(['Saisons', 'Import', 'Administrateurs'], \array_slice($this->labels($crawler), -3));
    }

    public function testEachBlockIsALabelledList(): void
    {
        $crawler = $this->logInAndOpen('admin@example.test', '/admin');

        foreach ($crawler->filter('nav[aria-label="Administration"] p[id^="menu-groupe-"]') as $title) {
            $id = $title->getAttribute('id');
            self::assertCount(1, $crawler->filter(sprintf('nav ul[aria-labelledby="%s"]', $id)), sprintf('Le bloc « %s » doit etre une liste nommee.', trim($title->textContent)));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'pointage' => ['/admin/pointage', 'Pointage'];
        yield 'courriels' => ['/admin/courriels', 'Courriels'];
        yield 'comptabilite' => ['/admin/comptabilite', 'Comptabilité'];
        yield 'adhesions' => ['/admin/adhesions', 'Adhésions'];
        yield 'emargement' => ['/admin/emargement', 'Émargement'];
        yield 'evenements' => ['/admin/evenements', 'Évènements'];
    }

    /**
     * @dataProvider pages
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testTheCurrentPageIsMarkedAndTheOthersAreNot(string $path, string $label): void
    {
        $crawler = $this->logInAndOpen('contributeur@example.test', $path);

        $current = $crawler->filter('nav[aria-label="Administration"] a[aria-current="page"]');
        self::assertCount(1, $current, 'Une seule entree est la page courante.');
        self::assertSame($label, trim($current->text()));
    }

    /**
     * @return list<string>
     */
    private function labels(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Administration"] li a')->each(fn ($n) => trim(preg_replace('/\s+/', ' ', $n->text())));
    }

    private function logInAndOpen(string $user, string $path): \Symfony\Component\DomCrawler\Crawler
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier($user));
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
