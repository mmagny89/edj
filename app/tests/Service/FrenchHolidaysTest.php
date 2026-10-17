<?php

namespace App\Tests\Service;

use App\Service\FrenchHolidays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FrenchHolidaysTest extends TestCase
{
    /**
     * Client qui echoue : le calcul local est alors la seule source, et ces
     * cas verifient donc le repli sans reseau.
     */
    private static function offline(): FrenchHolidays
    {
        return new FrenchHolidays(
            new MockHttpClient(static fn () => throw new \RuntimeException('reseau coupe')),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    /**
     * Dimanche de Paques : 28 mars 2027, 20 avril 2025, 5 avril 2026. Les
     * fetes mobiles s'en deduisent, d'ou des dates de controle sur trois ans.
     */
    #[DataProvider('holidays')]
    public function testFallbackKnowsTheHolidays(string $date): void
    {
        self::assertTrue(self::offline()->isHoliday(new \DateTimeImmutable($date)), $date);
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
        self::assertFalse(self::offline()->isHoliday(new \DateTimeImmutable($date)), $date);
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

    public function testApiAnswerIsUsedWhenAvailable(): void
    {
        // Un ferie qu'aucun calcul local ne produit : la preuve que la source
        // est bien l'API et non le repli.
        $payload = [
            '2027-01-01' => 'a', '2027-03-29' => 'b', '2027-05-01' => 'c',
            '2027-05-08' => 'd', '2027-05-06' => 'e', '2027-05-17' => 'f',
            '2027-07-14' => 'g', '2027-08-15' => 'h', '2027-02-02' => 'jour de test',
        ];
        $holidays = new FrenchHolidays(
            new MockHttpClient(new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR))),
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertTrue($holidays->isHoliday(new \DateTimeImmutable('2027-02-02')));
    }

    public function testApiIsCalledOnlyOncePerYear(): void
    {
        $calls = 0;
        $payload = [
            '2027-01-01' => 'a', '2027-03-29' => 'b', '2027-05-01' => 'c',
            '2027-05-08' => 'd', '2027-05-06' => 'e', '2027-05-17' => 'f',
            '2027-07-14' => 'g', '2027-08-15' => 'h',
        ];
        $holidays = new FrenchHolidays(
            new MockHttpClient(static function () use (&$calls, $payload) {
                ++$calls;

                return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR));
            }),
            new ArrayAdapter(),
            new NullLogger(),
        );

        $holidays->isHoliday(new \DateTimeImmutable('2027-05-01'));
        $holidays->isHoliday(new \DateTimeImmutable('2027-06-05'));

        self::assertSame(1, $calls);
    }

    /**
     * Une liste vide est une reponse fausse : elle ne doit pas faire croire
     * qu'aucun jour n'est ferie.
     */
    public function testEmptyApiAnswerFallsBackToLocalCalculation(): void
    {
        $holidays = new FrenchHolidays(
            new MockHttpClient(new MockResponse('{}')),
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertTrue($holidays->isHoliday(new \DateTimeImmutable('2027-05-01')));
    }
}
