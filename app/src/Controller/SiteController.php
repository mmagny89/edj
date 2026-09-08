<?php

namespace App\Controller;

use App\Entity\Event;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SiteController extends AbstractController
{
    #[Route('/', name: 'site_index')]
    public function index(EventRepository $events): Response
    {
        return $this->render('site/index.html.twig', [
            'events' => $this->serializeEvents($events->findUpcoming()),
        ]);
    }

    /**
     * Les evenements sont remis au front dans la forme qu'attend le
     * carrousel de assets/site.js, qui les fusionne avec les mardis et les
     * 3e samedis qu'il calcule lui-meme.
     *
     * Un evenement sur plusieurs jours est deplie en une entree par jour :
     * le carrousel range par date, et un week-end du jeu doit apparaitre le
     * samedi comme le dimanche plutot qu'une seule fois.
     *
     * @param list<Event> $events
     *
     * @return list<array{date: string, type: string, title: string, time: string, description: string}>
     */
    private function serializeEvents(array $events): array
    {
        $serialized = [];

        foreach ($events as $event) {
            $day = $event->getStartsAt();
            $last = $event->lastDay();

            if (null === $day || null === $last) {
                continue;
            }

            while ($day <= $last) {
                $serialized[] = [
                    'date' => $day->format('Y-m-d'),
                    'type' => $event->getType()?->value ?? 'special',
                    'title' => (string) $event->getTitle(),
                    'time' => (string) $event->getTimeLabel(),
                    'description' => trim(implode(' ', array_filter([
                        $event->getDescription(),
                        $event->getLocation(),
                    ]))),
                ];

                $day = $day->modify('+1 day');
            }
        }

        return $serialized;
    }
}
