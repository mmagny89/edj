<?php

namespace App\Controller;

use App\Entity\Member;
use App\Repository\MemberRepository;
use App\Service\MailingUnsubscribeLink;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Se desinscrire des messages de l'association, depuis le lien place en bas de
 * chacun.
 *
 * Le lien est signe (voir MailingUnsubscribeLink) : la signature est la seule
 * preuve exigee, et la seule chose que ce lien permet est ce choix. Pas de
 * jeton CSRF sur le POST : les messageries qui implementent « un clic »
 * (RFC 8058) envoient un POST sans formulaire, et il n'y a rien a forger — qui
 * ne connait pas l'adresse signee ne peut pas la viser.
 *
 * Un numero inconnu et une signature fausse donnent la meme reponse : la page ne
 * dit jamais qu'un numero existe.
 */
#[Route('/adhesion/desinscription/{number}', requirements: ['number' => Member::PREFIX.'\d+'])]
final class MailingUnsubscribeController extends AbstractController
{
    public function __construct(
        private readonly MemberRepository $members,
        private readonly MailingUnsubscribeLink $links,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'mailing_unsubscribe', methods: ['GET', 'POST'])]
    public function unsubscribe(Request $request, string $number): Response
    {
        $member = $this->members->findOneByNumber($number);
        if (!$this->links->isValid($request) || null === $member) {
            return $this->render('site/mailing_unsubscribe_invalid.html.twig', [], new Response('', Response::HTTP_FORBIDDEN));
        }

        if ($request->isMethod('POST')) {
            // Sans champ « action », c'est le « un clic » d'une messagerie : se desinscrire.
            $resubscribe = 'resubscribe' === $request->request->getString('action');
            $resubscribe ? $member->optBackIntoMailings() : $member->optOutOfMailings();
            $this->entityManager->flush();

            $this->securityLogger->info($resubscribe ? 'Reabonnement aux messages de l\'association.' : 'Desinscription des messages de l\'association.', [
                'event' => $resubscribe ? 'mailing_resubscribed' : 'mailing_unsubscribed',
                'member_id' => $member->getId(),
            ]);

            // « Un clic » : la messagerie n'affiche rien, un 200 lui suffit.
            if ($request->request->has('List-Unsubscribe')) {
                return new Response('', Response::HTTP_OK);
            }

            return $this->redirect($request->getUri());
        }

        return $this->render('site/mailing_unsubscribe.html.twig', [
            'optedOut' => $member->isOptedOutOfMailings(),
        ]);
    }
}
