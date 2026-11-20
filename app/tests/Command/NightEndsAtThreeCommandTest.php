<?php

namespace App\Tests\Command;

use App\Entity\Event;
use App\Enum\EventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class NightEndsAtThreeCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.Event::class)->execute();
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.Event::class)->execute();
        parent::tearDown();
    }

    private function event(string $title, EventType $type, string $time): int
    {
        $event = (new Event())->setTitle($title)->setType($type)->setTimeLabel($time)->setStartsAt(new \DateTimeImmutable('+10 days'));
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return (int) $event->getId();
    }

    private function applyCommand(array $options = []): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:events:nuit-fin-3h'));
        $tester->execute($options);
        $this->entityManager->clear();
    }

    public function testNightsEndAtThreeAndFourteenIsUntouched(): void
    {
        $night = $this->event('Nuit du Jeu', EventType::GameNight, '15h - 4h');
        $afternoon = $this->event('Après-midi', EventType::Monthly, '14h - 18h');
        $legacy = $this->event('Nuit du Jeu de printemps', EventType::Special, '14h - 4h du matin');

        $this->applyCommand();

        self::assertSame('15h - 3h', $this->entityManager->find(Event::class, $night)->getTimeLabel());
        self::assertSame('14h - 18h', $this->entityManager->find(Event::class, $afternoon)->getTimeLabel());
        $migrated = $this->entityManager->find(Event::class, $legacy);
        self::assertSame('14h - 3h du matin', $migrated->getTimeLabel());
        self::assertSame(EventType::GameNight, $migrated->getType());
    }

    public function testZeroPaddedFourIsHandled(): void
    {
        $night = $this->event('Nuit du Jeu', EventType::GameNight, '15h - 04h');

        $this->applyCommand();

        self::assertSame('15h - 03h', $this->entityManager->find(Event::class, $night)->getTimeLabel());
    }

    public function testDryRunChangesNothing(): void
    {
        $night = $this->event('Nuit du Jeu', EventType::GameNight, '15h - 4h');

        $this->applyCommand(['--dry-run' => true]);

        self::assertSame('15h - 4h', $this->entityManager->find(Event::class, $night)->getTimeLabel());
    }
}
