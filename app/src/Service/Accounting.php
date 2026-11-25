<?php

namespace App\Service;

use App\Entity\EventAttendance;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Repository\EventAttendanceRepository;
use App\Repository\MembershipApplicationRepository;

/**
 * Le journal de caisse d'une saison, pour la tresorerie.
 *
 * Deux recettes, et deux seulement : les cotisations annuelles encaissees a
 * la validation d'un bulletin, et l'euro que paie un consommateur a chaque
 * seance. Elles vivent dans deux tables differentes et se lisent ici d'une
 * seule piece, parce que c'est d'une seule piece qu'on tient une compta.
 *
 * Les montants circulent en centimes jusqu'a l'affichage : un total d'euros
 * entiers n'a pas besoin de virgule, mais un arrondi glisse dans un total ne
 * se rattrape plus.
 */
final readonly class Accounting
{
    public function __construct(
        private MembershipApplicationRepository $applications,
        private EventAttendanceRepository $attendances,
    ) {
    }

    /**
     * Toutes les lignes de recette d'une saison, les plus recentes d'abord.
     *
     * Une ligne porte sa date, qui est celle de l'encaissement : la
     * validation du bulletin pour une cotisation, la seance pour un passage.
     * La date de saisie, elle, n'interesse pas la caisse.
     *
     * @return list<array{id: int, cashedOn: \DateTimeImmutable|null, date: \DateTimeImmutable, kind: string, label: string,
     *     person: string, number: string|null, method: PaymentMethod|null,
     *     cents: int, creditCents: int, paid: bool}>
     */
    public function entries(int $season): array
    {
        $rows = [];

        foreach ($this->cotisations($season) as $application) {
            $rows[] = [
                'id' => (int) $application->getId(),
                'cashedOn' => $application->getCashedOn(),
                'date' => $application->getStatusChangedAt() ?? $application->getSubmittedAt(),
                'kind' => 'cotisation',
                'label' => $application->getMembershipType()?->label() ?? 'Adhésion',
                'person' => $this->nameOf($application),
                'number' => $application->getMember()?->getNumber(),
                'method' => $application->getPaymentMethod(),
                // Net des euros de seance, deja comptes sur leurs propres lignes.
                'cents' => 100 * (int) $application->amountDue(),
                'creditCents' => 100 * $application->consumerCredit(),
                'paid' => null !== $application->getPaymentMethod(),
            ];
        }

        foreach ($this->attendances->findForSeason($season) as $attendance) {
            // La presence gratuite d'un adherent annuel ne concerne pas la caisse.
            if (!$attendance->isPaid() && MembershipType::Consumer !== $attendance->getApplication()->getMembershipType()) {
                continue;
            }

            $rows[] = [
                'id' => (int) $attendance->getId(),
                'cashedOn' => $attendance->getCashedOn(),
                'date' => $attendance->getOccurredOn(),
                'kind' => 'passage',
                'label' => $attendance->getLabel(),
                'person' => $this->nameOf($attendance->getApplication()),
                'number' => $attendance->getApplication()->getMember()?->getNumber(),
                'method' => $attendance->getPaymentMethod(),
                // Zero pour une venue gratuite : ce n'est pas une creance.
                'cents' => $attendance->collectedCents(),
                'creditCents' => 0,
                'paid' => $attendance->isPaid(),
            ];
        }

        usort($rows, static fn (array $x, array $y) => $y['date'] <=> $x['date']);

        return $rows;
    }

    /**
     * Ce qu'il y a dans la caisse a la fin de la saison, et d'ou ca vient.
     *
     * Seules les lignes reglees sont comptees : une cotisation validee sans
     * moyen de reglement est une creance, pas une recette, et se retrouve dans
     * unpaid(). Un passage gratuit vaut zero.
     *
     * @param list<array{id: int, cashedOn: \DateTimeImmutable|null, date: \DateTimeImmutable, kind: string, label: string,
     *     person: string, number: string|null, method: PaymentMethod|null,
     *     cents: int, creditCents: int, paid: bool}>|null $entries
     *
     * @return array{cotisationsCents: int, passagesCents: int, totalCents: int,
     *     passages: int, byMethod: array<string, int>, unpaidCents: int, unpaidCount: int}
     */
    public function summary(int $season, ?array $entries = null): array
    {
        $entries ??= $this->entries($season);

        $byMethod = array_fill_keys(array_map(static fn (PaymentMethod $m) => $m->value, PaymentMethod::cases()), 0);
        $totals = ['cotisationsCents' => 0, 'passagesCents' => 0, 'passages' => 0, 'unpaidCents' => 0, 'unpaidCount' => 0];

        foreach ($entries as $entry) {
            if ('passage' === $entry['kind']) {
                ++$totals['passages'];
            }

            if (!$entry['paid']) {
                // Une cotisation gratuite — moins de huit ans, formule
                // consommateur — n'est pas une creance : il n'y a rien a
                // reclamer, et la faire figurer en impaye ferait chercher un
                // reglement qui n'existe pas.
                if ($entry['cents'] > 0) {
                    $totals['unpaidCents'] += $entry['cents'];
                    ++$totals['unpaidCount'];
                }

                continue;
            }

            $totals['passage' === $entry['kind'] ? 'passagesCents' : 'cotisationsCents'] += $entry['cents'];

            if (null !== $entry['method']) {
                $byMethod[$entry['method']->value] += $entry['cents'];
            }
        }

        return [
            'cotisationsCents' => $totals['cotisationsCents'],
            'passagesCents' => $totals['passagesCents'],
            'totalCents' => $totals['cotisationsCents'] + $totals['passagesCents'],
            'passages' => $totals['passages'],
            'byMethod' => $byMethod,
            'unpaidCents' => $totals['unpaidCents'],
            'unpaidCount' => $totals['unpaidCount'],
        ];
    }

    /**
     * Ce qui reste a encaisser : un bulletin valide dont le reglement n'a
     * jamais ete saisi.
     *
     * @param list<array{id: int, cashedOn: \DateTimeImmutable|null, date: \DateTimeImmutable, kind: string, label: string,
     *     person: string, number: string|null, method: PaymentMethod|null,
     *     cents: int, creditCents: int, paid: bool}>|null $entries
     *
     * @return list<array{id: int, cashedOn: \DateTimeImmutable|null, date: \DateTimeImmutable, kind: string, label: string,
     *     person: string, number: string|null, method: PaymentMethod|null,
     *     cents: int, creditCents: int, paid: bool}>
     */
    public function unpaid(int $season, ?array $entries = null): array
    {
        $entries ??= $this->entries($season);

        return array_values(array_filter(
            $entries,
            static fn (array $entry) => !$entry['paid'] && $entry['cents'] > 0,
        ));
    }

    /**
     * Les bulletins valides de la saison qui portent une cotisation — la
     * formule consommateur n'en porte pas, elle paie a la seance.
     *
     * @return list<MembershipApplication>
     */
    private function cotisations(int $season): array
    {
        $rows = array_filter(
            $this->applications->findFiltered(['season' => $season], limit: 10000),
            static fn (MembershipApplication $a) => $a->isValidated() && MembershipType::Consumer !== $a->getMembershipType(),
        );

        $rows = array_values($rows);
        $this->applications->preloadAttendances($rows);

        return $rows;
    }

    private function nameOf(MembershipApplication $application): string
    {
        return trim($application->getFirstName().' '.$application->getLastName());
    }
}
