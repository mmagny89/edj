<?php

namespace App\Controller;

use App\Entity\MembershipApplication;
use App\Form\MembershipApplicationType;
use App\Service\MembershipNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class MembershipController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipNotifier $notifier,
        private readonly RateLimiterFactoryInterface $membershipApplicationLimiter,
    ) {
    }

    #[Route('/adhesion/bulletin', name: 'membership_form', methods: ['GET', 'POST'])]
    public function form(Request $request): Response
    {
        // Limitation de debit avant toute autre chose : un envoi refuse ne
        // doit couter ni requete en base, ni envoi de courriel. La cle est
        // l'adresse IP, jamais conservee au-dela du compteur.
        $limiter = $this->membershipApplicationLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->render('site/membership_throttled.html.twig', [], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
        }

        $application = new MembershipApplication();
        $form = $this->createForm(MembershipApplicationType::class, $application);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($application);
            $this->entityManager->flush();

            $this->notifier->notifyBoard($application);
            $this->notifier->confirmToApplicant($application);

            // Redirection apres enregistrement : un rafraichissement de page
            // ne doit pas redeposer le bulletin.
            return $this->redirectToRoute('membership_confirmation');
        }

        return $this->render('site/membership.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/adhesion/bulletin/merci', name: 'membership_confirmation', methods: ['GET'])]
    public function confirmation(): Response
    {
        return $this->render('site/membership_confirmation.html.twig');
    }
}
