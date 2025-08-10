<?php
namespace App\Controller\app;

use App\Entity\Consumption;
use App\Entity\Event;
use App\Entity\Member;
use App\Form\ConsumptionType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/slates')]
#[IsGranted('ROLE_CONTRIBUTOR')]
class SlateController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em
    ) {
    }

    #[Route('/', name: 'app_slate', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('app/slate/index.html.twig', [
            'events' => $this->em->getRepository(Event::class)->findBy([], ['date' => 'DESC']),
        ]);
    }

    #[Route('/{event}', name: 'app_slate_details', methods: ['GET', 'POST'])]
    public function slate(Event $event): Response
    {
        $consumptions = $this->em->getRepository(Consumption::class)->findBy(['event' => $event]);

        // Regrouper les consommations par membre et calculer les totaux
        $membersData = [];
        foreach ($consumptions as $consumption) {
            $memberNumber = $consumption->getMember()->getMemberNumber();
            if (!isset($membersData[$memberNumber])) {
                $membersData[$memberNumber] = [
                    'member' => $consumption->getMember(),
                    'total' => 0,
                ];
            }
            $membersData[$memberNumber]['total'] += $consumption->getQuantity() * $consumption->getConsumable()->getPrice();
        }

        return $this->render('app/slate/details.html.twig', [
            'event' => $event,
            'membersData' => $membersData,
            'form' => $this->createForm(ConsumptionType::class, new Consumption())->createView(),
        ]);
    }

    #[Route('/{event}/member/{memberNumber}', name: 'app_slate_member_details', methods: ['GET'])]
    public function memberDetails(Event $event, string $memberNumber): Response
    {
        $member = $this->em->getRepository(Member::class)->findOneBy(['memberNumber' => $memberNumber]);
        if (!$member) {
            throw $this->createNotFoundException('Membre non trouvé');
        }

        $consumptions = $this->em->getRepository(Consumption::class)->findBy([
            'event' => $event,
            'member' => $member,
        ]);

        return $this->render('app/slate/member_details.html.twig', [
            'event' => $event,
            'member' => $member,
            'consumptions' => $consumptions,
            'form' => $this->createForm(ConsumptionType::class, new Consumption())->createView(),
        ]);
    }

    #[Route('/{event}/consumption/add', name: 'app_slate_consumption_add', methods: ['POST'])]
    public function addConsumption(Event $event, Request $request): Response
    {
        $this->logger->info('Tentative d\'ajout d\'une consommation', ['eventId' => $event->getId()]);
        $consumption = new Consumption();
        $form = $this->createForm(ConsumptionType::class, $consumption);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $receivedToken = $request->request->get('_token');
            $this->logger->debug('Vérification CSRF', [
                'received_token' => $receivedToken,
                'expected_key' => 'add_consumption',
            ]);
            if ($this->isCsrfTokenValid('add_consumption', $receivedToken)) {
                $consumption->setEvent($event);
                $consumption->setCreatedAt(new \DateTimeImmutable());
                $this->em->persist($consumption);
                $this->em->flush();
                $this->logger->info('Consommation ajoutée avec succès', [
                    'eventId' => $event->getId(),
                    'memberNumber' => $consumption->getMember()->getMemberNumber(),
                    'consumable' => $consumption->getConsumable()->getName(),
                ]);
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['success' => true, 'message' => 'Consommation ajoutée avec succès !', 'reload' => true]);
                }
                $this->addFlash('success', 'Consommation ajoutée avec succès.');
                return $this->redirectToRoute('app_slate_member_details', [
                    'event' => $event->getId(),
                    'memberNumber' => $consumption->getMember()->getMemberNumber(),
                ], Response::HTTP_SEE_OTHER);
            } else {
                $this->logger->warning('Token CSRF invalide pour ajout', ['received_token' => $receivedToken]);
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['success' => false, 'error' => 'Token CSRF invalide'], 403);
                }
                $this->addFlash('error', 'Token CSRF invalide.');
            }
        } else {
            $this->logger->warning('Formulaire invalide', ['errors' => $form->getErrors(true)]);
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'error' => 'Formulaire invalide'], 400);
            }
            $this->addFlash('error', 'Formulaire invalide.');
        }

        return $this->redirectToRoute('app_slate_details', ['event' => $event->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/consumption/{id}/edit', name: 'app_slate_consumption_edit', methods: ['GET', 'POST'])]
    public function editConsumption(int $id, Request $request): Response
    {
        $consumption = $this->em->getRepository(Consumption::class)->find($id);
        if (!$consumption) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'error' => 'Consommation non trouvée'], 404);
            }
            throw $this->createNotFoundException('Consommation non trouvée');
        }

        $form = $this->createForm(ConsumptionType::class, $consumption);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Consommation modifiée avec succès.']);
            }
            $this->addFlash('success', 'Consommation modifiée avec succès.');
            return $this->redirectToRoute('app_slate_member_details', [
                'event' => $consumption->getEvent()->getId(),
                'memberNumber' => $consumption->getMember()->getMemberNumber(),
            ]);
        }

        return $this->render('app/consumption/edit.html.twig', [
            'form' => $form->createView(),
            'event' => $consumption->getEvent(),
            'member' => $consumption->getMember(),
        ]);
    }

    #[Route('/consumption/{id}/delete', name: 'app_slate_consumption_delete', methods: ['POST'])]
    public function deleteConsumption(int $id, Request $request): Response
    {
        $consumption = $this->em->getRepository(Consumption::class)->find($id);
        if (!$consumption) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'error' => 'Consommation non trouvée'], 404);
            }
            throw $this->createNotFoundException('Consommation non trouvée');
        }

        if ($this->isCsrfTokenValid('delete_consumption_' . $id, $request->request->get('_token'))) {
            $eventId = $consumption->getEvent()->getId();
            $memberNumber = $consumption->getMember()->getMemberNumber();
            $this->em->remove($consumption);
            $this->em->flush();

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => true, 'message' => 'Consommation supprimée avec succès.', 'reload' => true]);
            }
            $this->addFlash('success', 'Consommation supprimée avec succès.');
            return $this->redirectToRoute('app_slate_member_details', ['event' => $eventId, 'memberNumber' => $memberNumber]);
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'error' => 'Token CSRF invalide'], 403);
        }
        $this->addFlash('error', 'Token CSRF invalide.');
        return $this->redirectToRoute('app_slate_member_details', ['event' => $consumption->getEvent()->getId(), 'memberNumber' => $consumption->getMember()->getMemberNumber()]);
    }

    #[Route('/members/search', name: 'app_members_search', methods: ['GET'])]
    public function searchMembers(Request $request): Response
    {
        $query = $request->query->get('query', '');
        $this->logger->info('Recherche de membres', ['query' => $query]);
        $members = $this->em->getRepository(Member::class)->createQueryBuilder('m')
            ->where('m.memberNumber LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $results = array_map(fn(Member $member) => [
            'memberNumber' => $member->getMemberNumber(),
        ], $members);

        return new JsonResponse($results);
    }
}
