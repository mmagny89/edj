<?php

namespace App\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Form\MemberAccessRequestType;
use App\Form\MembershipApplicationType;
use App\Repository\MemberRepository;
use App\Repository\MembershipApplicationRepository;
use App\Service\MemberAccessLink;
use App\Service\MembershipNotifier;
use App\Service\MembershipSeasons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renouveler son adhesion depuis le site, avec son numero d'adherent.
 *
 * Le parcours est celui de l'espace adherent : on saisit son numero, un lien
 * signe part a l'adresse du dernier bulletin, et c'est ce lien — pas le numero,
 * qui est devinable — qui ouvre le formulaire. Il arrive pre-rempli : personne
 * n'a a ressaisir son adresse, ni a risquer une faute qui lui donnerait un
 * second numero.
 *
 * Le renouvellement est un nouveau bulletin rattache au meme adherent, en
 * attente comme tous les autres : il ne vaut rien tant que le bureau n'a pas
 * encaisse la cotisation.
 */
final class MembershipRenewalController extends AbstractController
{
    public function __construct(
        private readonly MemberRepository $members,
        private readonly MembershipApplicationRepository $applications,
        private readonly EntityManagerInterface $entityManager,
        private readonly MemberAccessLink $links,
        private readonly MembershipNotifier $notifier,
        private readonly RateLimiterFactoryInterface $memberAccessRequestLimiter,
        private readonly RateLimiterFactoryInterface $membershipApplicationLimiter,
    ) {
    }

    #[Route('/adhesion/renouvellement', name: 'membership_renewal_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $form = $this->createForm(MemberAccessRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->memberAccessRequestLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                return $this->render('site/membership_throttled.html.twig', [], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }

            $this->sendLink(mb_strtoupper(trim((string) $form->get('number')->getData())));

            // Meme reponse numero connu ou non : sinon le formulaire dirait qui
            // est adherent.
            return $this->redirectToRoute('membership_renewal_sent');
        }

        return $this->render('site/membership_renewal_request.html.twig', ['form' => $form]);
    }

    #[Route('/adhesion/renouvellement/envoye', name: 'membership_renewal_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('site/membership_renewal_sent.html.twig');
    }

    #[Route('/adhesion/renouvellement/{number}', name: 'membership_renewal', methods: ['GET', 'POST'], requirements: ['number' => Member::PREFIX.'\d+'])]
    public function renew(Request $request, string $number): Response
    {
        if (!$this->links->isValid($request)) {
            return $this->expired(Response::HTTP_FORBIDDEN);
        }

        $member = $this->members->findOneByNumber($number);
        $previous = $member instanceof Member ? $this->applications->findLatestForMember($member) : null;
        if (!$member instanceof Member || !$previous instanceof MembershipApplication) {
            // Signature valable mais bulletin disparu ou anonymise depuis
            // l'envoi : rien a pre-remplir.
            return $this->expired(Response::HTTP_NOT_FOUND);
        }

        $season = MembershipSeasons::of(new \DateTimeImmutable());
        if (null !== $this->applications->findInSeason($member, $season)) {
            return $this->alreadyRenewed($season);
        }

        if (!$this->membershipApplicationLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->render('site/membership_throttled.html.twig', [], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
        }

        $application = MembershipApplication::renewalOf($previous);
        $form = $this->createForm(MembershipApplicationType::class, $application);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le formulaire a pu rester ouvert pendant qu'un second onglet
            // deposait deja le renouvellement : on reverifie a l'ecriture.
            if (null !== $this->applications->findInSeason($member, $season)) {
                return $this->alreadyRenewed($season);
            }

            $this->entityManager->persist($application);
            $this->entityManager->flush();

            $this->notifier->notifyBoard($application, renewal: true);
            $this->notifier->confirmToApplicant($application);

            return $this->redirectToRoute('membership_confirmation');
        }

        return $this->render('site/membership.html.twig', [
            'form' => $form,
            'renewal' => $member,
            'season' => $season,
        ]);
    }

    /**
     * Envoie le lien si le numero correspond a une adhesion consultable.
     * Silencieuse dans tous les autres cas : l'appelant repond pareil.
     */
    private function sendLink(string $number): void
    {
        $member = $this->members->findOneByNumber($number);
        $application = $member instanceof Member ? $this->applications->findLatestForMember($member) : null;
        if (null === $application) {
            return;
        }

        $this->notifier->sendRenewalLink($application, $this->links->renewalUrl($member));
    }

    private function expired(int $status): Response
    {
        return $this->render('site/member_link_expired.html.twig', ['retry' => 'membership_renewal_request'], new Response('', $status));
    }

    private function alreadyRenewed(int $season): Response
    {
        return $this->render('site/membership_renewal_done.html.twig', ['season' => $season]);
    }
}
