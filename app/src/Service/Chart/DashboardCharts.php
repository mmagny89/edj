<?php

namespace App\Service\Chart;

use App\Enum\MembershipType;
use App\Service\MembershipStatistics;

/**
 * Prepare les graphiques du tableau de bord : toute la geometrie se calcule ici,
 * les gabarits ne font que la dessiner.
 *
 * Les graphiques sont des SVG dont toutes les coordonnees sont des pourcentages
 * (« 37% »), jamais des pixels : ils s'adaptent a la largeur du conteneur sans
 * viewBox, donc sans que le texte et les traits se deforment. Seule la hauteur du
 * trace est fixe (PLOT_PX) — c'est elle qui permet de convertir en pourcentage
 * les quelques valeurs que la maquette donne en pixels : l'ecart entre deux
 * segments empiles, le debord sous la ligne de base.
 *
 * Pas un seul style en ligne : la politique de contenu n'autorise les styles que
 * par nonce. Les couleurs sont des classes, les positions des attributs SVG.
 */
final class DashboardCharts
{
    /** Hauteur du trace d'une colonne ou d'une courbe, en pixels (classe h-44). */
    public const PLOT_PX = 176;

    /** Ecart entre deux segments empiles, en pixels. */
    private const GAP_PX = 2;

    /** Part de la hauteur qu'une barre ne depasse pas : le reste porte sa valeur. */
    private const HEADROOM = 0.86;

