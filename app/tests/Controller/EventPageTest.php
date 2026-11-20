<?php

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\EventContribution;
use App\Enum\EventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventPageTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.Event::class)->execute();
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.Event::class)->execute();
        parent::tearDown();
    }

    private function night(EventType $type = EventType::GameNight, string $when = '+10 days'): Event
    {
        $event = (new Event())->setTitle('Nuit du Jeu')->setType($type)->setTimeLabel('15h - 3h')
            ->setStartsAt(new \DateTimeImmutable($when))
            ->setLudipassionPresent(true)
            ->setRaffleEnabled(true)->setRaffleTicketPriceCents(150)->setRaffleTicketCount(200)
            ->setRafflePrizes("Un jeu\nUn bon d'achat");
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    public function testThePageShowsLudipassionAndTheRaffle(): void
    {
        $event = $this->night();

        $this->client->request('GET', '/evenements/'.$event->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.info-tiles', 'Sera présent');
        self::assertSelectorTextContains('.raffle-panel', '1,5 €');
        self::assertSelectorTextContains('.raffle-panel', '200');
        self::assertSelectorTextContains('.raffle-panel', "Un bon d'achat");
        self::assertSelectorExists('a[href$="/apports"]');
        self::assertSelectorNotExists('form');
    }

    public function testAVisitorCanAddWhatTheyBring(): void
    {
        $event = $this->night();
        $url = '/evenements/'.$event->getId().'/apports';

        $crawler = $this->client->request('GET', $url);
        $form = $crawler->selectButton('Ajouter')->form([
            'event_contribution[name]' => 'Léa',
            'event_contribution[item]' => 'Un gâteau',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.note-grid', 'Léa');
        self::assertSelectorTextContains('.note-grid', 'Un gâteau');
    }

    public function testTheHoneypotRefusesARobot(): void
    {
        $event = $this->night();

        $crawler = $this->client->request('GET', '/evenements/'.$event->getId().'/apports');
        $form = $crawler->selectButton('Ajouter')->form([
            'event_contribution[name]' => 'Bot',
            'event_contribution[item]' => 'Spam',
            'event_contribution[website]' => 'http://spam.example',
        ]);
        $this->client->submit($form);

        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(EventContribution::class)->findAll());
    }

    public function testAnOrdinaryEventHasNoPage(): void
    {
        $event = $this->night(EventType::Special);

        $this->client->request('GET', '/evenements/'.$event->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/evenements/'.$event->getId().'/apports');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPastEventHasNoForm(): void
    {
        $event = $this->night(EventType::GameNight, '-3 days');

        $this->client->request('GET', '/evenements/'.$event->getId().'/apports');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form');
    }

    public function testDeletingTheEventRemovesItsContributions(): void
    {
        $event = $this->night();
        $this->entityManager->persist((new EventContribution($event))->setName('Léa')->setItem('Un gâteau'));
        $this->entityManager->flush();

        $this->entityManager->createQuery('DELETE FROM '.Event::class)->execute();

        self::assertCount(0, $this->entityManager->getRepository(EventContribution::class)->findAll());
    }

    public function testTheActivitiesPageIsPublic(): void
    {
        $this->client->request('GET', '/rendez-vous');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#mardis');
        self::assertSelectorExists('#nuits-du-jeu');
        self::assertSelectorExists('#week-end-du-jeu');
    }
}
