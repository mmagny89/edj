<?php

namespace App\Controller\app;

use App\Entity\Event;
use App\Entity\Game;
use App\Form\AddGameType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;

final class AppController extends AbstractController
{
    public function __construct(private LoggerInterface $logger, private EntityManagerInterface $entityManager)
    {
    }

    #[Route('/app', name: 'app_index')]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function index(): Response
    {
        return $this->render('app/index.html.twig');
    }

    #[Route('/app/events', name: 'app_events')]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function events(): Response
    {
        return $this->render('app/events/index.html.twig', [
            'events' => $this->entityManager->getRepository(Event::class)->findAll()
        ]);
    }

    #[Route('/app/events/{event}', name: 'app_events_details')]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function details(Event $event, Request $request): Response
    {
        $form = $this->createForm(AddGameType::class, new Game());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $gameData = $form->getData();
            $bggId = $gameData->getBggId();

            // Vérifier si le jeu existe déjà dans la base
            $existingGame = $this->entityManager->getRepository(Game::class)->findOneBy(['bggId' => $bggId]);
            if ($existingGame) {
                $this->logger->info('Jeu existant trouvé dans la base, association à l\'événement', ['gameId' => $bggId]);
                $event->addGame($existingGame);
            } else {
                $this->logger->info('Nouveau jeu, création dans la base', ['gameId' => $bggId]);
                $this->entityManager->persist($gameData);
                $event->addGame($gameData);
            }

            $this->entityManager->persist($event);
            $this->entityManager->flush();

            return $this->redirectToRoute('app_events_details', ['event' => $event->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('app/events/details.html.twig', [
            'event' => $event,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/app/events/{event}/remove-game/{game}', name: 'app_events_remove_game', methods: ['POST'])]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function removeGame(Event $event, Game $game, Request $request): Response
    {
        if ($this->isCsrfTokenValid('remove_game_' . $game->getId(), $request->request->get('_token'))) {
            $event->removeGame($game);
            $this->entityManager->persist($event);
            $this->entityManager->flush();
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        return $this->redirectToRoute('app_events_details', ['event' => $event->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/app/games/search', name: 'app_games_search', methods: ['GET'])]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function searchGames(Request $request, SerializerInterface $serializer): JsonResponse
    {
        $query = $request->query->get('query');
        if (!$query) {
            return new JsonResponse([]);
        }

        $client = new \GuzzleHttp\Client();
        try {
            // Recherche initiale pour obtenir les ID et noms des jeux
            $response = $client->get('https://www.boardgamegeek.com/xmlapi2/search', [
                'query' => ['query' => $query, 'type' => 'boardgame'],
                'timeout' => 5,
            ]);

            $xml = simplexml_load_string($response->getBody()->getContents());
            if ($xml === false) {
                $this->logger->error('Erreur lors du parsing XML de la recherche BGG', ['query' => $query]);
                return new JsonResponse([]);
            }

            $games = [];
            foreach ($xml->item as $item) {
                $name = isset($item->name['value']) ? (string) $item->name['value'] : '';
                if ($name) {
                    $games[] = [
                        'id' => (string) $item['id'],
                        'name' => $name,
                    ];
                }
            }

            // Trier par ordre alphabétique sur le nom
            usort($games, fn($a, $b) => strcmp($a['name'], $b['name']));

            return new JsonResponse($games);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la recherche BGG: ' . $e->getMessage(), [
                'query' => $query,
                'exception' => $e,
            ]);
            return new JsonResponse([], 500);
        }
    }

    #[Route('/app/games/{id}/details', name: 'app_games_details', methods: ['GET'])]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function getGameDetails(string $id, SerializerInterface $serializer): JsonResponse
    {
        // Vérifier si le jeu existe dans la base de données
        $game = $this->entityManager->getRepository(Game::class)->findOneBy(['bggId' => $id]);
        if ($game) {
            $this->logger->info('Jeu trouvé dans la base de données', ['gameId' => $id]);
            return new JsonResponse([
                'id' => $id,
                'name' => $game->getName(),
                'image' => $game->getImageUrl() ?? '',
            ]);
        }

        // Si le jeu n'est pas en base, interroger l'API BGG
        $client = new \GuzzleHttp\Client();
        try {
            $response = $client->get("https://www.boardgamegeek.com/xmlapi/boardgame/{$id}", [
                'timeout' => 5,
            ]);

            $xml = simplexml_load_string($response->getBody()->getContents());
            if ($xml === false) {
                $this->logger->error('Erreur lors du parsing XML pour le jeu', ['gameId' => $id]);
                return new JsonResponse([], 404);
            }

            $name = isset($xml->boardgame->name[0]) ? (string) $xml->boardgame->name[0] : '';
            $image = isset($xml->boardgame->image) ? (string) $xml->boardgame->image : '';

            if (!$name) {
                $this->logger->warning('Aucun nom trouvé pour le jeu', ['gameId' => $id]);
                return new JsonResponse([], 404);
            }

            return new JsonResponse([
                'id' => $id,
                'name' => $name,
                'image' => $image,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération des détails du jeu: ' . $e->getMessage(), [
                'gameId' => $id,
                'exception' => $e,
            ]);
            return new JsonResponse([], 500);
        }
    }
}