    private const MONTHS = ['oct.', 'nov.', 'déc.', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.'];

    /**
     * L'evolution des saisons : une colonne par saison, empilee en nouveaux,
     * renouvellements et consommateurs. Cliquer une saison l'ouvre.
     *
     * @param list<array{season: int, newcomers: int, renewals: int, consumers: int, active: int}> $trend
     *
     * @return array<string, mixed>
     */
    public function seasonTrend(array $trend, int $selected, string $baseUrl): array
    {
        $series = [
            ['key' => 'newcomers', 'label' => 'Nouveaux', 'class' => 'viz-s1'],
            ['key' => 'renewals', 'label' => 'Renouvellements', 'class' => 'viz-s2'],
            ['key' => 'consumers', 'label' => 'Consommateurs', 'class' => 'viz-s3'],
        ];

        $columns = [];
        foreach ($trend as $row) {
            $columns[] = [
                'label' => (string) $row['season'],
                'values' => [$row['newcomers'], $row['renewals'], $row['consumers']],
                'total' => $row['newcomers'] + $row['renewals'] + $row['consumers'],
                'selected' => $row['season'] === $selected,
                'href' => $baseUrl.'?saison='.$row['season'],
                'hint' => sprintf('Voir la saison %d', $row['season']),
            ];
        }

        return $this->columns('trend', $series, $columns);
    }

    /**
     * Les ages : une colonne par tranche, une seule serie.
     *
     * @param array<string, int> $bands
     *
     * @return array<string, mixed>
     */
    public function ageBands(array $bands): array
    {
        $columns = [];
        foreach (MembershipStatistics::AGE_BANDS as $key => $band) {
            $count = $bands[$key] ?? 0;
            $columns[] = ['label' => $band['label'], 'values' => [$count], 'total' => $count, 'selected' => false, 'href' => null, 'hint' => null];
        }

        return $this->columns('ages', [['key' => 'members', 'label' => 'Adhérents', 'class' => 'viz-s1']], $columns);
    }

    /**
     * Cumul mensuel de la saison choisie, contre la precedente en tons neutres :
     * la saison choisie est le sujet, la precedente le contexte.
     *
     * @param list<?int> $current
     * @param list<?int> $previous
     *
     * @return array<string, mixed>
     */
    public function cumulative(int $season, array $current, array $previous): array
    {
        $max = $this->niceMax(max([0, ...array_filter($current, 'is_int'), ...array_filter($previous, 'is_int')]));
        $count = \count(self::MONTHS);

        $build = static function (array $values) use ($max, $count): array {
            $path = '';
            $last = null;
            foreach ($values as $i => $value) {
                if (null === $value) {
                    continue;
                }
                $x = round(($i + 0.5) / $count * 100, 3);
                $y = round(100 - $value / $max * 100, 3);
                $path .= ('' === $path ? 'M' : 'L').$x.' '.$y.' ';
                $last = ['x' => $x, 'y' => $y, 'value' => $value];
            }

            return ['path' => trim($path), 'end' => $last];
        };

        $hits = [];
        foreach (self::MONTHS as $i => $label) {
            $x = round(($i + 0.5) / $count * 100, 3);
            $rows = [];
            if (null !== ($current[$i] ?? null)) {
                $rows[] = ['label' => 'Saison '.$season, 'value' => $current[$i], 'class' => 'viz-s1'];
            }
            if (null !== ($previous[$i] ?? null)) {
                $rows[] = ['label' => 'Saison '.($season - 1), 'value' => $previous[$i], 'class' => 'viz-muted'];
            }
            $hits[] = ['x' => $x, 'start' => round($i / $count * 100, 3), 'width' => round(100 / $count, 3), 'label' => $label, 'title' => ucfirst($label), 'rows' => $rows];
        }

        return [
            'id' => 'cumulative',
            'ticks' => $this->ticks($max),
            'months' => self::MONTHS,
            'series' => [
                ['label' => 'Saison '.$season, 'class' => 'viz-s1', 'line' => $build($current)],
                ['label' => 'Saison '.($season - 1), 'class' => 'viz-muted', 'line' => $build($previous)],
            ],
            'hits' => $hits,
            'table' => ['head' => ['Mois', 'Saison '.$season, 'Saison '.($season - 1)], 'rows' => array_map(
                static fn (int $i) => [self::MONTHS[$i], $current[$i] ?? '—', $previous[$i] ?? '—'],
                array_keys(self::MONTHS),
            )],
        ];
    }

    /**
     * Des barres horizontales : un libelle, une barre, une valeur. Pour les
     * categories aux noms longs, et pour tout ce qui se range par taille.
     *
     * @param list<array{label: string, value: int}> $rows
     *
     * @return array<string, mixed>
     */
    public function bars(string $id, array $rows, ?int $total = null, string $class = 'viz-s1'): array
    {
        $max = max([1, ...array_column($rows, 'value')]);
        $total ??= array_sum(array_column($rows, 'value'));

        $out = [];
        foreach ($rows as $row) {
            $share = $total > 0 ? (int) round($row['value'] / $total * 100) : 0;
            $out[] = [
                'label' => $row['label'],
                'value' => $row['value'],
                'width' => round($row['value'] / $max * 100, 2),
                'share' => $share,
                'class' => $class,
                'tip' => ['title' => $row['label'], 'rows' => [['label' => $total > 0 ? sprintf('%d %% du total', $share) : '', 'value' => $row['value'], 'class' => $class]]],
            ];
        }

        return ['id' => $id, 'rows' => $out, 'total' => $total];
    }

    /**
     * Un rapport a un maximum — « 12 photos refusees sur 80 » : la part remplie,
     * sur une piste plus claire de la meme teinte.
     *
     * @return array{label: string, value: int, of: int, width: float}
     */
    public function meter(string $label, int $value, int $of): array
    {
        return ['label' => $label, 'value' => $value, 'of' => $of, 'width' => $of > 0 ? round($value / $of * 100, 2) : 0.0];
    }

    /**
     * Libelles de formule pour le graphique des formules.
     *
     * @param array<string, int> $byType
     *
     * @return list<array{label: string, value: int}>
     */
    public function typeRows(array $byType): array
    {
        $rows = [];
        foreach (MembershipType::cases() as $type) {
            $rows[] = ['label' => $type->label(), 'value' => $byType[$type->value] ?? 0];
        }

        return $rows;
    }

    // ------------------------------------------------------------------

    /**
     * @param list<array{key: string, label: string, class: string}>                                                                          $series
     * @param list<array{label: string, values: list<int>, total: int, selected: bool, href: ?string, hint: ?string}> $columns
     *
     * @return array<string, mixed>
     */
    private function columns(string $id, array $series, array $columns): array
    {
        $max = $this->niceMax((int) ceil(max([1, ...array_column($columns, 'total')]) / self::HEADROOM));
        $gap = self::GAP_PX / self::PLOT_PX * 100;
        // Debord sous la ligne de base : l'arrondi de la premiere barre sort du
        // trace, qui le rogne — la base reste droite, seul le sommet est arrondi.
        $below = 3.0;

        $built = [];
        foreach ($columns as $column) {
            $segments = [];
            $running = 0;
            $visible = [];
            foreach ($column['values'] as $k => $value) {
                if ($value > 0) {
                    $visible[] = $k;
                }
            }
            $top = null === ($lastKey = array_key_last($visible)) ? null : $visible[$lastKey];

            foreach ($column['values'] as $k => $value) {
                $bottomPct = $running / $max * 100;
                $running += $value;
                if ($value <= 0) {
                    continue;
                }
                $topPct = $running / $max * 100;
                $edge = $bottomPct > 0 ? $bottomPct + $gap : -$below;
                $height = max($topPct - $edge, 1.5);
                $y = 100 - $topPct;
                $isTop = $k === $top;
                $segments[] = [
                    'class' => $series[$k]['class'],
                    'y' => round($y, 3),
                    'height' => round($height, 3),
                    'round' => $isTop,
                    // Moitie basse carree du segment le plus haut : elle recouvre
                    // les coins inferieurs arrondis, qui feraient une encoche
                    // contre le segment du dessous.
                    'squareY' => round($y + $height / 2, 3),
                    'squareHeight' => round($height / 2, 3),
                ];
            }

            $rows = [];
            foreach ($series as $k => $s) {
                $rows[] = ['label' => $s['label'], 'value' => $column['values'][$k] ?? 0, 'class' => $s['class']];
            }

            $built[] = $column + [
                'segments' => $segments,
                'labelY' => round(100 - ($column['total'] / $max * 100), 3),
                'tip' => ['title' => $column['label'], 'rows' => 1 === \count($series) ? [['label' => $series[0]['label'], 'value' => $column['total'], 'class' => $series[0]['class']]] : [...$rows, ['label' => 'Total', 'value' => $column['total'], 'class' => '']]],
            ];
        }

        return [
            'id' => $id,
            'series' => $series,
            'columns' => $built,
            'ticks' => $this->ticks($max),
            'table' => [
                'head' => ['', ...array_column($series, 'label'), ...(\count($series) > 1 ? ['Total'] : [])],
                'rows' => array_map(static fn (array $c) => [$c['label'], ...$c['values'], ...(\count($series) > 1 ? [$c['total']] : [])], $columns),
            ],
        ];
    }

    /**
     * Trois graduations — zero, moitie, maximum — du haut vers le bas : le
     * maximum est pair, la moitie tombe donc toujours juste.
     *
     * @return list<array{value: int}>
     */
    private function ticks(int $max): array
    {
        return [['value' => $max], ['value' => intdiv($max, 2)], ['value' => 0]];
    }

    /**
     * Le plus petit maximum « rond » et pair qui contient la valeur : 4, 6, 8, 10,
     * 20, 30, 40, 50, 60, 80, 100, 200...
     */
    private function niceMax(int $value): int
    {
        if ($value <= 4) {
            return 4;
        }

        $magnitude = 10 ** (int) floor(log10($value));
        foreach ([1, 2, 3, 4, 5, 6, 8, 10] as $step) {
            $candidate = $step * $magnitude;
            if ($candidate >= $value && 0 === $candidate % 2) {
                return (int) $candidate;
            }
        }

        return (int) (10 * $magnitude);
    }
}
