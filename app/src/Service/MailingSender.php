<?php

namespace App\Service;

use App\Dto\MailingDraft;
use App\Entity\MailCampaign;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * L'envoi d'un message aux adherents d'une saison.
 *
 * Un courriel par adresse, mis en file (Messenger, transport asynchrone) : le
 * clic du bureau rend la main tout de suite, et le worker les achemine au rythme
 * du fournisseur — un envoi de plusieurs centaines de messages ne tient pas dans
 * une requete.
 *
 * Chaque message porte son lien de desinscription, en bas du texte et dans les
 * en-tetes List-Unsubscribe (RFC 8058) : Gmail et les autres affichent alors
 * leur propre bouton « se desabonner », que les gens preferent au lien d'un
 * courriel — et, faute de mieux, au bouton « spam », qui ruine la reputation de
 * l'expediteur pour tout le monde, les courriels de gestion compris.
 */
final class MailingSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly MailingUnsubscribeLink $links,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
        #[Autowire('%app.contact_email%')]
        private readonly string $contactEmail,
    ) {
    }

    /**
     * Met en file un message par destinataire, puis enregistre l'envoi dans
     * l'historique avec ce qui est reellement parti.
     */
    public function send(MailingDraft $draft, MailingAudienceResult $audience, ?int $sentById): MailCampaign
    {
        $season = (int) $draft->season;
        $queued = 0;

        foreach ($audience->recipients as $recipient) {
            $email = $this->build(
                $draft,
                MailingTemplate::valuesFor($recipient->application, $season),
                $recipient->address,
                (string) $recipient->member->getNumber(),
                $this->links->url($recipient->member),
            );

            try {
                $this->mailer->send($email);
                ++$queued;
            } catch (TransportExceptionInterface $e) {
                // Journalise sans l'adresse : un message echoue ne doit pas
                // arreter les suivants, et le journal ne garde aucune donnee d'adherent.
                $this->logger->error('Message aux adherents non mis en file.', [
                    'member_id' => $recipient->member->getId(),
                    'exception' => $e::class,
                ]);
            }
        }

        $campaign = new MailCampaign(
            $draft->subject,
            $draft->body,
            $season,
            $draft->includeAnnual,
            $draft->includeConsumers,
            $queued,
            $audience->skippedWithoutAddress,
            $audience->skippedOptedOut,
            $audience->sharedAddress,
            $sentById,
        );
        $this->entityManager->persist($campaign);
        $this->entityManager->flush();

        return $campaign;
    }

    /**
     * Un essai a une seule adresse — celle de la personne qui redige —, avec des
     * valeurs d'exemple. L'objet le dit, pour qu'on ne le confonde pas avec un
     * message parti.
     */
    public function sendTest(MailingDraft $draft, string $to): void
    {
        $email = $this->build(
            $draft,
            MailingTemplate::sampleValues((int) $draft->season),
            $to,
            'EDJ-0042',
            null,
        );
        $email->subject('[ESSAI] '.$email->getSubject());

        $this->mailer->send($email);
    }

    /**
     * @param array<string, string> $values
     */
    private function build(MailingDraft $draft, array $values, string $to, string $number, ?string $unsubscribeUrl): Email
    {
        $subject = trim((string) preg_replace('/\s+/', ' ', MailingTemplate::render($draft->subject, $values)));
        $body = MailingTemplate::render($draft->body, $values);
        $context = [
            'body_text' => $body,
            'body_html' => MailingTemplate::toHtml($body),
            'number' => $number,
            // Pas de lien pour un essai : il n'existe aucun adherent a desinscrire.
            'unsubscribe_url' => $unsubscribeUrl,
        ];

        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Envie de Jouer'))
            ->to(new Address($to))
            ->replyTo(new Address($this->contactEmail, 'Envie de Jouer'))
            ->subject($subject)
            ->text($this->twig->render('emails/mailing.txt.twig', $context))
            ->html($this->twig->render('emails/mailing.html.twig', $context));

        if (null !== $unsubscribeUrl) {
            $headers = $email->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        return $email;
    }
}
