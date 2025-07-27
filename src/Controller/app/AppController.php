<?php

namespace App\Controller\app;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AppController extends AbstractController
{
    #[Route('/app', name: 'app_index')]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function index(): Response
    {
        return $this->render('app/index.html.twig');
    }

    #[Route('/app/events', name: 'app_events')]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function events(EntityManagerInterface $entityManager): Response
    {
        return $this->render('app/events/index.html.twig', [
            'events' => $entityManager->getRepository(Event::class)->findAll()
        ]);
    }

    #[Route('/app/events/{event}', name: 'app_events_details')]
    #[IsGranted('ROLE_CONTRIBUTOR')]
    public function details(Event $event): Response
    {
        return $this->render('app/events/details.html.twig', [
            'event' => $event,
        ]);
    }
}
