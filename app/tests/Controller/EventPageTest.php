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
        self::assertSelectorTextContains('main', 'sera présent');
        self::assertSelectorTextContains('main', 'ticket à 1,5 €');
        self::assertSelectorTextContains('main', '200 tickets');
        self::assertSelectorTextContains('main', "Un bon d'achat");
    }

    public function testAVisitorCanAddWhatTheyBring(): void
    {
        $event = $this->night();

        $crawler = $this->client->request('GET', '/evenements/'.$event->getId());
        $form = $crawler->selectButton('Ajouter à la liste')->form([
            'event_contribution[name]' => 'Léa',
            'event_contribution[item]' => 'Un gâteau',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/evenements/'.$event->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Léa');
        self::assertSelectorTextContains('main', 'Un gâteau');
    }

    public function testTheHoneypotRefusesARobot(): void
    {
        $event = $this->night();

        $crawler = $this->client->request('GET', '/evenements/'.$event->getId());
        $form = $crawler->selectButton('Ajouter à la liste')->form([
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
    }

    public function testAPastEventHasNoForm(): void
    {
        $event = $this->night(EventType::GameNight, '-3 days');

        $this->client->request('GET', '/evenements/'.$event->getId());

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
    }
}
