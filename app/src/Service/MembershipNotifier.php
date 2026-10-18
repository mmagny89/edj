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
        private readonly RateLimiterFactoryInterface $memberAccessEmailLimiter,
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

    public function notifyBoard(MembershipApplication $application, bool $renewal = false): void
    {
        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Site Envie de Jouer'))
            ->to($this->contactEmail)
            // L'adresse saisie sert de "repondre a", jamais d'expediteur :
            // usurper le From ferait tomber le message dans les indesirables.
            ->subject(sprintf(
                '%s : %s %s',
                $renewal ? 'Renouvellement' : "Bulletin d'adhésion",
                (string) $application->getFirstName(),
                (string) $application->getLastName(),
            ))
            ->html($this->twig->render('emails/membership_application.html.twig', [
                'application' => $application,
                'renewal' => $renewal,
            ]));

        // « Repondre a » : l'adresse saisie, quand il y en a une.
        if ($application->getEmail()) {
            $email->replyTo(new Address((string) $application->getEmail()));
        }

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
        $recipients = $this->recipients($application, named: true);
        if ([] === $recipients) {
            // Ni adresse, ni responsable legal joignable : rien a envoyer, et le
            // bulletin est enregistre dans tous les cas.
            return;
        }

        $key = mb_strtolower($recipients[0]->getAddress());
        if (!$this->membershipConfirmationEmailLimiter->create($key)->consume()->isAccepted()) {
            return;
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

    /**
     * Lien d'acces a l'espace adherent. Il part a la seule adresse du
     * bulletin — jamais a une adresse saisie dans le formulaire : c'est ce qui
     * fait tenir l'authentification.
     *
     * Le responsable legal d'un mineur le recoit aussi : c'est lui qui a
     * engage l'enfant, et souvent lui qui releve les courriels.
     */
    public function sendAccessLink(MembershipApplication $application, string $url): void
    {
        $this->sendLink($application, $url, 'member_access_link', 'Votre fiche d\'adhérent Envie de Jouer', '30 minutes');
    }

    /**
     * Lien de renouvellement : memes garanties que le lien d'acces — il part a
     * la seule adresse du dernier bulletin, et signe le numero qu'il ouvre.
     */
    public function sendRenewalLink(MembershipApplication $application, string $url): void
    {
        $this->sendLink($application, $url, 'membership_renewal_link', 'Renouveler votre adhésion à Envie de Jouer', '2 heures');
    }

    private function sendLink(MembershipApplication $application, string $url, string $template, string $subject, string $lifetime): void
    {
        // Plafond propre a ce courriel, par destinataire. Celui des
        // confirmations d'adhesion ne convenait pas : trois par jour, c'est
        // assez pour un accuse de reception, trop peu pour qui redemande son
        // lien apres avoir perdu le premier.
        $recipients = $this->recipients($application);
        if ([] === $recipients) {
            return;
        }

        $key = mb_strtolower($recipients[0]->getAddress());
        if (!$this->memberAccessEmailLimiter->create($key)->consume()->isAccepted()) {
            return;
        }

        $context = [
            'application' => $application,
            'url' => $url,
            'lifetime' => $lifetime,
        ];

        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Envie de Jouer'))
            ->to(...$recipients)
            ->replyTo(new Address($this->contactEmail, 'Envie de Jouer'))
            ->subject($subject)
            ->text($this->twig->render(sprintf('emails/%s.txt.twig', $template), $context))
            ->html($this->twig->render(sprintf('emails/%s.html.twig', $template), $context));

        $this->send($email);
    }

    /**
     * Les destinataires d'un courriel a l'adherent : son adresse quand il en a une,
     * et celle de son responsable legal s'il est mineur. Vide si personne n'est
     * joignable — beaucoup d'adherents repris d'un fichier n'ont pas d'adresse.
     *
     * @return list<Address>
     */
    private function recipients(MembershipApplication $application, bool $named = false): array
    {
        $recipients = [];
        if ($application->getEmail()) {
            $recipients[] = new Address($application->getEmail(), $named ? trim($application->getFirstName().' '.$application->getLastName()) : '');
        }
        if ($application->isMinor() && $application->getGuardianEmail()) {
            $recipients[] = new Address($application->getGuardianEmail());
        }

        return $recipients;
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
