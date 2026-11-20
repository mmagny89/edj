<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\EventContribution;
use App\Form\EventContributionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques qui presentent les rendez-vous : la page « en savoir plus »
 * sur les mardis, les samedis et les nuits du jeu, et la page d'une nuit ou
 * d'un week-end du jeu, ou chacun dit ce qu'il apporte.
 */
final class ActivityController extends AbstractController
{
    #[Route('/rendez-vous', name: 'site_activities', methods: ['GET'])]
    public function activities(): Response
    {
        return $this->render('site/activities.html.twig');
    }

    /**
     * Fiche d'une nuit ou d'un week-end du jeu : quand, ou, LudiPassion, tombola.
     */
    #[Route('/evenements/{id}', name: 'site_event', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function event(Event $event): Response
    {
        $this->assertHasOwnPage($event);

        return $this->render('site/event.html.twig', ['event' => $event]);
    }

    /**
     * « Qui apporte quoi » : la liste et le petit formulaire pour s'y ajouter.
     */
    #[Route('/evenements/{id}/apports', name: 'site_event_contributions', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function contributions(
        Request $request,
        Event $event,
        EntityManagerInterface $entityManager,
        RateLimiterFactoryInterface $eventContributionLimiter,
    ): Response {
        $this->assertHasOwnPage($event);

        $over = $event->lastDay() < new \DateTimeImmutable('today');
        $contribution = new EventContribution($event);
        $form = $this->createForm(EventContributionType::class, $contribution);

        // Un evenement passe n'accepte plus d'apports : la liste reste lisible,
        // le formulaire n'est pas traite.
        if (!$over) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                if (!$eventContributionLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                    return $this->render('site/membership_throttled.html.twig', [], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
                }

                $entityManager->persist($contribution);
                $entityManager->flush();

                // Redirection : un rafraichissement ne doit pas redeposer l'apport.
                $this->addFlash('success', 'Merci, c\'est noté !');

                return $this->redirectToRoute('site_event_contributions', ['id' => $event->getId()]);
            }
        }

        return $this->render('site/event_contributions.html.twig', [
            'event' => $event,
            'form' => $form,
            'over' => $over,
        ]);
    }

    /**
     * Seules les nuits et les week-ends du jeu ont leurs pages : un mardi ou
     * une manifestation n'a ni tombola ni liste d'apports.
     */
    private function assertHasOwnPage(Event $event): void
    {
        if (!$event->getType()?->hasOwnPage()) {
            throw $this->createNotFoundException();
        }
    }
}
