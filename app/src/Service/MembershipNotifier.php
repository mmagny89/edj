<?php

namespace App\Service;

use App\Entity\MembershipApplication;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;

/**
 * Courriels lies a un bulletin d'adhesion : l'avis au conseil d'administration
 * et l'accuse de reception a l'adherent.
 *
 * Un echec d'envoi ne fait jamais echouer l'operation qui l'a declenche : le
 * bulletin est deja enregistre, et c'est lui qui fait foi. L'erreur est
 * journalisee par le mailer.
 */
final class MembershipNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly RateLimiterFactoryInterface $membershipConfirmationEmailLimiter,
        #[Autowire('%app.contact_email%')]
        private readonly string $contactEmail,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
        // Lien du groupe WhatsApp : passe au seul gabarit du courriel, jamais
        // expose en variable globale Twig, donc jamais rendu sur une page.
        #[Autowire('%app.social.members_whatsapp%')]
        private readonly string $membersWhatsappUrl,
    ) {
    }

    public function notifyBoard(MembershipApplication $application): void
    {
        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Site Envie de Jouer'))
            ->to($this->contactEmail)
            // L'adresse saisie sert de "repondre a", jamais d'expediteur :
            // usurper le From ferait tomber le message dans les indesirables.
            ->replyTo(new Address((string) $application->getEmail()))
            ->subject(sprintf(
                "Bulletin d'adhésion : %s %s",
                (string) $application->getFirstName(),
                (string) $application->getLastName(),
            ))
            ->html($this->twig->render('emails/membership_application.html.twig', [
                'application' => $application,
            ]));

        $this->send($email);
    }

    /**
     * Accuse de reception envoye a l'adherent — et au responsable legal pour un
     * mineur : c'est lui qui a engage l'enfant, il doit en avoir trace.
     *
     * Le courriel part de l'adresse de l'association, et « repondre » lui
     * revient : l'adherent ecrit a une personne, pas a une boite muette.
     */
    public function confirmToApplicant(MembershipApplication $application): void
    {
        // Un destinataire ne recoit que trois confirmations par jour : saisir
        // l'adresse d'un tiers dans le bulletin ne permet pas de l'inonder. Le
        // bulletin est enregistre dans tous les cas ; seul le courriel est omis.
        $key = mb_strtolower((string) $application->getEmail());
        if (!$this->membershipConfirmationEmailLimiter->create($key)->consume()->isAccepted()) {
            return;
        }

        $recipients = [new Address((string) $application->getEmail(), trim($application->getFirstName().' '.$application->getLastName()))];
        if ($application->isMinor() && $application->getGuardianEmail()) {
            $recipients[] = new Address($application->getGuardianEmail());
        }

        $context = [
            'application' => $application,
            'membersWhatsappUrl' => '' !== $this->membersWhatsappUrl ? $this->membersWhatsappUrl : null,
        ];

        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Envie de Jouer'))
            ->to(...$recipients)
            ->replyTo(new Address($this->contactEmail, 'Envie de Jouer'))
            ->subject('Bienvenue à Envie de Jouer : votre bulletin est bien reçu')
            ->text($this->twig->render('emails/membership_confirmation.txt.twig', $context))
            ->html($this->twig->render('emails/membership_confirmation.html.twig', $context));

        $this->send($email);
    }

    private function send(Email $email): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            // Silencieux a dessein : voir le bloc de documentation de la classe.
        }
    }
}
