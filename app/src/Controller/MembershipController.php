<?php

namespace App\Controller;

use App\Entity\MembershipApplication;
use App\Form\MembershipApplicationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class MembershipController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly RateLimiterFactoryInterface $membershipApplicationLimiter,
        #[Autowire('%app.contact_email%')]
        private readonly string $contactEmail,
        // Lien du groupe prive : passe au seul gabarit de l'e-mail, jamais
        // expose en variable globale Twig, donc jamais rendu sur une page.
        #[Autowire('%app.social.members_group%')]
        private readonly string $membersGroupUrl,
    ) {
    }

    #[Route('/adhesion/bulletin', name: 'membership_form', methods: ['GET', 'POST'])]
    public function form(Request $request): Response
    {
        // Limitation de debit avant toute autre chose : un envoi refuse ne
        // doit couter ni requete en base, ni envoi de courriel. La cle est
        // l'adresse IP, jamais conservee au-dela du compteur.
        $limiter = $this->membershipApplicationLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->render('site/membership_throttled.html.twig', [], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
        }

        $application = new MembershipApplication();
        $form = $this->createForm(MembershipApplicationType::class, $application);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($application);
            $this->entityManager->flush();

            $this->notify($application);
            $this->confirmToApplicant($application);

            // Redirection apres enregistrement : un rafraichissement de page
            // ne doit pas redeposer le bulletin.
            return $this->redirectToRoute('membership_confirmation');
        }

        return $this->render('site/membership.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/adhesion/bulletin/merci', name: 'membership_confirmation', methods: ['GET'])]
    public function confirmation(): Response
    {
        return $this->render('site/membership_confirmation.html.twig');
    }

    /**
     * L'echec d'envoi ne doit pas faire echouer le depot : le bulletin est
     * deja enregistre, et c'est lui qui fait foi. L'erreur est journalisee
     * par le mailer, le visiteur voit sa confirmation.
     */
    private function notify(MembershipApplication $application): void
    {
        $email = (new Email())
            ->from(new Address($this->contactEmail, 'Site Envie de Jouer'))
            ->to($this->contactEmail)
            // L'adresse saisie sert de "repondre a", jamais d'expediteur :
            // usurper le From ferait tomber le message dans les indesirables.
            ->replyTo(new Address((string) $application->getEmail()))
            ->subject(sprintf(
                "Bulletin d'adhésion : %s %s",
                (string) $application->getFirstName(),
                (string) $application->getLastName(),
            ))
            ->html($this->renderView('emails/membership_application.html.twig', [
                'application' => $application,
            ]));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            // Silencieux a dessein : voir le bloc de documentation ci-dessus.
        }
    }

    /**
     * Accuse de reception envoye a l'adherent — et au responsable legal pour
     * un mineur : c'est lui qui a engage l'enfant, il doit en avoir trace.
     *
     * Meme regle que notify() : un echec d'envoi ne fait jamais echouer le
     * depot. Le courriel part de l'adresse de l'association, et « repondre »
     * lui revient : l'adherent ecrit a une personne, pas a une boite muette.
     */
    private function confirmToApplicant(MembershipApplication $application): void
    {
        $recipients = [new Address((string) $application->getEmail(), trim($application->getFirstName().' '.$application->getLastName()))];
        if ($application->isMinor() && $application->getGuardianEmail()) {
            $recipients[] = new Address($application->getGuardianEmail());
        }

        $context = [
            'application' => $application,
            'membersGroupUrl' => '' !== $this->membersGroupUrl ? $this->membersGroupUrl : null,
        ];

        $email = (new Email())
            ->from(new Address($this->contactEmail, 'Envie de Jouer'))
            ->to(...$recipients)
            ->replyTo(new Address($this->contactEmail, 'Envie de Jouer'))
            ->subject('Bienvenue à Envie de Jouer : votre bulletin est bien reçu')
            ->text($this->renderView('emails/membership_confirmation.txt.twig', $context))
            ->html($this->renderView('emails/membership_confirmation.html.twig', $context));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            // Silencieux a dessein : voir le bloc de documentation ci-dessus.
        }
    }
}
