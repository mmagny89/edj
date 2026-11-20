<?php

namespace App\Controller;

use App\Entity\Event;
use App\Enum\EventType;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SiteController extends AbstractController
{
    public function __construct(
        #[Autowire('%app.contact_email%')]
        private readonly string $contactEmail,
        #[Autowire('%app.social.facebook%')]
        private readonly string $facebookUrl,
        #[Autowire('%app.social.instagram%')]
        private readonly string $instagramUrl,
    ) {
    }

    #[Route('/', name: 'site_index')]
    public function index(Request $request, EventRepository $events): Response
    {
        $upcoming = $events->findUpcoming();

        return $this->render('site/index.html.twig', [
            'events' => $this->serializeEvents($upcoming),
            'structuredData' => $this->structuredData($request->getSchemeAndHttpHost(), $upcoming),
        ]);
    }

    /**
     * Donnees structurees schema.org : l'association, puis ses prochains
     * evenements, que les moteurs de recherche peuvent afficher en resultat
     * enrichi.
     *
     * JSON_HEX_TAG echappe « < » et « > » : un titre saisi dans
     * l'administration ne peut pas refermer la balise <script> qui porte ce JSON.
     *
     * @param list<Event> $events
     */
    private function structuredData(string $base, array $events): string
    {
        $organization = [
            '@type' => 'Organization',
            '@id' => $base.'/#association',
            'name' => 'Envie de Jouer',
            'description' => 'Association de jeux de société à Joigny : soirées jeux, après-midi ludiques et Nuits du Jeu, avec les jeux de ses membres.',
            'url' => $base.'/',
            'logo' => $base.'/img/edj-logo-transparent.png',
            'email' => $this->contactEmail,
            'foundingDate' => '2023',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Maison des Associations',
                'postalCode' => '89300',
                'addressLocality' => 'Joigny',
                'addressCountry' => 'FR',
            ],
            'sameAs' => [$this->facebookUrl, $this->instagramUrl],
        ];

        $graph = [$organization];

        // Dix evenements au plus : les moteurs n'en affichent pas davantage, et
        // un soir du mardi par semaine noierait les temps forts.
        foreach (\array_slice($events, 0, 10) as $event) {
            $start = $event->getStartsAt();
            if (null === $start) {
                continue;
            }

            $item = [
                '@type' => 'Event',
                'name' => (string) $event->getTitle(),
                'startDate' => $start->format('Y-m-d'),
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location' => [
                    '@type' => 'Place',
                    'name' => (string) ($event->getLocation() ?: 'Maison des Associations, Joigny'),
                    'address' => (string) ($event->getLocation() ?: 'Maison des Associations, 89300 Joigny'),
                ],
                'organizer' => ['@id' => $base.'/#association'],
                'isAccessibleForFree' => false,
            ];
            if ($event->getEndsAt()) {
                $item['endDate'] = $event->getEndsAt()->format('Y-m-d');
            }
            if ($event->getDescription()) {
                $item['description'] = $event->getDescription();
            }
            $graph[] = $item;
        }

        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Page « en savoir plus » d'un evenement : sa propre page pour une nuit ou
     * un week-end du jeu, la presentation du rendez-vous pour un mardi ou un
     * samedi, rien pour une manifestation.
     */
    private function eventUrl(Event $event): string
    {
        $type = $event->getType();

        return match (true) {
            $type?->hasOwnPage() ?? false => $this->generateUrl('site_event', ['id' => $event->getId()]),
            EventType::Weekly === $type => $this->generateUrl('site_activities').'#mardis',
            EventType::Monthly === $type => $this->generateUrl('site_activities').'#samedis',
            default => '',
        };
    }

    /**
     * Les evenements sont remis au front dans la forme qu'attend le
     * carrousel de assets/site.js, qui les fusionne avec les mardis et les
     * 1ers samedis qu'il calcule lui-meme.
     *
     * Un evenement sur plusieurs jours est deplie en une entree par jour :
     * le carrousel range par date, et un week-end du jeu doit apparaitre le
     * samedi comme le dimanche plutot qu'une seule fois.
     *
     * @param list<Event> $events
     *
     * @return list<array{url: string, ics: string, date: string, type: string, title: string, time: string, description: string, location: string}>
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
                    'url' => $this->eventUrl($event),
                    'ics' => $this->generateUrl('site_event_ics', ['id' => $event->getId()]),
                    'date' => $day->format('Y-m-d'),
                    'type' => $event->getType()?->value ?? 'special',
                    'title' => (string) $event->getTitle(),
                    'time' => (string) $event->getTimeLabel(),
                    // Description et lieu voyagent separement : les coller
                    // produisait des repetitions des que la description
                    // mentionnait deja l'endroit.
                    'description' => (string) $event->getDescription(),
                    'location' => (string) $event->getLocation(),
                ];

                $day = $day->modify('+1 day');
            }
        }

        return $serialized;
    }
}
