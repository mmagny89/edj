<?php

namespace App\Controller\app;

use App\Service\Accounting;
use App\Service\MembershipStatistics;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La section comptable : ce qui est entre en caisse sur une saison, d'ou ca
 * vient, et ce qui reste a encaisser.
 *
 * Elle ne tient pas la comptabilite de l'association — elle lui donne la
 * matiere : le detail des cotisations et des euros de seance, deja totalise
 * par moyen de reglement, et le meme detail en CSV pour le tableur ou se
 * fait le reste.
 */
#[Route('/admin/comptabilite')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class AccountingController extends AbstractController
{
    public function __construct(
        private readonly Accounting $accounting,
        private readonly MembershipStatistics $statistics,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_accounting', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $season = $this->season($request);
        $entries = $this->accounting->entries($season);

        return $this->render('app/accounting/index.html.twig', [
            'seasons' => $this->statistics->availableSeasons(),
            'season' => $season,
            'entries' => $entries,
            'summary' => $this->accounting->summary($season, $entries),
            'unpaid' => $this->accounting->unpaid($season, $entries),
        ]);
    }

    /**
     * Le meme journal, en CSV.
     *
     * Separateur point-virgule et BOM UTF-8 : c'est ce qu'attend un tableur
     * francophone, qui sans eux ouvre tout sur une colonne et mange les
     * accents. Montants en euros avec une virgule decimale, pour la meme
     * raison.
     */
    #[Route('/export.csv', name: 'app_accounting_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $season = $this->season($request);
        $entries = $this->accounting->entries($season);

        $this->securityLogger->info('Export comptable', [
            'event' => 'accounting_export',
            'user' => $this->getUser()?->getUserIdentifier(),
            'season' => $season,
            'lines' => \count($entries),
        ]);

        $response = new StreamedResponse(static function () use ($entries): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Date', 'Nature', 'Libellé', 'Personne', 'Numéro', 'Règlement', 'Montant (€)', 'Encaissé'], ';', '"', '');

            foreach ($entries as $entry) {
                fputcsv($out, [
                    $entry['date']->format('d/m/Y'),
                    'passage' === $entry['kind'] ? 'Passage' : 'Cotisation',
                    $entry['label'],
                    $entry['person'],
                    $entry['number'] ?? '',
                    $entry['method']?->label() ?? '',
                    number_format($entry['cents'] / 100, 2, ',', ''),
                    $entry['paid'] ? 'oui' : 'non',
                ], ';', '"', '');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('comptabilite-%d.csv', $season),
        ));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function season(Request $request): int
    {
        $seasons = $this->statistics->availableSeasons();
        $season = $request->query->getInt('saison') ?: $this->statistics->currentSeason();

        return \in_array($season, $seasons, true) ? $season : $this->statistics->currentSeason();
    }
}
