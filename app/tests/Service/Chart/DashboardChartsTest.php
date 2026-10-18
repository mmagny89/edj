<?php

namespace App\Tests\Service\Chart;

use App\Service\Chart\DashboardCharts;
use PHPUnit\Framework\TestCase;

/**
 * Geometrie des graphiques du tableau de bord.
 *
 * Tout se calcule en PHP : ce qu'on verifie ici, ce sont les regles qui font un
 * graphique juste — une echelle qui contient les donnees, un empilement qui ne
 * triche pas sur les hauteurs, aucune barre invisible, aucune valeur inventee.
 */
final class DashboardChartsTest extends TestCase
{
    private DashboardCharts $charts;

    protected function setUp(): void
    {
        $this->charts = new DashboardCharts();
    }

    /**
     * @return list<array{season: int, newcomers: int, renewals: int, consumers: int, active: int}>
     */
    private function trend(): array
    {
        return [
            ['season' => 2025, 'newcomers' => 10, 'renewals' => 0, 'consumers' => 2, 'active' => 10],
            ['season' => 2026, 'newcomers' => 6, 'renewals' => 8, 'consumers' => 4, 'active' => 14],
            ['season' => 2027, 'newcomers' => 0, 'renewals' => 0, 'consumers' => 0, 'active' => 0],
        ];
    }

    public function testTheScaleContainsTheTallestColumnAndKeepsHeadroom(): void
    {
        $chart = $this->charts->seasonTrend($this->trend(), 2026, '/admin');

        // La plus haute colonne vaut 18 : l'echelle monte plus haut, pour que sa
        // valeur ecrite au-dessus ne sorte pas du trace.
        $max = $chart['ticks'][0]['value'];
        self::assertGreaterThan(18, $max);
        self::assertSame(0, $max % 2, 'Un maximum pair : la graduation du milieu tombe juste.');
        self::assertSame([$max, intdiv($max, 2), 0], array_column($chart['ticks'], 'value'));
    }

    public function testASegmentIsAsTallAsItsValue(): void
    {
        $chart = $this->charts->seasonTrend($this->trend(), 2026, '/admin');
        $max = $chart['ticks'][0]['value'];
        // Une seule serie non nulle : un seul segment, de la hauteur de son total.
        $column = $chart['columns'][0];
        $nonZero = array_values(array_filter($column['segments'], static fn (array $s) => $s['height'] > 0));

        $newcomers = $nonZero[0];
        $expectedHeight = 10 / $max * 100;
        // Le premier segment depasse sous la ligne de base (rognee) : il est plus
        // haut que sa valeur de ce debord, jamais moins.
        self::assertEqualsWithDelta($expectedHeight + 3.0, $newcomers['height'], 0.01);
        self::assertEqualsWithDelta(100 - $expectedHeight, $newcomers['y'], 0.01);
    }

    /**
     * Un segment de valeur nulle ne se dessine pas : une barre de hauteur zero
     * laisserait un trait fantome, et un arrondi sans corps.
     */
    public function testAZeroValueDrawsNothing(): void
    {
        $chart = $this->charts->seasonTrend($this->trend(), 2026, '/admin');

        self::assertSame([], $chart['columns'][2]['segments']);
        // 2025 : nouveaux (10) et consommateurs (2), pas de renouvellements.
        self::assertCount(2, $chart['columns'][0]['segments']);
    }

    /**
     * Seul le haut de la pile est arrondi : les segments du dessous restent
     * carres, sans quoi la pile serait denticulee.
     */
    public function testOnlyTheTopSegmentIsRounded(): void
    {
        $chart = $this->charts->seasonTrend($this->trend(), 2026, '/admin');

        $segments = $chart['columns'][1]['segments'];

        self::assertCount(3, $segments);
        self::assertSame([false, false, true], array_column($segments, 'round'));
    }

    public function testStackedSegmentsAreSeparatedByAGap(): void
    {
        $chart = $this->charts->seasonTrend($this->trend(), 2026, '/admin');
        $segments = $chart['columns'][1]['segments'];

        // Les ordonnees SVG descendent : le bas du segment du dessus est au-dessus
        // du haut du segment du dessous — d'un ecart de deux pixels, ni contact ni
        // chevauchement.
        $bottomOfUpper = $segments[1]['y'] + $segments[1]['height'];
        self::assertLessThan($segments[0]['y'], $bottomOfUpper, 'Les segments se touchent ou se chevauchent.');
        self::assertEqualsWithDelta(2 / DashboardCharts::PLOT_PX * 100, $segments[0]['y'] - $bottomOfUpper, 0.01);
    }

