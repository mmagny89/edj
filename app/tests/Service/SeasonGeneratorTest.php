<?php

namespace App\Tests\Service;

use App\Dto\SeasonGenerationRequest;
use App\Entity\Event;
use App\Enum\EventType;
use App\Repository\EventRepository;
use App\Service\FrenchHolidays;
use App\Service\SeasonGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Le generateur ne touche la base que par deux collaborateurs, tous deux
 * doubles ici : le test porte sur les dates calculees, pas sur Doctrine.
 */
final class SeasonGeneratorTest extends TestCase
{
    public function testMonthlyEventsFallOnTheFirstSaturday(): void
    {
        $request = new SeasonGenerationRequest();
        $request->from = new \DateTimeImmutable('2026-01-01');
        $request->to = new \DateTimeImmutable('2026-03-31');
        $request->weekly = false;

        $dates = array_map(
            static fn (Event $event) => $event->getStartsAt()?->format('Y-m-d'),
            $this->generate($request),
        );

        // Janvier, fevrier et mars 2026 : les 1ers samedis tombent le 3, le 7
        // et le 7. Le 17 janvier — 3e samedi — ne doit plus apparaitre.
        self::assertSame(['2026-01-03', '2026-02-07', '2026-03-07'], $dates);
    }

    public function testMonthlyEventsCarryTheMonthlyType(): void
    {
        $request = new SeasonGenerationRequest();
        $request->from = new \DateTimeImmutable('2026-01-01');
        $request->to = new \DateTimeImmutable('2026-01-31');
        $request->weekly = false;

        $events = $this->generate($request);

        self::assertCount(1, $events);
        self::assertSame(EventType::Monthly, $events[0]->getType());
    }

    /**
     * Une borne de depart posterieure au 1er samedi exclut le mois en cours :
     * la date est deja passee, la generer n'aurait pas de sens.
     */
    public function testFirstSaturdayBeforeTheStartDateIsSkipped(): void
    {
        $request = new SeasonGenerationRequest();
        $request->from = new \DateTimeImmutable('2026-01-10');
        $request->to = new \DateTimeImmutable('2026-02-28');
        $request->weekly = false;

        $dates = array_map(
            static fn (Event $event) => $event->getStartsAt()?->format('Y-m-d'),
            $this->generate($request),
        );

        self::assertSame(['2026-02-07'], $dates);
    }

    /**
     * Le 1er mai 2027 est a la fois un samedi et un jour ferie : l'apres-midi
     * n'est pas creee. C'est le cas qui a motive la regle.
     */
    public function testMonthlyEventOnAHolidayIsNotCreated(): void
    {
        $request = new SeasonGenerationRequest();
        $request->from = new \DateTimeImmutable('2027-04-01');
        $request->to = new \DateTimeImmutable('2027-06-30');
        $request->weekly = false;

        $dates = array_map(
            static fn (Event $event) => $event->getStartsAt()?->format('Y-m-d'),
            $this->generate($request),
        );

        self::assertSame(['2027-04-03', '2027-06-05'], $dates);
    }

    /**
     * Le mardi 14 juillet 2026 est ferie, de meme que le mardi 25 decembre
     * 2029 : aucun des deux ne doit apparaitre.
     */
    public function testWeeklyEventsSkipHolidays(): void
    {
        $request = new SeasonGenerationRequest();
        $request->from = new \DateTimeImmutable('2026-07-07');
        $request->to = new \DateTimeImmutable('2026-07-21');
        $request->monthly = false;

        $dates = array_map(
            static fn (Event $event) => $event->getStartsAt()?->format('Y-m-d'),
            $this->generate($request),
        );

        self::assertSame(['2026-07-07', '2026-07-21'], $dates);
    }

    /**
     * @return list<Event>
     */
    private function generate(SeasonGenerationRequest $request): array
    {
        $persisted = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );

        $events = $this->createStub(EventRepository::class);
        $events->method('existingKeys')->willReturn([]);

        (new SeasonGenerator($entityManager, $events, new FrenchHolidays()))->generate($request);

        return $persisted;
    }
}
