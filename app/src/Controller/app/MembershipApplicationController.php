<?php

namespace App\Controller\app;

use App\Entity\MembershipApplication;
use App\Entity\User;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Form\PaperMembershipType;
use App\Service\MemberDirectory;
use App\Service\MembershipCardRenderer;
use App\Service\MembershipNotifier;
use App\Repository\MembershipApplicationRepository;
use App\Repository\UserRepository;
use App\Service\MembershipSeasons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulletins d'adhesion : consultation, etat (validee, annulee), fiche
 * imprimable, carte d'adherent, anonymisation et liste Philibert.
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
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Les filtres passent par l'URL (GET) et non par une session : une liste
     * filtree se partage alors par son lien — « les impayes de la saison
     * 2027 » — et un rechargement ne change pas ce qu'on regarde.
     */
    #[Route('', name: 'app_membership_applications', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query->get('q')),
            'status' => MembershipStatus::tryFrom((string) $request->query->get('etat')),
            'type' => MembershipType::tryFrom((string) $request->query->get('formule')),
            'season' => $request->query->getInt('saison') ?: null,
        ];

        return $this->render('app/membership_applications.html.twig', [
            'applications' => $this->applications->findFiltered($filters),
            'filters' => $filters,
            'filtered' => '' !== $filters['q'] || null !== $filters['status'] || null !== $filters['type'] || null !== $filters['season'],
            'seasons' => $this->applications->seasonsWithApplications(),
            'philibertCount' => \count($this->applications->findPhilibertEmails()),
            'philibertSeason' => MembershipSeasons::of(new \DateTimeImmutable()),
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

    /**
     * Renouvelle l'adhesion d'une personne deja connue, depuis sa fiche : le
     * formulaire arrive rempli avec son dernier bulletin, sous le meme numero.
     *
     * C'est le cas d'un adherent qui renouvelle a une soiree, sur papier ou de
     * vive voix : le bureau n'a ni a retrouver sa fiche pour tout recopier, ni a
     * lui donner un second numero. Le bulletin est enregistre comme « fiche
     * papier », en attente, et se valide ensuite comme les autres.
     *
     * Une saison ne se renouvelle qu'une fois : a l'ouverture du formulaire, et
     * de nouveau a l'enregistrement, la date de la fiche pouvant viser une autre
     * saison que celle d'aujourd'hui.
     */
    #[Route('/{id}/renouveler', name: 'app_membership_renew', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function renew(Request $request, MembershipApplication $application): Response
    {
        $member = $application->getMember();
        if ($application->isAnonymized() || null === $member) {
            $this->addFlash('error', 'Ce bulletin est anonymisé : il n\'y a plus rien à reprendre pour un renouvellement.');

            return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
        }

        $season = MembershipSeasons::of(new \DateTimeImmutable());
        if (null !== $existing = $this->applications->findInSeason($member, $season)) {
            $this->addFlash('error', sprintf('%s a déjà une adhésion pour la saison %d.', $member->getNumber(), $season));

            return $this->redirectToRoute('app_membership_show', ['id' => $existing->getId()]);
        }

        // On reprend le bulletin le plus recent de la personne, pas celui de la
        // fiche d'ou l'on vient : c'est lui qui porte son adresse a jour.
        $previous = $this->applications->findLatestForMember($member) ?? $application;

        $draft = MembershipApplication::renewalOf($previous)
            ->enterFromPaper($this->adminId())
            ->setSubmittedAt(new \DateTimeImmutable('today'))
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Fiche papier')
            ->setSignatureName('Fiche papier signée');

        $form = $this->createForm(PaperMembershipType::class, $draft);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $chosen = MembershipSeasons::of($draft->getSubmittedAt());
            if (null !== $this->applications->findInSeason($member, $chosen)) {
                $form->get('submittedAt')->addError(new FormError(sprintf('%s a déjà une adhésion pour la saison %d.', $member->getNumber(), $chosen)));
            } else {
                $draft->setSignatureName(trim($draft->getFirstName().' '.$draft->getLastName()));
                $this->entityManager->persist($draft);
                $this->entityManager->flush();

                $sent = (bool) $form->get('sendConfirmation')->getData();
                if ($sent) {
                    $this->notifier->confirmToApplicant($draft);
                }

                $this->addFlash('success', sprintf(
                    'Adhésion %s renouvelée pour la saison %d%s. Elle est en attente : validez-la une fois la cotisation encaissée.',
                    $member->getNumber(),
                    $chosen,
                    $sent ? ', et le courriel de confirmation est parti' : ' (sans courriel de confirmation)',
                ));

                return $this->redirectToRoute('app_membership_show', ['id' => $draft->getId()]);
            }
        }

        return $this->render('app/membership_new.html.twig', [
            'form' => $form,
            'renewal' => $member,
            'season' => $season,
            'from' => $application,
        ]);
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
            'attachment; filename="philibert-saison-%d-%s.csv"',
            MembershipSeasons::of(new \DateTimeImmutable()),
            (new \DateTimeImmutable())->format('Y-m-d'),
        ));

        return $response;
    }

    #[Route('/{id}', name: 'app_membership_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(MembershipApplication $application): Response
    {
        return $this->render('app/membership_show.html.twig', [
            'application' => $application,
            // Le bulletin public laisse saisir l'adherent principal en texte
            // libre : la fiche dit si ce nom correspond a une adhesion
            // individuelle connue, plutot que de le presenter comme acquis.
            'actors' => $this->actorsOf($application),
            'renewable' => $this->isRenewable($application),
            'currentSeason' => MembershipSeasons::of(new \DateTimeImmutable()),
            'parcours' => null !== $application->getMember() ? $this->applications->findAllForMember($application->getMember()) : [],
            'principal' => MembershipType::Family === $application->getMembershipType() && $application->getPrincipalMemberName()
                ? $this->applications->findIndividualByName((string) $application->getPrincipalMemberName())
                : null,
        ]);
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
    /**
     * La carte d'adherent, telle que la personne la telecharge depuis son
     * espace : le bureau doit pouvoir la regarder avant de la remettre, ou la
     * reimprimer pour qui n'a pas d'imprimante.
     *
     * Affichee dans l'onglet (« inline ») et non telechargee : on la consulte
     * ici, on ne la collectionne pas. Elle s'ouvre meme si l'adhesion n'est
     * pas encore validee — aucune saison n'y est alors cochee, ce qui est
     * exactement ce qu'il faut voir avant d'encaisser.
     */
    #[Route('/{id}/carte.pdf', name: 'app_membership_card', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function card(MembershipApplication $application, MembershipCardRenderer $renderer): Response
    {
        if ($application->isAnonymized()) {
            $this->addFlash('error', 'Ce bulletin est anonymisé : il n\'y a plus de carte à montrer.');

            return $this->redirectToRoute('app_membership_show', ['id' => $application->getId()]);
        }

        $response = new Response($renderer->render($application), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $renderer->filename($application)),
        ]);
        // Carte nominative : ni le navigateur ni un intermediaire ne la gardent.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/{id}/valider', name: 'app_membership_validate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function validate(Request $request, MembershipApplication $application): Response
    {
        if (!$this->isCsrfTokenValid('membership_status_'.$application->getId(), (string) $request->request->get('_token'))) {
            return $this->refuseStatusChange($application, 'jeton invalide');
        }

        $method = PaymentMethod::tryFrom((string) $request->request->get('paymentMethod'));
        if (null === $method && ($application->amountDue() ?? 0) > 0) {
            return $this->refuseStatusChange($application, 'moyen de règlement manquant');
        }

        $application->validate($method, $this->adminId());
        $this->entityManager->flush();

        $this->addFlash('success', null !== $method
            ? sprintf('Adhésion validée, cotisation réglée par %s.', mb_lcfirst($method->label()))
            : sprintf('Adhésion validée : %s.', mb_lcfirst($application->amountDueLabel())));

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

        $application->anonymize(adminId: $this->adminId());
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
                $application->anonymize(adminId: $this->adminId());
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

    /**
     * Les adresses des administrateurs qui ont decide, indexees par
     * identifiant. L'historique ne garde que l'identifiant : un compte supprime
     * depuis n'a plus d'entree ici, et la fiche l'ecrit comme tel.
     *
     * @return array<int, string>
     */
    private function actorsOf(MembershipApplication $application): array
    {
        $ids = [];
        foreach ($application->getHistory() as $event) {
            if (null !== $event->getActorId()) {
                $ids[$event->getActorId()] = true;
            }
        }

        $actors = [];
        foreach ([] === $ids ? [] : $this->users->findBy(['id' => array_keys($ids)]) as $user) {
            $actors[(int) $user->getId()] = (string) $user->getEmail();
        }

        return $actors;
    }

    /**
     * Vrai quand la fiche peut ouvrir un renouvellement : la personne est
     * connue, son bulletin n'est pas anonymise, et elle n'a encore aucun
     * bulletin — hors annule — pour la saison en cours.
     */
    private function isRenewable(MembershipApplication $application): bool
    {
        $member = $application->getMember();

        return null !== $member
            && !$application->isAnonymized()
            && null === $this->applications->findInSeason($member, MembershipSeasons::of(new \DateTimeImmutable()));
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
