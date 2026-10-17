<?php

namespace App\Controller\app;

use App\Entity\MembershipApplication;
use App\Repository\MembershipApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulletins d'adhesion : consultation, fiche imprimable, anonymisation et
 * liste Philibert.
 *
 * Donnees personnelles, dont celles de mineurs : reservees au
 * super-administrateur. La regle d'access_control le dit deja ; l'attribut la
 * redit ici, pour qu'un changement de motif dans security.yaml ne suffise pas
 * a ouvrir ces pages.
 */
#[Route('/admin/adhesions')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class MembershipApplicationController extends AbstractController
{
    public function __construct(
        private readonly MembershipApplicationRepository $applications,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_membership_applications', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('app/membership_applications.html.twig', [
            'applications' => $this->applications->findLatest(),
            'philibertCount' => \count($this->applications->findPhilibertEmails()),
        ]);
    }

    /**
     * Liste des adresses a transmettre a Philibert : uniquement celles dont le
     * titulaire a coche l'autorisation, et jamais un bulletin anonymise.
     *
     * L'autorisation porte sur l'adresse e-mail seule : le fichier ne contient
     * donc rien d'autre — ni nom, ni telephone.
     */
    #[Route('/philibert.csv', name: 'app_membership_philibert', methods: ['GET'])]
    public function philibert(): Response
    {
        $emails = $this->applications->findPhilibertEmails();

        $response = new StreamedResponse(function () use ($emails): void {
            $out = fopen('php://output', 'wb');
            // BOM : Excel lit sinon l'UTF-8 comme du Windows-1252.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['email'], ';', '"', '');
            foreach ($emails as $email) {
                fputcsv($out, [$this->neutralize($email)], ';', '"', '');
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="philibert-%s.csv"',
            (new \DateTimeImmutable())->format('Y-m-d'),
        ));

        return $response;
    }

    #[Route('/{id}', name: 'app_membership_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(MembershipApplication $application): Response
    {
        return $this->render('app/membership_show.html.twig', ['application' => $application]);
    }

    /**
     * Fiche a imprimer : une page autonome, sans barre laterale, mise en page
     * pour le papier. Un bulletin anonymise n'a plus rien a imprimer.
     */
    #[Route('/{id}/fiche', name: 'app_membership_sheet', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sheet(MembershipApplication $application): Response
    {
        if ($application->isAnonymized()) {
            $this->addFlash('error', 'Ce bulletin est anonymisé : il n\'y a plus de fiche à imprimer.');

            return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
        }

        return $this->render('app/membership_sheet.html.twig', ['application' => $application]);
    }

    #[Route('/{id}/anonymiser', name: 'app_membership_anonymize', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function anonymize(Request $request, MembershipApplication $application): Response
    {
        if (!$this->isCsrfTokenValid('anonymize_membership_'.$application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'L\'anonymisation a été refusée : jeton invalide. Réessayez depuis la fiche.');

            return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
        }

        $application->anonymize();
        $this->entityManager->flush();

        $this->addFlash('success', 'Le bulletin a été anonymisé.');

        return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
    }

    /**
     * Anonymisation en masse : les bulletins coches dans la liste.
     */
    #[Route('/anonymiser', name: 'app_membership_anonymize_many', methods: ['POST'])]
    public function anonymizeMany(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('anonymize_memberships', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'L\'anonymisation a été refusée : jeton invalide. Réessayez depuis la liste.');

            return $this->redirectToRoute('app_membership_applications');
        }

        // Seuls des identifiants entiers sont retenus : le formulaire est
        // fabricable a la main, rien de ce qu'il porte n'est de confiance.
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->request->all('ids')),
            static fn (int $id) => $id > 0,
        )));

        if ([] === $ids) {
            $this->addFlash('error', 'Aucun bulletin sélectionné.');

            return $this->redirectToRoute('app_membership_applications');
        }

        $count = 0;
        foreach ($this->applications->findBy(['id' => $ids]) as $application) {
            if (!$application->isAnonymized()) {
                $application->anonymize();
                ++$count;
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('%d bulletin(s) anonymisé(s).', $count));

        return $this->redirectToRoute('app_membership_applications');
    }

    /**
     * Une cellule qui commence par = + - @ est lue comme une formule par un
     * tableur. Les adresses sont validees a la saisie, mais le fichier sort
     * d'ici pour etre ouvert ailleurs : il ne fait confiance a rien.
     */
    private function neutralize(string $value): string
    {
        return 1 === preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
