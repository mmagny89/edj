<?php

namespace App\Controller\app;

use App\Repository\MembershipApplicationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class AppController extends AbstractController
{
    #[Route('', name: 'app_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('app/index.html.twig');
    }

    #[Route('/adhesions', name: 'app_membership_applications', methods: ['GET'])]
    public function membershipApplications(MembershipApplicationRepository $repository): Response
    {
        return $this->render('app/membership_applications.html.twig', [
            'applications' => $repository->findLatest(),
        ]);
    }
}
