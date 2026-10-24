<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La section comptable vue du navigateur : qui y accede, et ce que donne
 * l'export.
 */
final class AccountingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    public function testAnonymousIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin/comptabilite');

        self::assertResponseRedirects();
    }

    public function testContributorSeesTheJournal(): void
    {
        $this->logIn();
        $this->client->request('GET', '/admin/comptabilite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Comptabilité');
        self::assertSelectorTextContains('body', 'Total encaissé');
        // Les libelles du moyen de reglement, pas leurs valeurs brutes.
        self::assertSelectorTextContains('body', 'Espèces');
        self::assertSelectorTextContains('body', 'Chèque');
    }

    public function testTheExportIsACsvForASpreadsheet(): void
    {
        $this->logIn();
        $this->client->request('GET', '/admin/comptabilite/export.csv');
        $response = $this->client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('comptabilite-', (string) $response->headers->get('Content-Disposition'));

        $body = (string) $this->client->getInternalResponse()->getContent();
        // BOM et point-virgule : sans eux, un tableur francophone ouvre tout
        // sur une colonne et mange les accents.
        self::assertStringStartsWith("\u{FEFF}", $body);
        self::assertStringContainsString('Date;Nature;', $body);
    }

    private function logIn(): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));
    }
}
