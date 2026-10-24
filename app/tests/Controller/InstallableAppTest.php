<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le site se propose comme application (ecran d'accueil) : manifeste, icones,
 * service worker minimal et encart d'installation. Le navigateur lit le
 * manifeste, pas ce test : il garde les engagements qu'un navigateur refuserait
 * en silence — une icone absente ou de mauvaise taille suffit a retirer la
 * proposition d'installation, sans la moindre erreur.
 */
final class InstallableAppTest extends WebTestCase
{
    private const PUBLIC_DIR = __DIR__.'/../../public';

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $json = file_get_contents(self::PUBLIC_DIR.'/manifest.webmanifest');
        self::assertNotFalse($json);

        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testTheManifestDeclaresWhatInstallationNeeds(): void
    {
        $manifest = $this->manifest();

        foreach (['name', 'short_name', 'start_url', 'scope', 'display', 'icons', 'theme_color', 'background_color'] as $member) {
            self::assertArrayHasKey($member, $manifest, sprintf('Le manifeste doit porter « %s ».', $member));
        }
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/', $manifest['start_url']);
        self::assertSame('/', $manifest['scope']);
        self::assertSame('fr', $manifest['lang']);
    }

    public function testEveryDeclaredIconExistsAtItsDeclaredSize(): void
    {
        $purposes = [];
        foreach ($this->manifest()['icons'] as $icon) {
            $path = self::PUBLIC_DIR.$icon['src'];
            self::assertFileExists($path, sprintf('Icone manquante : %s', $icon['src']));
            [$width, $height] = getimagesize($path);
            self::assertSame($icon['sizes'], $width.'x'.$height, sprintf('Taille declaree fausse pour %s.', $icon['src']));
            $purposes[] = $icon['purpose'];
        }

        // Une icone « any » en 192 et 512 est exigee pour proposer l'installation ;
        // la « maskable » evite que le systeme n'entoure la mascotte d'un fond blanc.
        self::assertContains('any', $purposes);
        self::assertContains('maskable', $purposes);
        self::assertContains('192x192', array_column($this->manifest()['icons'], 'sizes'));
        self::assertContains('512x512', array_column($this->manifest()['icons'], 'sizes'));
    }

    public function testTheIphoneIconIsAnOpaqueSquare(): void
    {
        $path = self::PUBLIC_DIR.'/icons/apple-touch-icon.png';
        self::assertFileExists($path);
        [$width, $height] = getimagesize($path);
        self::assertSame($width, $height);
        // iOS noircit la transparence : l'icone doit etre pleine.
        $image = imagecreatefrompng($path);
        self::assertSame(0, (imagecolorat($image, 0, 0) >> 24) & 0x7F, 'Le coin de l\'icone doit etre opaque.');
    }

    public function testThePublicPagesLinkTheManifestAndTheInstallBlock(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('/manifest.webmanifest', $crawler->filter('link[rel="manifest"]')->attr('href'));
        self::assertSame('/icons/apple-touch-icon.png', $crawler->filter('link[rel="apple-touch-icon"]')->attr('href'));
        // La couleur de la barre du navigateur et celle du manifeste sont la meme.
        self::assertSame($this->manifest()['theme_color'], $crawler->filter('meta[name="theme-color"]')->attr('content'));

        $block = $crawler->filter('[data-install-app]');
        self::assertCount(1, $block);
        self::assertNotNull($block->attr('hidden'), 'L\'encart est cache tant que le JavaScript n\'a pas dit comment installer.');
        foreach (['prompt', 'ios', 'samsung', 'android'] as $mode) {
            self::assertGreaterThan(0, $block->filter('p[data-install-mode="'.$mode.'"], button[data-install-mode="'.$mode.'"]')->count(), sprintf('Mode « %s » sans texte.', $mode));
        }
    }

    public function testTheAdminPagesLinkTheSameManifest(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSame('/manifest.webmanifest', $crawler->filter('link[rel="manifest"]')->attr('href'));
        self::assertSame($this->manifest()['theme_color'], $crawler->filter('meta[name="theme-color"]')->attr('content'));
    }

    /**
     * Le service worker n'existe que pour satisfaire certains navigateurs : s'il
     * mettait quoi que ce soit en cache, le site cesserait d'etre a jour.
     */
    public function testTheServiceWorkerNeverCachesAnything(): void
    {
        $script = (string) file_get_contents(self::PUBLIC_DIR.'/sw.js');
        // Les commentaires disent « aucun cache » : on ne regarde que le code.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $script);

        self::assertStringContainsString("addEventListener('fetch'", $code);
        self::assertDoesNotMatchRegularExpression('/\bcaches\b|\.match\(|respondWith|cache\.add|cache\.put/i', $code);
    }

    public function testBothEntriesRegisterTheServiceWorker(): void
    {
        foreach (['site.js', 'app.js'] as $entry) {
            self::assertStringContainsString("import './pwa.js';", (string) file_get_contents(__DIR__.'/../../assets/'.$entry), sprintf('%s doit charger pwa.js.', $entry));
        }
    }
}
