<?php
namespace App\Controller\app;

use App\Entity\Consumption;
use App\Entity\Event;
use App\Entity\Member;
use App\Form\ConsumptionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/slates')]
#[IsGranted('ROLE_CONTRIBUTOR')]
class SlateController extends AbstractController
{
    #[Route('/', name: 'app_slate', methods: ['GET'])]
    public function list(EntityManagerInterface $em): Response
    {
        return $this->render('app/slate/index.html.twig', [
            'events' => $em->getRepository(Event::class)->findBy([], ['date' => 'DESC']),
        ]);
    }

    #[Route('/{event}', name: 'app_slate_details', methods: ['GET', 'POST'])]
    public function slate(Event $event, EntityManagerInterface $em): Response
    {
        $consumptions = $em->getRepository(Consumption::class)->findBy(['event' => $event]);

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
    public function memberDetails(Event $event, string $memberNumber, EntityManagerInterface $em): Response
    {
        $member = $em->getRepository(Member::class)->findOneBy(['memberNumber' => $memberNumber]);
        if (!$member) {
            throw $this->createNotFoundException('Membre non trouvé');
        }

        $consumptions = $em->getRepository(Consumption::class)->findBy(['event' => $event, 'member' => $member]);
        $total = array_reduce($consumptions, fn($carry, $consumption) => $carry + ($consumption->getQuantity() * $consumption->getConsumable()->getPrice()), 0);

        return $this->render('app/slate/member_details.html.twig', [
            'event' => $event,
            'member' => $member,
            'consumptions' => $consumptions,
            'total' => $total,
            'form' => $this->createForm(ConsumptionType::class, new Consumption())->createView(),
        ]);
    }

    #[Route('/{event}/consumption/add', name: 'app_slate_consumption_add')]
    public function addConsumption(Event $event, Request $request, EntityManagerInterface $em): Response
    {
        $consumption = new Consumption();
        $consumption->setEvent($event);
        $consumption->setConsumedAt(new \DateTime());

        $form = $this->createForm(ConsumptionType::class, $consumption);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $consumable = $consumption->getConsumable();
            if ($consumable->getStock() !== null && $consumable->getStock() < $consumption->getQuantity()) {
                $this->addFlash('error', 'Stock insuffisant pour ' . $consumable->getName());
                return $this->redirectToRoute('app_slate_details', ['event' => $event->getId()]);
            }

            if ($consumable->getStock() !== null) {
                $consumable->setStock($consumable->getStock() - $consumption->getQuantity());
            }

            $em->persist($consumption);
            $em->flush();
            $this->addFlash('success', 'Consommation ajoutée avec succès.');
            return $this->redirectToRoute('app_slate_member_details', ['event' => $event->getId(), 'memberNumber' => $consumption->getMember()->getMemberNumber()]);
        }

        return $this->render('app/consumption/add.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
        ]);
    }

    #[Route('/consumption/{id}/edit', name: 'app_slate_consumption_edit', methods: ['GET', 'POST'])]
    public function editConsumption(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $consumption = $em->getRepository(Consumption::class)->find($id);
        if (!$consumption) {
            throw $this->createNotFoundException('Consommation non trouvée');
        }

        $form = $this->createForm(ConsumptionType::class, $consumption);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $consumable = $consumption->getConsumable();
            if ($consumable->getStock() !== null && $consumable->getStock() < $consumption->getQuantity()) {
                $this->addFlash('error', 'Stock insuffisant pour ' . $consumable->getName());
                return $this->redirectToRoute('app_slate_member_details', ['event' => $consumption->getEvent()->getId(), 'memberNumber' => $consumption->getMember()->getMemberNumber()]);
            }

            if ($consumable->getStock() !== null) {
                $consumable->setStock($consumable->getStock() - $consumption->getQuantity());
            }

            $em->flush();
            $this->addFlash('success', 'Consommation modifiée avec succès.');
            return $this->redirectToRoute('app_slate_member_details', ['event' => $consumption->getEvent()->getId(), 'memberNumber' => $consumption->getMember()->getMemberNumber()]);
        }

        return $this->render('app/consumption/edit.html.twig', [
            'form' => $form->createView(),
            'event' => $consumption->getEvent(),
            'member' => $consumption->getMember(),
        ]);
    }

    #[Route('/consumption/{id}/delete', name: 'app_slate_consumption_delete', methods: ['POST'])]
    public function deleteConsumption(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $consumption = $em->getRepository(Consumption::class)->find($id);
        if (!$consumption) {
            throw $this->createNotFoundException('Consommation non trouvée');
        }

        if ($this->isCsrfTokenValid('delete_consumption_' . $id, $request->request->get('_token'))) {
            $eventId = $consumption->getEvent()->getId();
            $memberNumber = $consumption->getMember()->getMemberNumber();
            $em->remove($consumption);
            $em->flush();
            $this->addFlash('success', 'Consommation supprimée avec succès.');
            return $this->redirectToRoute('app_slate_member_details', ['event' => $eventId, 'memberNumber' => $memberNumber]);
        }

        $this->addFlash('error', 'Token CSRF invalide.');
        return $this->redirectToRoute('app_slate_member_details', ['event' => $consumption->getEvent()->getId(), 'memberNumber' => $consumption->getMember()->getMemberNumber()]);
    }
}
