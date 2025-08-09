<?php

namespace App\Controller\app;

use App\Entity\Game;
use App\Service\BggApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class GameController extends AbstractController
{
    public function __construct(
        private BggApiService $bggApiService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/games/search', name: 'app_games_search', methods: ['GET'])]
    public function searchGames(Request $request): JsonResponse
    {
        $query = $request->query->get('query');
        if (!$query) {
            return new JsonResponse([]);
        }

        $games = $this->bggApiService->searchGames($query);
        return new JsonResponse($games);
    }

    #[Route('/games/{id}/details', name: 'app_games_details', methods: ['GET'])]
    public function getGameDetails(string $id): JsonResponse
    {
        // Vérifier si le jeu existe dans la base de données
        $game = $this->entityManager->getRepository(Game::class)->findOneBy(['bggId' => $id]);
        if ($game && $game->getImageUrl()) {
            return new JsonResponse([
                'id' => $id,
                'name' => $game->getName(),
                'image' => $game->getImageUrl(),
            ]);
        }

        // Si le jeu n'existe pas ou si l'image est manquante, interroger l'API BGG
        $details = $this->bggApiService->getGameDetails($id);
        if ($details === null) {
            return new JsonResponse(['error' => 'Jeu non trouvé ou erreur API'], 404);
        }

        // Si le jeu existe mais n'a pas d'image, mettre à jour l'entité
        if ($game && !$game->getImageUrl() && !empty($details['image'])) {
            $game->setImageUrl($details['image']);
            $this->entityManager->persist($game);
            $this->entityManager->flush();
        }

        return new JsonResponse($details);
    }
}
