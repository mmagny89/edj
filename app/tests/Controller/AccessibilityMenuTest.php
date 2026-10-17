<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccessibilityMenuTest extends WebTestCase
{
    public function testEveryPublicPageOffersTheFourAccessibilityOptions(): void
    {
        $client = static::createClient();

        foreach (['/', '/adhesion/bulletin', '/mentions-legales'] as $path) {
            $crawler = $client->request('GET', $path);

            $options = $crawler->filter('[data-a11y-option]')->each(static fn ($input) => $input->attr('data-a11y-option'));
            self::assertSame(['contrast', 'text', 'links', 'motion'], $options, $path);
            self::assertSame('false', $crawler->filter('[data-a11y-button]')->attr('aria-expanded'), $path);
        }
    }

    public function testTheMenuIsHiddenUntilJavaScriptRevealsIt(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertNotNull($crawler->filter('[data-a11y-menu]')->attr('hidden'), 'Sans JavaScript, des réglages inertes ne s\'affichent pas.');
        self::assertNotEmpty(trim($crawler->filter('[data-a11y-button] .sr-only')->text()), 'Le bouton a un nom pour les lecteurs d\'écran.');
    }
}
