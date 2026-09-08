<?php

namespace App\Controller\app;

use App\Entity\Event;
use App\Form\EventFormType;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/evenements')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class EventController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $events,
    ) {
    }

    #[Route('', name: 'app_events', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('app/events/index.html.twig', [
            'events' => $this->events->findAllOrdered(),
            'today' => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route('/nouveau', name: 'app_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handleForm($request, new Event());
    }

    #[Route('/{id}/modifier', name: 'app_event_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Event $event): Response
    {
        return $this->handleForm($request, $event);
    }

    /**
     * La suppression est en POST et protegee par un jeton : en GET, un simple
     * lien visite par un aspirateur de pages ou un prefetch du navigateur
     * effacerait des evenements.
     */
    #[Route('/{id}/supprimer', name: 'app_event_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Event $event): Response
    {
        if (!$this->isCsrfTokenValid('delete_event_'.$event->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', "La suppression a été refusée : jeton invalide. Réessayez depuis la liste.");

            return $this->redirectToRoute('app_events');
        }

        $title = (string) $event->getTitle();
        $this->entityManager->remove($event);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('« %s » a été supprimé.', $title));

        return $this->redirectToRoute('app_events');
    }

    private function handleForm(Request $request, Event $event): Response
    {
        $isNew = null === $event->getId();
        $form = $this->createForm(EventFormType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($event);
            $this->entityManager->flush();

            $this->addFlash('success', $isNew
                ? sprintf('« %s » a été ajouté au calendrier.', (string) $event->getTitle())
                : sprintf('« %s » a été mis à jour.', (string) $event->getTitle()));

            return $this->redirectToRoute('app_events');
        }

        return $this->render('app/events/form.html.twig', [
            'form' => $form,
            'event' => $event,
            'isNew' => $isNew,
        ]);
    }
}
