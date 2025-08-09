<?php
namespace App\Controller\app;

use App\Entity\Consumable;
use App\Form\ConsumableType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/consumable')]
class ConsumableController extends AbstractController
{
    #[Route('/', name: 'consumable_list')]
    public function list(EntityManagerInterface $em): Response
    {
        $consumables = $em->getRepository(Consumable::class)->findAll();
        return $this->render('app/consumable/list.html.twig', [
            'consumables' => $consumables,
        ]);
    }

    #[Route('/new', name: 'consumable_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $consumable = new Consumable();
        $form = $this->createForm(ConsumableType::class, $consumable);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($consumable);
            $em->flush();
            return $this->redirectToRoute('consumable_list');
        }

        return $this->render('app/consumable/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
