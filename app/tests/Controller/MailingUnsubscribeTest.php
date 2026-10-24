<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Service\MailingUnsubscribeLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Se desinscrire des messages de l'association, depuis le lien d'un courriel.
 */
final class MailingUnsubscribeTest extends WebTestCase
{
    private const NUMBER = 'EDJ-7777';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
        $this->em->persist(new Member(self::NUMBER));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testTheLinkShowsTheChoiceAndChangesNothingByItself(): void
    {
        $this->client->request('GET', $this->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Messages de l\'association');
        self::assertSelectorExists('button:contains("Ne plus recevoir les messages")');
        self::assertFalse($this->member()->isOptedOutOfMailings(), 'Ouvrir le lien ne desinscrit pas : les antivirus et les messageries le font.');
    }

    public function testConfirmingUnsubscribesAndKeepsTheFirstDate(): void
    {
        $crawler = $this->client->request('GET', $this->link());
        $this->client->submit($crawler->selectButton('Ne plus recevoir les messages')->form());
        self::assertResponseRedirects();

        $first = $this->member()->getMailingOptOutAt();
        self::assertNotNull($first);

        $this->client->followRedirect();
        self::assertSelectorExists('button:contains("Recevoir de nouveau les messages")');

        // Un second clic ne repousse pas la date d'origine.
        $this->client->request('POST', $this->link(), ['action' => 'unsubscribe']);
        $this->em->clear();
        self::assertEquals($first, $this->member()->getMailingOptOutAt());
    }

    public function testTheOneClickPostOfAMailClientUnsubscribes(): void
    {
        // RFC 8058 : la messagerie envoie un POST sans formulaire ni jeton.
        $this->client->request('POST', $this->link(), ['List-Unsubscribe' => 'One-Click']);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->member()->isOptedOutOfMailings());
    }

    public function testOneCanSubscribeAgain(): void
    {
        $this->client->request('POST', $this->link(), ['List-Unsubscribe' => 'One-Click']);
        $this->em->clear();
        self::assertTrue($this->member()->isOptedOutOfMailings());

        $this->client->request('POST', $this->link(), ['action' => 'resubscribe']);
        $this->em->clear();

        self::assertFalse($this->member()->isOptedOutOfMailings());
    }

    public function testAnAlteredSignatureIsRefusedAndChangesNothing(): void
    {
        $this->client->request('POST', preg_replace('/_hash=[^&]+/', '_hash=faux', $this->link()), ['List-Unsubscribe' => 'One-Click']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->member()->isOptedOutOfMailings());
    }

    public function testALinkWithoutSignatureIsRefused(): void
    {
        $this->client->request('GET', '/adhesion/desinscription/'.self::NUMBER);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Un numero inconnu et une signature fausse donnent la meme page : elle ne dit
     * jamais qu'un numero existe.
     */
    public function testAnUnknownNumberGetsTheSameAnswerAsABadSignature(): void
    {
        $this->client->request('GET', '/adhesion/desinscription/'.self::NUMBER.'?_hash=faux');
        $bad = $this->normalized();

        $unknown = static::getContainer()->get(MailingUnsubscribeLink::class)->url(new Member('EDJ-7778'));
        $this->client->request('GET', $unknown);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($bad, $this->normalized());
    }

    public function testTheLinkHasNoExpiry(): void
    {
        self::assertStringNotContainsString('_expiration', $this->link());
    }

    /**
     * La page sans ce qui change d'une reponse a l'autre (le nonce de la politique
     * de contenu) : on compare ce que lit le visiteur.
     */
    private function normalized(): string
    {
        $page = (string) preg_replace('/(nonce|csp-nonce)([="\s]+content)?="[^"]*"/', '$1=""', (string) $this->client->getResponse()->getContent());

        // L'adresse demandee est renvoyee telle quelle (canonical, og:url) : le
        // visiteur l'a tapee, elle ne lui apprend rien.
        return (string) preg_replace('~/adhesion/desinscription/EDJ-\d+~', '/adhesion/desinscription/EDJ-0', $page);
    }

    private function link(): string
    {
        $url = static::getContainer()->get(MailingUnsubscribeLink::class)->url(new Member(self::NUMBER));

        return parse_url($url, \PHP_URL_PATH).'?'.parse_url($url, \PHP_URL_QUERY);
    }

    private function member(): Member
    {
        $this->em->clear();

        return $this->em->getRepository(Member::class)->findOneBy(['number' => self::NUMBER]);
    }

    private function purge(): void
    {
        $this->em->createQuery('DELETE FROM '.Member::class.' m WHERE m.number IN (:n)')->setParameter('n', [self::NUMBER, 'EDJ-7778'])->execute();
        $this->em->clear();
    }
}
