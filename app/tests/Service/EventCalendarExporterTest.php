<?php

namespace App\Tests\Service;

use App\Entity\Event;
use App\Service\EventCalendarExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventCalendarExporterTest extends TestCase
{
    private const URL = 'https://enviedejouer89.fr/evenements/7';

    private function event(string $time, string $start = '2026-12-05', ?string $end = null): Event
    {
        $event = (new Event())->setTitle('Nuit du Jeu, édition; spéciale')->setTimeLabel($time)
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(null === $end ? null : new \DateTimeImmutable($end))
            ->setDescription("Ligne un\nLigne deux");
        (new \ReflectionProperty(Event::class, 'id'))->setValue($event, 7);

        return $event;
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function timedLabels(): iterable
    {
        // Heure d'hiver : Paris = UTC+1.
        yield 'plage' => ['18h30 - 00h', '20261205T173000Z', '20261205T230000Z'];
        yield 'fin apres minuit' => ['15h - 3h', '20261205T140000Z', '20261206T020000Z'];
        yield 'debut seul' => ['20h', '20261205T190000Z', '20261205T210000Z'];
    }

    #[DataProvider('timedLabels')]
    public function testTimesAreReadFromTheLabel(string $label, string $start, string $end): void
    {
        $ics = (new EventCalendarExporter())->toIcs($this->event($label), self::URL);

        self::assertStringContainsString("DTSTART:$start\r\n", $ics);
        self::assertStringContainsString("DTEND:$end\r\n", $ics);
    }

    public function testAFreeLabelBecomesAnAllDayEvent(): void
    {
        $ics = (new EventCalendarExporter())->toIcs($this->event('Journée'), self::URL);

        self::assertStringContainsString("DTSTART;VALUE=DATE:20261205\r\n", $ics);
        self::assertStringContainsString("DTEND;VALUE=DATE:20261206\r\n", $ics);
        self::assertStringContainsString('Horaire : Journée', $ics);
    }

    public function testAMultiDayEventIsAllDayWithAnExclusiveEnd(): void
    {
        $ics = (new EventCalendarExporter())->toIcs($this->event('14h - 18h', '2026-12-05', '2026-12-06'), self::URL);

        self::assertStringContainsString("DTSTART;VALUE=DATE:20261205\r\n", $ics);
        self::assertStringContainsString("DTEND;VALUE=DATE:20261207\r\n", $ics);
    }

    public function testInvalidHoursFallBackToAllDay(): void
    {
        $ics = (new EventCalendarExporter())->toIcs($this->event('25h'), self::URL);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20261205', $ics);
    }

    public function testTextIsEscapedAndTheUidIsStable(): void
    {
        $ics = (new EventCalendarExporter())->toIcs($this->event('20h'), self::URL);

        self::assertStringContainsString("UID:event-7@enviedejouer89.fr\r\n", $ics);
        self::assertStringContainsString('SUMMARY:Nuit du Jeu\, édition\; spéciale', $ics);
        self::assertStringContainsString('Ligne un\nLigne deux', $ics);
        self::assertStringContainsString('LOCATION:Maison des Associations\, 89300 Joigny', $ics);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function testNoLineExceedsSeventyFiveOctets(): void
    {
        $event = $this->event('20h')->setDescription(str_repeat('é', 200));
        $ics = (new EventCalendarExporter())->toIcs($event, self::URL);

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line));
        }
    }

    public function testGoogleUrl(): void
    {
        $url = (new EventCalendarExporter())->googleUrl($this->event('18h30 - 00h'), self::URL);

        self::assertStringStartsWith('https://calendar.google.com/calendar/render?action=TEMPLATE', $url);
        self::assertStringContainsString('dates=20261205T173000Z%2F20261205T230000Z', $url);
    }
}
