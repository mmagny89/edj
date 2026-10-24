<?php

namespace App\Controller\app;

use App\Entity\Event;
use App\Entity\EventAttendance;
use App\Entity\MembershipApplication;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventRepository;
use App\Repository\MembershipApplicationRepository;
use App\Service\MembershipStatistics;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le pointage d'une seance : qui est venu, et qui a paye son euro.
 *
 * Pense pour etre tenu a l'entree, souvent sur un telephone, pendant que les
 * gens arrivent : une seance choisie une fois, puis un bouton par personne.
 * Chaque geste est un POST qui revient sur la meme page — pas de formulaire a
 * valider, rien a enregistrer a la fin, rien a perdre si l'on ferme l'onglet.
 */
#[Route('/admin/pointage')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class AttendanceLogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $events,
        private readonly EventAttendanceRepository $attendances,
        private readonly MembershipApplicationRepository $applications,
        private readonly MembershipStatistics $statistics,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_attendance_log', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $season = $this->season($request);
        $events = $this->events->findForSeason($season);
        $event = $this->chosenEvent($request, $events);

        $consumers = $this->applications->findConsumers($season);
        $pointed = null !== $event ? $this->attendances->indexedByApplication($event) : [];

        $paid = 0;
        foreach ($pointed as $attendance) {
            $paid += $attendance->isPaid() ? 1 : 0;
        }

        return $this->render('app/attendance_log/index.html.twig', [
            'seasons' => $this->statistics->availableSeasons(),
            'season' => $season,
            'events' => $events,
            'event' => $event,
            'consumers' => $consumers,
            'pointed' => $pointed,
            'present' => \count($pointed),
            'paid' => $paid,
            'priceCents' => EventAttendance::PRICE_CENTS,
        ]);
    }

    /**
     * Les trois gestes du pointage, sur une seule route : present et paye,
     * present sans avoir paye, et le retrait d'un pointage saisi par erreur.
     *
     * Un seul point d'entree parce que c'est un seul et meme geste vu de la
     * personne qui pointe — elle corrige en appuyant ailleurs, pas en
     * cherchant une autre page.
     */
    #[Route('/{event}/{application}', name: 'app_attendance_log_toggle', methods: ['POST'], requirements: ['event' => '\d+', 'application' => '\d+'])]
    public function toggle(Request $request, Event $event, MembershipApplication $application): Response
    {
        $action = (string) $request->request->get('action', 'paye');

        if (!$this->isCsrfTokenValid('pointage'.$event->getId().'-'.$application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, recommencez.');

            return $this->back($event);
        }

        $attendance = $this->attendances->findOneBy(['event' => $event, 'application' => $application])
            ?? new EventAttendance($event, $application, $this->currentUserId());

        match ($action) {
            'retirer' => $this->entityManager->remove($attendance),
            'present' => $attendance->markUnpaid(),
            default => $attendance->markPaid(PaymentMethod::Cash),
        };

        if ('retirer' !== $action) {
            $this->entityManager->persist($attendance);
        }

        $this->entityManager->flush();

        $this->securityLogger->info('Pointage consommateur', [
            'event' => 'attendance_log',
            'user' => $this->getUser()?->getUserIdentifier(),
            'seance' => $event->getId(),
            'action' => $action,
        ]);

        return $this->back($event);
    }

    /**
     * Retour sur la seance que l'on vient de pointer, et non sur la seance
     * par defaut : sans le parametre, chaque clic ramenerait a la derniere
     * seance passee.
     */
    private function back(Event $event): Response
    {
        return $this->redirectToRoute('app_attendance_log', ['seance' => $event->getId()]);
    }

    private function season(Request $request): int
    {
        $seasons = $this->statistics->availableSeasons();
        $season = $request->query->getInt('saison') ?: $this->statistics->currentSeason();

        return \in_array($season, $seasons, true) ? $season : $this->statistics->currentSeason();
    }

    /**
     * La seance demandee ; a defaut la derniere seance qui a eu lieu, parce
     * que l'on pointe pendant ou juste apres. Si la saison n'a encore que des
     * dates a venir, la plus proche.
     *
     * @param list<Event> $events les evenements de la saison, les plus recents d'abord
     */
    private function chosenEvent(Request $request, array $events): ?Event
    {
        $wanted = $request->query->getInt('seance');

        foreach ($events as $event) {
            if ($event->getId() === $wanted) {
                return $event;
            }
        }

        $today = new \DateTimeImmutable('today');
        foreach ($events as $event) {
            if (($event->getStartsAt() ?? $today) <= $today) {
                return $event;
            }
        }

        return $events[\count($events) - 1] ?? null;
    }

    private function currentUserId(): ?int
    {
        return $this->getUser() instanceof User ? $this->getUser()->getId() : null;
    }
}
