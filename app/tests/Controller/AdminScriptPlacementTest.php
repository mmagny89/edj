<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le script de l'administration vit dans le <head>.
 *
 * Turbo Drive remplace le <body> a chaque navigation et reexecute les scripts
 * qu'il y trouve : un script en bas de page demarre une application Stimulus de
 * plus a chaque clic sur un lien, et les controleurs s'empilent sur le meme
 * element. Le test PHP ne voit pas ce defaut (il n'execute pas de JavaScript) ;
 * il garde au moins la cause : aucun script externe dans le corps de la page.
 */
final class AdminScriptPlacementTest extends WebTestCase
{
    public function testTheApplicationScriptIsInTheHeadNotTheBody(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));

        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('head script[src]')->count(), 'Le script de l\'application doit etre dans le head.');
        self::assertSame(0, $crawler->filter('body script')->count(), 'Un script dans le body est reexecute par Turbo a chaque navigation.');
    }
}
