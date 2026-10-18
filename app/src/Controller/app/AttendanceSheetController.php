<?php

namespace App\Controller\app;

use App\Form\AttendanceSheetType;
use App\Repository\MembershipApplicationRepository;
use App\Service\AttendanceSheetRenderer;
use App\Service\MembershipStatistics;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Feuille d'emargement pour une assemblee generale (ordinaire ou extraordinaire).
 *
 * Ouverte aux contributeurs, comme la liste des adhesions dont elle est tiree. La
 * feuille ne porte que numero, nom et prenom : aucune coordonnee.
 */
#[Route('/admin/emargement')]
final class AttendanceSheetController extends AbstractController
{
    public function __construct(
        private readonly MembershipApplicationRepository $applications,
        private readonly AttendanceSheetRenderer $renderer,
        private readonly MembershipStatistics $statistics,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_attendance', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(AttendanceSheetType::class, [
            'kind' => AttendanceSheetType::KINDS['Assemblée générale ordinaire'],
            'date' => new \DateTimeImmutable('today'),
            'season' => $this->statistics->currentSeason(),
            'withMinors' => false,
            'withConsumers' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $members = $this->applications->findForAttendance((int) $data['season'], (bool) $data['withMinors'], (bool) $data['withConsumers']);
            $pdf = $this->renderer->render($data['kind'], $data['date'], (string) $data['place'], (int) $data['season'], $members);

            $this->securityLogger->info('Feuille d\'emargement generee', [
                'event' => 'attendance_sheet',
                'user' => $this->getUser()?->getUserIdentifier(),
                'members' => \count($members),
            ]);

            return new Response($pdf, Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    sprintf('emargement-%s.pdf', $data['date']->format('Y-m-d')),
                ),
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return $this->render('app/attendance/index.html.twig', ['form' => $form]);
    }
}
