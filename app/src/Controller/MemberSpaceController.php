<?php

namespace App\Controller;

use App\Entity\Member;
use App\Enum\MembershipType;
use App\Form\MemberEditType;
use App\Service\MembershipSeasons;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\MembershipApplication;
use App\Form\MemberAccessRequestType;
use App\Repository\MemberRepository;
use App\Repository\MembershipApplicationRepository;
use App\Service\MemberAccessLink;
use App\Service\MembershipCardRenderer;
use App\Service\MembershipNotifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace adherent : consulter sa fiche et telecharger sa carte, sans compte ni
 * mot de passe.
 *
 * Le parcours tient en trois pages : on saisit son numero, on recoit un lien
 * signe par courriel, le lien ouvre la fiche. Rien d'autre n'y mene — une URL
 * sans signature valable est refusee, quel que soit le numero.
 *
 * La reponse a une demande est toujours la meme, que le numero existe ou non :
 * sinon, le formulaire devient un moyen de savoir qui est adherent.
 */
final class MemberSpaceController extends AbstractController
{
    public function __construct(
        private readonly MemberRepository $members,
        private readonly MembershipApplicationRepository $applications,
        private readonly MemberAccessLink $links,
        private readonly MembershipNotifier $notifier,
        private readonly RateLimiterFactoryInterface $memberAccessRequestLimiter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/adhesion/ma-fiche', name: 'member_access_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $form = $this->createForm(MemberAccessRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le plafond se consomme a l'envoi et non a l'affichage : un
            // visiteur qui recharge la page ne s'en prive pas lui-meme.
            if (!$this->memberAccessRequestLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                return $this->render('site/membership_throttled.html.twig', [], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }

            $this->sendLink(mb_strtoupper(trim((string) $form->get('number')->getData())));

            return $this->redirectToRoute('member_access_sent');
        }

        return $this->render('site/member_access.html.twig', ['form' => $form]);
    }

    #[Route('/adhesion/ma-fiche/envoye', name: 'member_access_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('site/member_access_sent.html.twig');
    }

    #[Route('/adhesion/espace/{number}', name: 'member_space', methods: ['GET'], requirements: ['number' => Member::PREFIX.'\d+'])]
    public function space(Request $request, string $number): Response
    {
        $application = $this->resolve($request, $number);
        if (!$application instanceof MembershipApplication) {
            return $application;
        }

        return $this->render('site/member_space.html.twig', [
            'application' => $application,
            'cardUrl' => $application->getMember()
                ? $this->links->cardUrl($application->getMember(), $this->links->expirationOf($request))
                : null,
            'editUrl' => $application->getMember()
                ? $this->links->editUrl($application->getMember(), $this->links->expirationOf($request))
                : null,
            'canUpgrade' => $application->canBecomeAnnual(MembershipSeasons::of(new \DateTimeImmutable())),
        ]);
    }

    /**
     * Demande du lien « Modifier mon adhesion » : meme parcours que le
     * renouvellement — numero, puis lien signe envoye a l'adresse du bulletin —,
     * et meme reponse que le numero existe ou non.
     */
    #[Route('/adhesion/modification', name: 'membership_edit_request', methods: ['GET', 'POST'])]
    public function editRequest(Request $request): Response
    {
        $form = $this->createForm(MemberAccessRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->memberAccessRequestLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                return $this->render('site/membership_throttled.html.twig', [], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }

            $this->sendEditLink(mb_strtoupper(trim((string) $form->get('number')->getData())));

            return $this->redirectToRoute('membership_edit_sent');
        }

        return $this->render('site/membership_edit_request.html.twig', ['form' => $form]);
    }

    #[Route('/adhesion/modification/envoye', name: 'membership_edit_sent', methods: ['GET'])]
    public function editSent(): Response
    {
        return $this->render('site/membership_edit_sent.html.twig');
    }

