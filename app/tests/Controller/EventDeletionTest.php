<?php

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Enum\EventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Suppression d'un evenement depuis la liste, de bout en bout : la page rend
 * le formulaire avec son jeton, le jeton est renvoye, la ligne disparait.
 *
 * Ecrit en base de test (edj_test, creee par `doctrine:database:create
 * --env=test` puis `doctrine:migrations:migrate --env=test`).
 */
final class EventDeletionTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.Event::class)->execute();

        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.Event::class)->execute();
        parent::tearDown();
    }

    private function createEvent(string $title): Event
    {
        $event = (new Event())
            ->setType(EventType::Special)
            ->setStartsAt(new \DateTimeImmutable('+10 days'))
            ->setTitle($title)
            ->setTimeLabel('Soirée');
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    public function testAnEventCanBeDeletedFromTheList(): void
    {
        $event = $this->createEvent('Nuit du Jeu');
        $id = $event->getId();

        $crawler = $this->client->request('GET', '/admin/evenements');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter(sprintf('form[action$="/%d/supprimer"]', $id))->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/evenements');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'supprimé');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Event::class, $id));
    }

    public function testAnEventWithQuotesInItsTitleCanBeDeleted(): void
    {
        $event = $this->createEvent('L\'après-midi "spéciale" <Joigny>');
        $id = $event->getId();

        $crawler = $this->client->request('GET', '/admin/evenements');
        $this->client->submit($crawler->filter(sprintf('form[action$="/%d/supprimer"]', $id))->form());

        self::assertResponseRedirects('/admin/evenements');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Event::class, $id));
    }

    public function testDeletionWithAnInvalidTokenIsRefused(): void
    {
        $event = $this->createEvent('Nuit du Jeu');
        $id = $event->getId();

        $this->client->request('POST', sprintf('/admin/evenements/%d/supprimer', $id), ['_token' => 'faux']);

        self::assertResponseRedirects('/admin/evenements');
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Event::class, $id));
    }

    public function testListIsSortedFromTheNearestToTheFurthest(): void
    {
        foreach (['+30 days' => 'Loin', '+3 days' => 'Proche', '+15 days' => 'Milieu'] as $when => $title) {
            $event = (new Event())->setType(EventType::Special)->setStartsAt(new \DateTimeImmutable($when))->setTitle($title)->setTimeLabel('Soirée');
            $this->entityManager->persist($event);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/evenements');
        $titles = $crawler->filter('tbody tr td:nth-child(2) > div:first-child')->each(
            static fn ($node) => trim($node->text()),
        );

        self::assertSame(['Proche', 'Milieu', 'Loin'], $titles);
    }
}
