<?php

namespace App\Controller\app;

use App\Enum\MembershipStatus;
use App\Repository\EventRepository;
use App\Repository\MembershipApplicationRepository;
use App\Service\Chart\DashboardCharts;
use App\Service\MembershipStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class AppController extends AbstractController
{
    /**
     * La saison regardee passe par l'URL (GET) : un tableau de bord filtre se
     * partage alors par son lien, et un rechargement ne change pas ce qu'on
     * regarde. Par defaut, la saison en cours.
     */
    #[Route('', name: 'app_index', methods: ['GET'])]
    public function index(
        Request $request,
        EventRepository $events,
        MembershipApplicationRepository $applications,
        MembershipStatistics $statistics,
        DashboardCharts $charts,
    ): Response {
        $seasons = $statistics->availableSeasons();
        $season = $request->query->getInt('saison') ?: $statistics->currentSeason();
        if (!\in_array($season, $seasons, true)) {
            $season = $statistics->currentSeason();
        }

        $stats = $statistics->forSeason($season);
        $trend = $statistics->trend();
        $previous = null;
        foreach ($trend as $row) {
            if ($row['season'] === $season - 1) {
                $previous = $row['active'];
            }
        }

        $people = $stats['active'] + $stats['consumers'];
        $geography = $stats['geography'];

        return $this->render('app/index.html.twig', [
            'upcoming' => \array_slice($events->findUpcoming(), 0, 5),
            'applicationCount' => $applications->count([]),
            'pendingCount' => $applications->count(['status' => MembershipStatus::Pending]),
            'stats' => $stats,
            // Pour le chiffre en vedette : la saison choisie contre la precedente,
            // lue sur la saison entiere. Nul s'il n'y a pas de precedente.
            'previousActive' => $previous,
            'charts' => [
                'trend' => $charts->seasonTrend($trend, $season, $this->generateUrl('app_index')),
                'cumulative' => $charts->cumulative($season, $statistics->cumulative($season), $statistics->cumulative($season - 1)),
                'ages' => $charts->ageBands($stats['ageBands']),
                'geography' => $charts->bars('geography', [
                    ['label' => 'Joigny', 'value' => $geography['joigny']],
                    ['label' => 'Communauté de communes', 'value' => $geography['intercommunality']],
                    ['label' => 'Hors territoire', 'value' => $geography['outside']],
                    ['label' => 'Non renseigné', 'value' => $geography['unknown']],
                ]),
                'types' => $charts->bars('types', $charts->typeRows($stats['byType'])),
                'payments' => $charts->bars('payments', [
                    ['label' => 'Chèque', 'value' => $stats['payments']['cheque']],
                    ['label' => 'Espèces', 'value' => $stats['payments']['especes']],
                    ['label' => 'Virement', 'value' => $stats['payments']['virement']],
                    ['label' => 'Gratuit', 'value' => $stats['payments']['gratuit']],
                ]),
                'photos' => $charts->meter('Photos refusées', $stats['imageRightsRefused'], $people),
                'philibert' => $charts->meter('E-mail transmis à Philibert', $stats['philibertConsent'], $stats['active']),
            ],
            'seasons' => $seasons,
            'season' => $season,
            'currentSeason' => $statistics->currentSeason(),
        ]);
    }
}
