<?php

namespace App\Service;

/**
 * Jours feries de France metropolitaine.
 *
 * Calcules plutot que listes : les fetes fixes tombent a date constante, et
 * les trois fetes mobiles (lundi de Paques, Ascension, lundi de Pentecote)
 * se deduisent du dimanche de Paques. Le dimanche de Paques est calcule par
 * l'algorithme de Meeus/Jones/Butcher, valable pour tout le calendrier
 * gregorien, sans dependre de l'extension "calendar" que l'image ne porte pas.
 */
final class FrenchHolidays
{
    public function isHoliday(\DateTimeImmutable $date): bool
    {
        return isset($this->forYear((int) $date->format('Y'))[$date->format('Y-m-d')]);
    }

    /**
     * @return array<string, string> date ISO => nom de la fete
     */
    public function forYear(int $year): array
    {
        $easter = $this->easterSunday($year);

        return [
            sprintf('%04d-01-01', $year) => 'Jour de l\'an',
            $easter->modify('+1 day')->format('Y-m-d') => 'Lundi de Pâques',
            sprintf('%04d-05-01', $year) => 'Fête du travail',
            sprintf('%04d-05-08', $year) => 'Victoire 1945',
            $easter->modify('+39 days')->format('Y-m-d') => 'Ascension',
            $easter->modify('+50 days')->format('Y-m-d') => 'Lundi de Pentecôte',
            sprintf('%04d-07-14', $year) => 'Fête nationale',
            sprintf('%04d-08-15', $year) => 'Assomption',
            sprintf('%04d-11-01', $year) => 'Toussaint',
            sprintf('%04d-11-11', $year) => 'Armistice 1918',
            sprintf('%04d-12-25', $year) => 'Noël',
        ];
    }

    private function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
