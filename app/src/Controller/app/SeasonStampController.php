<?php

namespace App\Controller\app;

use App\Entity\SeasonStamp;
use App\Enum\StampColor;
use App\Enum\StampSymbol;
use App\Form\SeasonStampType;
use App\Repository\SeasonStampRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tampons de saison : le symbole et la couleur apposes sur la carte
 * d'adherent en face de chaque annee reglee.
 *
 * Reserve au super-administrateur : changer un tampon change ce qui est
 * imprime sur toutes les cartes de cette saison, y compris celles deja
 * telechargees par les adherents.
 */
#[Route('/admin/saisons')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class SeasonStampController extends AbstractController
{
    public function __construct(
        private readonly SeasonStampRepository $stamps,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_seasons', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $stamp = $this->suggestNext();
        $form = $this->createForm(SeasonStampType::class, $stamp);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $this->stamps->findOneByYear((int) $stamp->getYear())) {
                $form->get('year')->addError(new FormError('Cette saison a déjà un tampon. Modifiez-le dans la liste.'));
            } else {
                $this->entityManager->persist($stamp);
                $this->entityManager->flush();
                $this->addFlash('success', sprintf('La saison %d est enregistrée.', $stamp->getYear()));

                return $this->redirectToRoute('app_seasons');
            }
        }

        return $this->render('app/seasons/index.html.twig', [
            'stamps' => $this->stamps->findAllOrdered(),
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_season_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, SeasonStamp $stamp): Response
    {
        $form = $this->createForm(SeasonStampType::class, $stamp);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $existing = $this->stamps->findOneByYear((int) $stamp->getYear());
            if (null !== $existing && $existing->getId() !== $stamp->getId()) {
                $form->get('year')->addError(new FormError('Cette saison a déjà un tampon.'));
            } else {
                $this->entityManager->flush();
                $this->addFlash('success', sprintf('La saison %d est modifiée. Les cartes la portent désormais ainsi.', $stamp->getYear()));

                return $this->redirectToRoute('app_seasons');
            }
        }

        return $this->render('app/seasons/edit.html.twig', [
            'stamp' => $stamp,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'app_season_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, SeasonStamp $stamp): Response
    {
        if (!$this->isCsrfTokenValid('season_delete_'.$stamp->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : jeton invalide. Réessayez depuis la liste.');

            return $this->redirectToRoute('app_seasons');
        }

        $year = $stamp->getYear();
        $this->entityManager->remove($stamp);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Le tampon de la saison %d est supprimé : les cartes n\'en portent plus pour cette année.', $year));

        return $this->redirectToRoute('app_seasons');
    }

    /**
     * Pre-remplit le formulaire avec la saison qui suit la derniere enregistree
     * et le tampon qui vient dans la rotation. Ce n'est qu'une proposition :
     * l'association reste libre de choisir autre chose, c'est tout l'interet
     * d'une configuration plutot que d'une regle en dur.
     */
    private function suggestNext(): SeasonStamp
    {
        $latest = $this->stamps->findAllOrdered()[0] ?? null;

        return (new SeasonStamp())
            ->setYear(null !== $latest ? (int) $latest->getYear() + 1 : (int) (new \DateTimeImmutable())->format('Y'))
            ->setSymbol($latest?->getSymbol()?->next() ?? StampSymbol::Star)
            ->setColor($latest?->getColor()?->next() ?? StampColor::Orange);
    }
}
