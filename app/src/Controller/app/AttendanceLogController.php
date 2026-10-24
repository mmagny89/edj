<?php

namespace App\Controller\app;

use App\Entity\EventAttendance;
use App\Entity\MembershipApplication;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventRepository;
use App\Repository\MembershipApplicationRepository;
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
 * Le pointage d'un jour : qui est venu, et qui a paye son euro.
 *
 * On pointe pour la seance du jour, ou pour une seance passee dont on saisit la
 * date : la page s'ouvre sur aujourd'hui, et un champ date permet de reculer. Pas
 * de choix d'evenement — une seance n'a pas besoin d'etre au calendrier —, et pas
 * de choix de saison : elle se deduit de la date. On ne pointe pas dans l'avenir.
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
    private const DATE_FORMAT = '!Y-m-d';

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
        $requested = (string) $request->query->get('date', '');
        $day = $this->parseDay($requested) ?? $today;

        // Une date dans l'avenir ne se pointe pas : on revient a aujourd'hui, et on le dit.
        if ($day > $today) {
            $this->addFlash('error', 'On ne pointe pas dans l\'avenir : la page est revenue à aujourd\'hui.');
            $day = $today;
        } elseif ('' !== $requested && null === $this->parseDay($requested)) {
            $this->addFlash('error', 'Cette date n\'est pas valide : la page est revenue à aujourd\'hui.');
        }

        $season = MembershipSeasons::of($day);
        $consumers = $this->applications->findConsumers($season);
        $pointed = $this->attendances->indexedByApplication($day);

        $paid = 0;
        foreach ($pointed as $attendance) {
            $paid += $attendance->isPaid() ? 1 : 0;
        }

        return $this->render('app/attendance_log/index.html.twig', [
            'day' => $day,
            'today' => $today,
            'isToday' => $day == $today,
            'season' => $season,
            'event' => $this->events->findOnDate($day),
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
    #[Route('/{date}/{application}', name: 'app_attendance_log_toggle', methods: ['POST'], requirements: ['date' => '\d{4}-\d{2}-\d{2}', 'application' => '\d+'])]
    public function toggle(Request $request, string $date, MembershipApplication $application): Response
    {
        $day = $this->parseDay($date);
        if (null === $day || $day > new \DateTimeImmutable('today')) {
            throw $this->createNotFoundException('Date de pointage invalide.');
        }

        if (!$this->isCsrfTokenValid('pointage'.$date.'-'.$application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, recommencez.');

            return $this->back($day);
        }

        $action = (string) $request->request->get('action', 'paye');

        $attendance = $this->attendances->findOneBy(['occurredOn' => $day, 'application' => $application])
            ?? new EventAttendance($day, $application, $this->events->findOnDate($day), $this->currentUserId());

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
            'date' => $date,
            'action' => $action,
        ]);

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
     * La date « AAAA-MM-JJ », ou null si elle n'en est pas une : « 2026-02-30 »
     * est refusee, pas reportee au 2 mars.
     */
    private function parseDay(string $value): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if (false === $day || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $day;
    }

    private function currentUserId(): ?int
    {
        return $this->getUser() instanceof User ? $this->getUser()->getId() : null;
    }
}
