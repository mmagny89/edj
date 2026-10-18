<?php

namespace App\Service;

use App\Entity\MembershipApplication;
use App\Entity\SeasonStamp;
use App\Repository\SeasonStampRepository;

/**
 * Les saisons imprimees au verso de la carte d'adherent, et leur tampon.
 *
 * Une saison porte le millesime de l'annee qu'elle **couvre**. L'annee
 * associative courant du 1er octobre au 30 septembre, une adhesion prise le
 * 1er octobre 2026 vaut pour la saison 2027, et une adhesion de mars 2027
 * vaut pour cette meme saison 2027. C'est contre-intuitif — on adhere en 2026
 * et la carte dit 2027 — d'ou le calcul isole ici plutot que glisse dans le
 * gabarit du PDF.
 */
final class MembershipSeasons
{
    /** Nombre de saisons imprimees au verso, comme sur la carte papier. */
    public const COUNT = 8;

    /** Premier mois de l'annee associative. */
    private const FIRST_MONTH = 10;

    public function __construct(
        private readonly SeasonStampRepository $stamps,
    ) {
    }

    /**
     * Les huit saisons a imprimer, a partir de la premiere adhesion connue de
     * la personne. Une saison est cochee quand un bulletin de cette saison est
     * valide : une adhesion en attente ou annulee ne coche rien.
     *
     * Une saison cochee porte le tampon de son annee, s'il en existe un. Sans
     * tampon enregistre, la case reste cochee sans ornement : mieux vaut cela
     * qu'une adhesion qui n'apparait pas.
     *
     * @return list<array{year: int, valid: bool, stamp: SeasonStamp|null}>
     */
    public function forApplication(MembershipApplication $application): array
    {
        $first = self::of($application->getSubmittedAt());
        // Le bulletin courant compte le premier : la collection de l'adherent
        // ne le porte pas tant qu'il n'a pas ete relu depuis la base, et sa
        // propre saison manquerait alors a sa propre carte.
        $validated = $application->isValidated() ? [$first => true] : [];

        foreach ($application->getMember()?->getApplications() ?? [] as $other) {
            $season = self::of($other->getSubmittedAt());
            $first = min($first, $season);
            if ($other->isValidated()) {
                $validated[$season] = true;
            }
        }

        $stamps = $this->stamps->indexedByYear();

        $seasons = [];
        for ($i = 0; $i < self::COUNT; ++$i) {
            $year = $first + $i;
            $valid = isset($validated[$year]);
            $seasons[] = [
                'year' => $year,
                'valid' => $valid,
                'stamp' => $valid ? ($stamps[$year] ?? null) : null,
            ];
        }

        return $seasons;
    }

    /**
     * La saison a laquelle appartient une date de depot.
     */
    public static function of(\DateTimeImmutable $date): int
    {
        $year = (int) $date->format('Y');

        return (int) $date->format('n') >= self::FIRST_MONTH ? $year + 1 : $year;
    }
}
