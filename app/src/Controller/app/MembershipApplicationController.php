<?php

namespace App\Controller\app;

use App\Entity\MembershipApplication;
use App\Entity\User;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Form\PaperMembershipType;
use App\Service\MemberDirectory;
use App\Service\MembershipNotifier;
use App\Repository\MembershipApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulletins d'adhesion : consultation, etat (validee, annulee), fiche
 * imprimable, anonymisation et liste Philibert.
 *
 * Ouvert aux contributeurs : le suivi des adhesions est le travail courant du
 * bureau, pas une prerogative du seul super-administrateur. Seule la gestion
 * des comptes lui reste reservee. La regle d'access_control le dit deja ;
 * l'attribut la redit ici, pour qu'un changement de motif dans security.yaml
 * ne suffise pas a ouvrir ces pages a un anonyme.
 */
#[Route('/admin/adhesions')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class MembershipApplicationController extends AbstractController
{
    public function __construct(
        private readonly MembershipApplicationRepository $applications,
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipNotifier $notifier,
        private readonly MemberDirectory $members,
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
    /**
     * Inscrit une personne qui a rempli une fiche papier. Le bulletin est
     * marque « fiche papier » et garde l'administrateur qui l'a saisi : on sait
     * ainsi d'ou vient chaque adhesion, et qui a engage l'association.
     *
     * L'acceptation des statuts et du reglement figure sur le papier : elle est
     * posee avant la validation, que l'attestation de l'administrateur couvre.
     */
    #[Route('/nouveau', name: 'app_membership_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $application = (new MembershipApplication())
            ->enterFromPaper($this->getUser() instanceof User ? $this->getUser()->getId() : null)
            ->setSubmittedAt(new \DateTimeImmutable('today'))
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Fiche papier')
            ->setSignatureName('Fiche papier signée');

        $form = $this->createForm(PaperMembershipType::class, $application);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // La signature de substitution porte le nom de l'adherent, pour
            // que la fiche imprimee ne dise pas « fiche papier signee » seul.
            $application->setSignatureName(trim($application->getFirstName().' '.$application->getLastName()));

            $this->members->attach($application);
            $this->entityManager->persist($application);
            $this->entityManager->flush();

            $sent = (bool) $form->get('sendConfirmation')->getData();
            if ($sent) {
                $this->notifier->confirmToApplicant($application);
            }

            $this->addFlash('success', sprintf(
                '%s %s est inscrit(e)%s.',
                $application->getFirstName(),
                $application->getLastName(),
                $sent ? ', et le courriel de confirmation est parti' : ' (sans courriel de confirmation)',
            ));

            return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
        }

        return $this->render('app/membership_new.html.twig', ['form' => $form]);
    }

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

    /**
     * Valide l'adhesion : la cotisation est encaissee, ou la formule est
     * gratuite. Le moyen de reglement est obligatoire des que la formule est
     * payante — l'association doit savoir ce qu'elle a encaisse, et comment.
     */
    #[Route('/{id}/valider', name: 'app_membership_validate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function validate(Request $request, MembershipApplication $application): Response
    {
        if (!$this->isCsrfTokenValid('membership_status_'.$application->getId(), (string) $request->request->get('_token'))) {
            return $this->refuseStatusChange($application, 'jeton invalide');
        }

        $method = PaymentMethod::tryFrom((string) $request->request->get('paymentMethod'));
        if (null === $method && MembershipType::Consumer !== $application->getMembershipType()) {
            return $this->refuseStatusChange($application, 'moyen de règlement manquant');
        }

        $application->validate($method, $this->adminId());
        $this->entityManager->flush();

        $this->addFlash('success', null !== $method
            ? sprintf('Adhésion validée, cotisation réglée par %s.', mb_lcfirst($method->label()))
            : 'Adhésion validée : la formule consommateur est gratuite à l\'année.');

        return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
    }

    #[Route('/{id}/annuler', name: 'app_membership_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(Request $request, MembershipApplication $application): Response
    {
        if (!$this->isCsrfTokenValid('membership_status_'.$application->getId(), (string) $request->request->get('_token'))) {
            return $this->refuseStatusChange($application, 'jeton invalide');
        }

        $application->cancel($this->adminId());
        $this->entityManager->flush();

        $this->addFlash('success', 'Adhésion annulée. Le bulletin reste consultable ; pour effacer les données personnelles, anonymisez-le.');

        return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
    }

    #[Route('/{id}/rouvrir', name: 'app_membership_reopen', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reopen(Request $request, MembershipApplication $application): Response
    {
        if (!$this->isCsrfTokenValid('membership_status_'.$application->getId(), (string) $request->request->get('_token'))) {
            return $this->refuseStatusChange($application, 'jeton invalide');
        }

        $application->reopen($this->adminId());
        $this->entityManager->flush();

        $this->addFlash('success', 'Le bulletin est remis en attente.');

        return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
    }

    #[Route('/{id}/anonymiser', name: 'app_membership_anonymize', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function anonymize(Request $request, MembershipApplication $application): Response
    {
        if (!$this->isCsrfTokenValid('anonymize_membership_'.$application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'L\'anonymisation a été refusée : jeton invalide. Réessayez depuis la fiche.');

            return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
        }

        $application->anonymize();
        if ($member = $application->getMember()) {
            $this->members->forgetIfFullyAnonymized($member);
        }
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
            if ($member = $application->getMember()) {
                $this->members->forgetIfFullyAnonymized($member);
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('%d bulletin(s) anonymisé(s).', $count));

        return $this->redirectToRoute('app_membership_applications');
    }

    private function refuseStatusChange(MembershipApplication $application, string $reason): Response
    {
        $this->addFlash('error', sprintf('Changement d\'état refusé : %s. Réessayez depuis la fiche.', $reason));

        return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
    }

    private function adminId(): ?int
    {
        return $this->getUser() instanceof User ? $this->getUser()->getId() : null;
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
