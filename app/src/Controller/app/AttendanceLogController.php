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
use App\Service\Day;
use App\Service\MembershipSeasons;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le pointage d'une seance du calendrier : qui est venu, et qui a paye son euro.
 *
 * On pointe un evenement du calendrier ou une date libre, aujourd'hui ou deja
 * passee : la page propose les seances recentes et s'ouvre sur le jour meme. Un
 * jour sans evenement se pointe sous le libelle « Séance ». La saison se deduit
 * de la date.
 *
 * Un contributeur ne pointe que les consommateurs, jamais dans l'avenir. Un
 * super-administrateur pointe n'importe quel adherent a n'importe quelle date, et
 * choisit, quelle que soit la formule, entre venue gratuite et euro paye.
 *
 * Pense pour etre tenu a l'entree, souvent sur un telephone, pendant que les
 * gens arrivent : un bouton par personne. Chaque geste est un POST qui revient
 * sur la meme page — pas de formulaire a valider, rien a enregistrer a la fin,
 * rien a perdre si l'on ferme l'onglet.
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
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_attendance_log', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $today = new \DateTimeImmutable('today');
        $anyDate = $this->isGranted('ROLE_SUPER_ADMIN');
        $sessions = $this->sessions($today);
        $requested = (string) $request->query->get('date', '');
        $day = Day::parse($requested);

        if ('' !== $requested) {
            if (null === $day) {
                $this->addFlash('error', 'Cette date n\'est pas valide.');
            } elseif ($day > $today && !$anyDate) {
                $this->addFlash('error', 'On ne pointe pas dans l\'avenir.');
            }
        }

        // Date demandee si elle est permise, sinon le jour meme.
        $day = null !== $day && ($anyDate || $day <= $today) ? $day : $today;
        $key = $day->format('Y-m-d');

        $season = MembershipSeasons::of($day);
        $pointed = $this->attendances->indexedByApplication($day);

        $paid = 0;
        $people = [];
        $consumerIds = [];
        foreach ($this->applications->findConsumers($season) as $consumer) {
            $people[(int) $consumer->getId()] = $consumer;
            $consumerIds[] = (int) $consumer->getId();
        }
        foreach ($pointed as $id => $attendance) {
            $paid += $attendance->isPaid() ? 1 : 0;
            // Les autres personnes deja pointees ce jour-la.
            $people[$id] ??= $attendance->getApplication();
        }

        $others = $anyDate ? array_filter(
            $this->applications->findOnePerMember($season),
            static fn (MembershipApplication $a) => !isset($people[(int) $a->getId()]),
        ) : [];

        return $this->render('app/attendance_log/index.html.twig', [
            'day' => $day,
            'today' => $today,
            'isToday' => $day == $today,
            'anyDate' => $anyDate,
            'season' => $season,
            'event' => $sessions[$key] ?? $this->events->findOnDate($day),
            'sessions' => $sessions,
            'people' => array_values($people),
            'consumerIds' => $consumerIds,
            'others' => $others,
            'pointed' => $pointed,
            'present' => \count($pointed),
            'paid' => $paid,
            'priceCents' => EventAttendance::PRICE_CENTS,
        ]);
    }

    /**
     * Les seances pointables : un evenement par jour (le premier saisi l'emporte
     * quand deux tombent le meme jour — un pointage est unique par personne et par
     * jour), du plus recent au plus ancien, jusqu'a aujourd'hui.
     *
     * @return array<string, Event> indexe par jour AAAA-MM-JJ
     */
    private function sessions(\DateTimeImmutable $today): array
    {
        $sessions = [];
        foreach ($this->events->findStartedBy($today) as $event) {
            $last = $event->getEndsAt() ?? $event->getStartsAt();
            $last = $last < $today->setTime(23, 59, 59) ? $last : $today;
            for ($d = $event->getStartsAt()->setTime(0, 0); $d <= $last; $d = $d->modify('+1 day')) {
                $sessions[$d->format('Y-m-d')] ??= $event;
            }
        }
        krsort($sessions);

        return $sessions;
    }

    /**
     * Les trois gestes du pointage, sur une seule route : present et paye,
     * venu gratuitement, et le retrait d'un pointage saisi par erreur.
     *
     * Un seul point d'entree parce que c'est un seul et meme geste vu de la
     * personne qui pointe — elle corrige en appuyant ailleurs, pas en
     * cherchant une autre page.
     */
    #[Route('/{date}/{application}', name: 'app_attendance_log_toggle', methods: ['POST'], requirements: ['date' => '\d{4}-\d{2}-\d{2}', 'application' => '\d+'])]
    public function toggle(Request $request, string $date, MembershipApplication $application): Response
    {
        $day = $this->pointableDay($date);

        // Hors super-administrateur, seuls les consommateurs de la saison.
        if (!$this->isGranted('ROLE_SUPER_ADMIN')
            && !\in_array($application, $this->applications->findConsumers(MembershipSeasons::of($day)), true)) {
            throw $this->createAccessDeniedException('Ce bulletin ne se pointe pas avec ce compte.');
        }

        // L'evenement du calendrier de ce jour, quand il y en a un ; sinon date libre.
        $event = $this->events->findOnDate($day);

        if (!$this->isCsrfTokenValid('pointage'.$date.'-'.$application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, recommencez.');

            return $this->back($day);
        }

        $action = (string) $request->request->get('action', 'paye');

        $attendance = $this->attendances->findOneBy(['occurredOn' => $day, 'application' => $application])
            ?? new EventAttendance($day, $application, $event, $this->currentUserId());

        match ($action) {
            'retirer' => $this->entityManager->remove($attendance),
            'present' => $attendance->markFree(),
            default => $attendance->markPaid(PaymentMethod::Cash),
        };

        if ('retirer' !== $action) {
            $this->entityManager->persist($attendance);
        }

        $this->entityManager->flush();

        $this->securityLogger->info('Pointage consommateur', [
            'event' => 'attendance_log',
            'user' => $this->getUser()?->getUserIdentifier(),
            'date' => $date,
            'action' => $action,
        ]);

        return $this->back($day);
    }

    /**
     * Ajoute au pointage du jour un adherent qui n'est pas dans la liste.
     */
    #[Route('/{date}/ajouter', name: 'app_attendance_log_add', methods: ['POST'], requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function add(Request $request, string $date): Response
    {
        $day = $this->pointableDay($date);

        if (!$this->isCsrfTokenValid('pointage-ajout-'.$date, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, recommencez.');

            return $this->back($day);
        }

        $application = $this->applications->find($request->request->getInt('application'));
        if (null === $application || $application->isAnonymized()) {
            $this->addFlash('error', 'Choisissez un adhérent.');

            return $this->back($day);
        }

        if (null === $this->attendances->findOneBy(['occurredOn' => $day, 'application' => $application])) {
            $attendance = new EventAttendance($day, $application, $this->events->findOnDate($day), $this->currentUserId());
            if ('paye' === $request->request->get('action')) {
                $attendance->markPaid(PaymentMethod::Cash);
            }
            $this->entityManager->persist($attendance);
            $this->entityManager->flush();

            $this->securityLogger->info('Pointage adherent', [
                'event' => 'attendance_log',
                'user' => $this->getUser()?->getUserIdentifier(),
                'date' => $date,
                'action' => 'ajouter',
                'paid' => $attendance->isPaid(),
            ]);
        }

        $this->addFlash('success', sprintf('%s %s est pointé(e).', $application->getFirstName(), $application->getLastName()));

        return $this->back($day);
    }

    /**
     * Retour sur le jour que l'on vient de pointer, et non sur aujourd'hui :
     * sans le parametre, chaque clic sur une date passee ramenerait au jour meme.
     */
    private function back(\DateTimeImmutable $day): Response
    {
        return $this->redirectToRoute('app_attendance_log', ['date' => $day->format('Y-m-d')]);
    }

    /**
     * Le jour d'un geste de pointage : une date reelle, et pas dans l'avenir
     * sauf pour un super-administrateur.
     */
    private function pointableDay(string $date): \DateTimeImmutable
    {
        $day = Day::parse($date);
        if (null === $day || ($day > new \DateTimeImmutable('today') && !$this->isGranted('ROLE_SUPER_ADMIN'))) {
            throw $this->createNotFoundException('Date de pointage invalide.');
        }

        return $day;
    }

    private function currentUserId(): ?int
    {
        return $this->getUser() instanceof User ? $this->getUser()->getId() : null;
    }
}
