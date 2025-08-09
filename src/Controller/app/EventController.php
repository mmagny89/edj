<?php

namespace App\Controller\app;

use App\Entity\Event;
use App\Entity\Game;
use App\Form\AddGameType;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class EventController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface        $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $eventRepository
    ) {
    }

    #[Route('/events', name: 'app_events', methods: ['GET'])]
    public function events(): Response
    {
        return $this->render('app/events/index.html.twig', [
            'events' => $this->entityManager->getRepository(Event::class)->findBy([], ['date' => 'DESC']),
        ]);
    }

    #[Route('/events/{event}', name: 'app_events_details', methods: ['GET', 'POST'])]
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

        // Récupérer les 5 derniers événements (excluant l'événement actuel)
        $lastEvents = $this->eventRepository->createQueryBuilder('e')
            ->where('e.id != :eventId')
            ->setParameter('eventId', $event->getId())
            ->orderBy('e.date', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return $this->render('app/events/details.html.twig', [
            'event' => $event,
            'form' => $form->createView(),
            'lastEvents' => $lastEvents,
        ]);
    }

    #[Route('/events/{event}/remove-game/{game}', name: 'app_events_remove_game', methods: ['POST'])]
    public function removeGame(Event $event, Game $game, Request $request): Response
    {
        $this->logger->info('Tentative de suppression du jeu', ['eventId' => $event->getId(), 'gameId' => $game->getId()]);
        if ($this->isCsrfTokenValid('remove_game_' . $game->getId(), $request->request->get('_token'))) {
            $event->removeGame($game);
            $this->entityManager->persist($event);
            $this->entityManager->flush();
            $this->logger->info('Jeu supprimé avec succès', ['eventId' => $event->getId(), 'gameId' => $game->getId()]);
        } else {
            $this->logger->warning('Token CSRF invalide pour suppression', ['gameId' => $game->getId()]);
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'error' => 'Token CSRF invalide'], 403);
            }
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        return $this->redirectToRoute('app_events_details', ['event' => $event->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/events/{event}/add-game/{game}', name: 'app_events_add_game', methods: ['POST'])]
    public function addGame(Event $event, Game $game, Request $request): Response
    {
        $this->logger->info('Tentative d\'ajout du jeu', ['eventId' => $event->getId(), 'gameId' => $game->getId()]);
        if ($this->isCsrfTokenValid('add_game_' . $game->getId(), $request->request->get('_token'))) {
            if (!$event->getGames()->contains($game)) {
                $event->addGame($game);
                $this->entityManager->persist($event);
                $this->entityManager->flush();
                $this->logger->info('Jeu ajouté avec succès', ['eventId' => $event->getId(), 'gameId' => $game->getId()]);
            } else {
                $this->logger->info('Jeu déjà présent dans l\'événement', ['eventId' => $event->getId(), 'gameId' => $game->getId()]);
            }
        } else {
            $this->logger->warning('Token CSRF invalide pour ajout', ['gameId' => $game->getId(), 'token' => $request->request->get('_token')]);
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'error' => 'Token CSRF invalide'], 403);
            }
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        return $this->redirectToRoute('app_events_details', ['event' => $event->getId()], Response::HTTP_SEE_OTHER);
    }
}
