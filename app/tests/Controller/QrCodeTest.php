<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class QrCodeTest extends WebTestCase
{
    public function testTheAdminPageShowsBothAddresses(): void
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));

        $client->request('GET', '/admin/qr-code');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'svg.qrcode');
        self::assertSelectorTextContains('main', 'www.enviedejouer89.fr');
    }

    public function testBothFormatsDownload(): void
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));

        $client->request('GET', '/admin/qr-code/avec-www.svg');
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');

        $client->request('GET', '/admin/qr-code/sans-www.png');
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringStartsWith("\x89PNG", $client->getResponse()->getContent());
    }

    public function testAnUnknownVariantIsNotFound(): void
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));

        $client->request('GET', '/admin/qr-code/autre.svg');

        self::assertResponseStatusCodeSame(404);
    }
}