    public function testTheSelectedSeasonIsMarkedAndEveryColumnLinksToItsSeason(): void
    {
        $chart = $this->charts->seasonTrend($this->trend(), 2026, '/admin');

        self::assertSame([false, true, false], array_column($chart['columns'], 'selected'));
        self::assertSame('/admin?saison=2025', $chart['columns'][0]['href']);
        self::assertSame('/admin?saison=2027', $chart['columns'][2]['href']);
    }

    /**
     * L'infobulle donne chaque serie et le total : tout ce qu'elle montre se lit
     * aussi dans la vue en tableau.
     */
    public function testTheTooltipAndTheTableCarryTheSameNumbers(): void
    {
        $chart = $this->charts->seasonTrend($this->trend(), 2026, '/admin');
        $column = $chart['columns'][1];

        self::assertSame('2026', $column['tip']['title']);
        self::assertSame([6, 8, 4, 18], array_column($column['tip']['rows'], 'value'));
        self::assertSame(['2026', 6, 8, 4, 18], $chart['table']['rows'][1]);
        self::assertSame(['', 'Nouveaux', 'Renouvellements', 'Consommateurs', 'Total'], $chart['table']['head']);
    }

    public function testASingleSeriesChartHasNoTotalColumn(): void
    {
        $chart = $this->charts->ageBands(['under8' => 1, 'minor' => 2, 'young' => 3, 'adult' => 4, 'mature' => 5, 'senior' => 6]);

        self::assertCount(6, $chart['columns']);
        self::assertSame(['', 'Adhérents'], $chart['table']['head']);
        self::assertSame(['0–7 ans', 1], $chart['table']['rows'][0]);
    }

    public function testHorizontalBarsAreScaledToTheLongestOne(): void
    {
        $chart = $this->charts->bars('x', [['label' => 'A', 'value' => 40], ['label' => 'B', 'value' => 10], ['label' => 'C', 'value' => 0]]);

        self::assertSame([100.0, 25.0, 0.0], array_column($chart['rows'], 'width'));
        self::assertSame([80, 20, 0], array_column($chart['rows'], 'share'));
    }

    public function testBarsWithNothingToShowDoNotDivideByZero(): void
    {
        $chart = $this->charts->bars('x', [['label' => 'A', 'value' => 0]]);

        self::assertSame(0.0, $chart['rows'][0]['width']);
        self::assertSame(0, $chart['rows'][0]['share']);
    }

    /**
     * Les mois a venir de la saison en cours n'ont pas de valeur : la courbe
     * s'arrete, elle ne retombe pas a zero ni ne reste plate.
     */
    public function testTheCurveStopsWhereTheDataStops(): void
    {
        $current = [3, 5, null, null, null, null, null, null, null, null, null, null];
        $previous = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

        $chart = $this->charts->cumulative(2027, $current, $previous);

        self::assertSame(2, substr_count($chart['series'][0]['line']['path'], 'L') + 1, 'Deux points seulement.');
        self::assertSame(12, substr_count($chart['series'][1]['line']['path'], 'L') + 1);
        self::assertSame(5, $chart['series'][0]['line']['end']['value']);
    }

    public function testEachMonthHasAHoverZoneTellingBothSeasons(): void
    {
        $chart = $this->charts->cumulative(2027, [3, null, null, null, null, null, null, null, null, null, null, null], array_fill(0, 12, 1));

        self::assertCount(12, $chart['hits']);
        self::assertSame(['Saison 2027', 'Saison 2026'], array_column($chart['hits'][0]['rows'], 'label'));
        // Les mois sans valeur pour la saison en cours n'inventent pas de ligne.
        self::assertSame(['Saison 2026'], array_column($chart['hits'][5]['rows'], 'label'));
    }

    public function testAMeterFillsInProportion(): void
    {
        self::assertSame(25.0, $this->charts->meter('x', 5, 20)['width']);
        self::assertSame(0.0, $this->charts->meter('x', 0, 0)['width']);
    }
}