    /**
     * Modifier ses coordonnees et, pour un consommateur de la saison en cours,
     * passer a une formule annuelle sans attendre la saison suivante.
     *
     * Comme pour le bureau, une adhesion validee redevient « en attente » des
     * qu'une cotisation est a payer : la carte n'est plus delivree tant
     * qu'elle n'est pas encaissee.
     */
    #[Route('/adhesion/espace/{number}/modifier', name: 'member_edit', methods: ['GET', 'POST'], requirements: ['number' => Member::PREFIX.'\\d+'])]
    public function edit(Request $request, string $number): Response
    {
        $application = $this->resolve($request, $number);
        if (!$application instanceof MembershipApplication) {
            return $application;
        }

        $canUpgrade = $application->canBecomeAnnual(MembershipSeasons::of(new \DateTimeImmutable()));
        $from = $application->getMembershipType();
        $form = $this->createForm(MemberEditType::class, $application, ['allow_upgrade' => $canUpgrade]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $upgraded = $canUpgrade && null !== $from && $from !== $application->getMembershipType();

            if ($upgraded) {
                if (MembershipType::Family !== $application->getMembershipType()) {
                    $application->setPrincipalMemberName(null);
                }

                $application->recordFormulaChange($from, null);
                if (($application->amountDue() ?? 0) > 0 && $application->isValidated()) {
                    $application->reopen(null);
                }
            }
            $this->entityManager->flush();

            $this->notifier->notifyChange($application, $upgraded);

            return $this->render('site/member_edit_done.html.twig', ['application' => $application, 'upgraded' => $upgraded]);
        }

        return $this->render('site/member_edit.html.twig', ['form' => $form, 'application' => $application, 'canUpgrade' => $canUpgrade]);
    }

    /**
     * La carte ne se telecharge qu'une fois l'adhesion validee : une carte
     * remise avant encaissement vaudrait acceptation.
     */
    #[Route('/adhesion/espace/{number}/carte.pdf', name: 'member_card', methods: ['GET'], requirements: ['number' => Member::PREFIX.'\d+'])]
    public function card(Request $request, string $number, MembershipCardRenderer $renderer): Response
    {
        $application = $this->resolve($request, $number);
        if (!$application instanceof MembershipApplication) {
            return $application;
        }

        if (!$application->isValidated()) {
            $this->addFlash('error', "Votre carte sera disponible dès que l'association aura validé votre adhésion.");

            return $this->redirectToRoute('member_access_request');
        }

        $response = new Response($renderer->render($application), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $renderer->filename($application)),
        ]);
        // Une carte nominative ne se garde ni dans le cache du navigateur ni
        // dans celui d'un intermediaire.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Verifie la signature puis retrouve le bulletin. Renvoie le bulletin, ou
     * la reponse a servir quand l'acces est refuse.
     */
    private function resolve(Request $request, string $number): MembershipApplication|Response
    {
        if (!$this->links->isValid($request)) {
            return $this->render('site/member_link_expired.html.twig', [], new Response('', Response::HTTP_FORBIDDEN));
        }

        $member = $this->members->findOneByNumber($number);
        $application = $member instanceof Member ? $this->applications->findLatestForMember($member) : null;

        // Signature valable mais bulletin disparu ou anonymise depuis l'envoi :
        // la meme page que pour un lien perime, qui dit quoi faire.
        return $application ?? $this->render('site/member_link_expired.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
    }

    /**
     * Envoie le lien si le numero correspond a une adhesion consultable.
     * Silencieuse dans tous les autres cas : l'appelant repond pareil.
     */
    private function sendEditLink(string $number): void
    {
        $member = $this->members->findOneByNumber($number);
        $application = $member instanceof Member ? $this->applications->findLatestForMember($member) : null;
        if (null === $application) {
            return;
        }

        $this->notifier->sendEditLink($application, $this->links->editUrl($member));
    }

    private function sendLink(string $number): void
    {
        $member = $this->members->findOneByNumber($number);
        if (!$member instanceof Member) {
            return;
        }

        $application = $this->applications->findLatestForMember($member);
        if (!$application instanceof MembershipApplication) {
            return;
        }

        $this->notifier->sendAccessLink($application, $this->links->sheetUrl($member));
    }
}
