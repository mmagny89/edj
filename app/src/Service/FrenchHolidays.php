<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Jours feries de France metropolitaine.
 *
 * La source est l'API officielle du calendrier (calendrier.api.gouv.fr). Une
 * annee interrogee est conservee trente jours : la generation d'une saison
 * demande trois annees au plus, soit trois appels par mois.
 *
 * Si l'API ne repond pas — panne, delai, reponse illisible — le calcul local
 * prend le relais : onze dates, dont trois deduites du dimanche de Paques par
 * l'algorithme de Meeus/Jones/Butcher, sans dependre de l'extension "calendar"
 * que l'image ne porte pas. Une generation ne doit jamais echouer parce qu'un
 * service tiers est indisponible. Ce repli n'est conserve qu'une heure, pour
 * que l'API soit reinterrogee des qu'elle revient.
 */
final class FrenchHolidays
{
    private const ENDPOINT = 'https://calendrier.api.gouv.fr/jours-feries/metropole/%d.json';

    private const API_TTL = 2592000; // 30 jours

    private const FALLBACK_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isHoliday(\DateTimeImmutable $date): bool
    {
        return isset($this->forYear((int) $date->format('Y'))[$date->format('Y-m-d')]);
    }

    /**
     * @return array<string, string> date ISO => nom de la fete
     */
    public function forYear(int $year): array
    {
        return $this->cache->get(
            'french_holidays_'.$year,
            function (ItemInterface $item) use ($year): array {
                try {
                    $holidays = $this->fetch($year);
                    $item->expiresAfter(self::API_TTL);

                    return $holidays;
                } catch (\Throwable $e) {
                    $this->logger->warning('API des jours feries indisponible, calcul local utilise.', [
                        'year' => $year,
                        'error' => $e->getMessage(),
                    ]);
                    $item->expiresAfter(self::FALLBACK_TTL);

                    return $this->compute($year);
                }
            },
        );
    }

    /**
     * @return array<string, string>
     */
    private function fetch(int $year): array
    {
        $data = $this->httpClient
            ->request('GET', sprintf(self::ENDPOINT, $year), ['timeout' => 5])
            ->toArray();

        $holidays = [];
        foreach ($data as $date => $name) {
            if (!\is_string($date) || !\is_string($name) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new \UnexpectedValueException('Reponse inattendue de l\'API des jours feries.');
            }

            $holidays[$date] = $name;
        }

        // Une liste vide ou trop courte est une reponse fausse, pas un calendrier
        // sans ferie : mieux vaut le calcul local que de generer un jour ferie.
        if (\count($holidays) < 8) {
            throw new \UnexpectedValueException('Liste de jours feries incomplete.');
        }

        return $holidays;
    }

    /**
     * @return array<string, string>
     */
    private function compute(int $year): array
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
