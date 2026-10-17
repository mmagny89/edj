<?php

namespace App\Controller\app;

use App\Repository\EventRepository;
use App\Repository\MembershipApplicationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class AppController extends AbstractController
{
    #[Route('', name: 'app_index', methods: ['GET'])]
    public function index(EventRepository $events, MembershipApplicationRepository $applications): Response
    {
        return $this->render('app/index.html.twig', [
            'upcoming' => \array_slice($events->findUpcoming(), 0, 5),
            // Le compte ne se calcule que pour qui peut voir les bulletins :
            // un contributeur n'a pas a connaitre le nombre d'adhesions.
            'applicationCount' => $this->isGranted('ROLE_SUPER_ADMIN') ? $applications->count([]) : null,
        ]);
    }

    /**
     * Donnees personnelles, dont celles de mineurs : reservees au
     * super-administrateur. La regle d'access_control le dit deja ; l'attribut
     * la redit ici, pour qu'un changement de motif dans security.yaml ne
     * suffise pas a ouvrir la page.
     */
    #[Route('/adhesions', name: 'app_membership_applications', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function membershipApplications(MembershipApplicationRepository $repository): Response
    {
        return $this->render('app/membership_applications.html.twig', [
            'applications' => $repository->findLatest(),
        ]);
    }
}
