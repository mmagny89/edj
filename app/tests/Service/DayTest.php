<?php

namespace App\Tests\Service;

use App\Repository\MembershipApplicationRepository;
use App\Service\Day;
use App\Service\MembershipSeasons;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Enregistre en UTC, lu a l'heure de Paris.
 */
final class DayTest extends KernelTestCase
{
    public function testAnInstantBelongsToItsParisDay(): void
    {
        $instant = new \DateTimeImmutable('2026-09-30 22:30:00 UTC');

        self::assertSame('2026-10-01', Day::of($instant)->format('Y-m-d'));
        self::assertSame(2027, MembershipSeasons::of($instant));
        self::assertGreaterThanOrEqual(MembershipApplicationRepository::seasonInstantBounds(2027)[0], $instant);
    }

    public function testACalendarDayKeepsItsDate(): void
    {
        self::assertSame(2026, MembershipSeasons::of(new \DateTimeImmutable('2026-09-30')));
        self::assertSame(2027, MembershipSeasons::of(new \DateTimeImmutable('2026-10-01')));
    }

    public function testTemplatesShowParisTime(): void
    {
        $twig = static::getContainer()->get('twig');
        $template = $twig->createTemplate("{{ at|date('d/m/Y H:i') }}");

        self::assertSame('01/07/2026 00:30', $template->render(['at' => new \DateTimeImmutable('2026-06-30 22:30 UTC')]));
        self::assertSame('01/12/2026', $twig->createTemplate("{{ day|date('d/m/Y') }}")->render(['day' => new \DateTimeImmutable('2026-12-01')]));
    }
}
