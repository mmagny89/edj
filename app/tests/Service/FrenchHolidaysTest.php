<?php

namespace App\Tests\Service;

use App\Service\FrenchHolidays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrenchHolidaysTest extends TestCase
{
    /**
     * Dimanche de Paques : 28 mars 2027, 20 avril 2025, 5 avril 2026. Les
     * fetes mobiles s'en deduisent, d'ou des dates de controle sur trois ans.
     */
    #[DataProvider('holidays')]
    public function testKnownHolidays(string $date): void
    {
        self::assertTrue((new FrenchHolidays())->isHoliday(new \DateTimeImmutable($date)), $date);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function holidays(): iterable
    {
        yield 'jour de l\'an 2027' => ['2027-01-01'];
        yield 'fete du travail 2027' => ['2027-05-01'];
        yield 'lundi de paques 2027' => ['2027-03-29'];
        yield 'ascension 2027' => ['2027-05-06'];
        yield 'lundi de pentecote 2027' => ['2027-05-17'];
        yield 'lundi de paques 2026' => ['2026-04-06'];
        yield 'ascension 2026' => ['2026-05-14'];
        yield 'lundi de paques 2025' => ['2025-04-21'];
        yield 'noel 2026' => ['2026-12-25'];
    }

    #[DataProvider('ordinaryDays')]
    public function testOrdinaryDaysAreNotHolidays(string $date): void
    {
        self::assertFalse((new FrenchHolidays())->isHoliday(new \DateTimeImmutable($date)), $date);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ordinaryDays(): iterable
    {
        // Le samedi de Paques et le dimanche de Paques ne sont pas feries.
        yield 'samedi de paques 2027' => ['2027-03-27'];
        yield 'dimanche de paques 2027' => ['2027-03-28'];
        yield 'premier samedi de janvier 2027' => ['2027-01-02'];
        yield 'mardi ordinaire' => ['2026-10-20'];
    }
}
