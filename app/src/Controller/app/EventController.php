<?php

namespace App\Controller\app;

use App\Dto\SeasonGenerationRequest;
use App\Entity\Event;
use App\Form\EventFormType;
use App\Form\SeasonGenerationType;
use App\Repository\EventRepository;
use App\Service\SeasonGenerator;
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
    /**
     * A moins de six semaines de la fin de la saison, la generation proposee
     * porte sur la saison suivante. Six semaines parce que c'est l'ordre de
     * grandeur ou l'on prepare la rentree — pas une valeur a l'unite pres.
     */
    private const NEXT_SEASON_THRESHOLD_DAYS = 42;

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

    /**
     * Cree en une fois toutes les dates recurrentes d'une periode. Sans cela,
     * couvrir une saison demandait une cinquantaine de saisies identiques.
     */
    #[Route('/generer', name: 'app_event_generate', methods: ['GET', 'POST'])]
    public function generate(Request $request, SeasonGenerator $generator): Response
    {
        $generation = new SeasonGenerationRequest();
        $generation->from = new \DateTimeImmutable('today');
        $generation->to = $this->suggestedEnd($generation->from);

        $form = $this->createForm(SeasonGenerationType::class, $generation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result = $generator->generate($generation);

            if (0 === $result['created'] && 0 === $result['skipped']) {
                $this->addFlash('error', "Aucune date à créer sur cette période : vérifiez les bornes.");
            } else {
                $this->addFlash('success', $this->generationMessage($result));
            }

            return $this->redirectToRoute('app_events');
        }

        return $this->render('app/events/generate.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Borne de fin proposee par defaut.
     *
     * En cours de saison, c'est la fin de la saison en cours. Quand celle-ci
     * touche a sa fin, c'est celle de la suivante : a quelques semaines du
     * 30 septembre, ce qu'on vient preparer est la rentree, pas les trois
     * mardis qui restent.
     *
     * La borne de depart, elle, reste aujourd'hui dans les deux cas : la
     * generation ne duplique rien, une periode large ne coute donc rien et
     * couvre d'un coup la fin de la saison en cours et toute la suivante.
     */
    private function suggestedEnd(\DateTimeImmutable $reference): \DateTimeImmutable
    {
        $end = $this->endOfSeason($reference);

        if ($end < $reference->modify(sprintf('+%d days', self::NEXT_SEASON_THRESHOLD_DAYS))) {
            $end = $this->endOfSeason($end->modify('+1 day'));
        }

        return $end;
    }

    /**
     * La saison associative court du 1er octobre au 30 septembre suivant.
     */
    private function endOfSeason(\DateTimeImmutable $reference): \DateTimeImmutable
    {
        $year = (int) $reference->format('n') >= 10
            ? (int) $reference->format('Y') + 1
            : (int) $reference->format('Y');

        return $reference->setDate($year, 9, 30);
    }

    /**
     * @param array{created: int, skipped: int} $result
     */
    private function generationMessage(array $result): string
    {
        $message = sprintf('%d date(s) ajoutée(s) au calendrier.', $result['created']);

        if ($result['skipped'] > 0) {
            // Sans cette precision, relancer la generation sur une periode
            // deja couverte paraitrait sans effet.
            $message .= sprintf(' %d date(s) étaient déjà présentes et n\'ont pas été dupliquées.', $result['skipped']);
        }

        return $message;
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
