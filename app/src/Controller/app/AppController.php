<?php

namespace App\Controller\app;

use App\Enum\MembershipStatus;
use App\Repository\EventRepository;
use App\Repository\MembershipApplicationRepository;
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
    ): Response {
        $seasons = $statistics->availableSeasons();
        $season = $request->query->getInt('saison') ?: $statistics->currentSeason();
        if (!\in_array($season, $seasons, true)) {
            $season = $statistics->currentSeason();
        }

        return $this->render('app/index.html.twig', [
            'upcoming' => \array_slice($events->findUpcoming(), 0, 5),
            'applicationCount' => $applications->count([]),
            'pendingCount' => $applications->count(['status' => MembershipStatus::Pending]),
            'stats' => $statistics->forSeason($season),
            'seasons' => $seasons,
            'season' => $season,
        ]);
    }
}
