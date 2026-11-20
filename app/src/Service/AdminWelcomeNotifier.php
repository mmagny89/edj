<?php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use Twig\Environment;

/**
 * Le courriel d'accueil d'un compte d'administration (ou de sa reinitialisation
 * par un super-administrateur) : un lien pour choisir son mot de passe. Aucun mot de passe ne voyage jamais par courriel.
 *
 * Le lien est celui de la reinitialisation (meme page, meme usage unique), avec
 * une validite plus longue : la personne ne lit pas forcement son courrier dans
 * la demi-heure. Un echec d'envoi ne fait pas echouer la creation, mais il est
 * journalise.
 */
final class AdminWelcomeNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
    ) {
    }

    public function send(User $user, ResetPasswordToken $token, bool $created = true): void
    {
        $context = [
            'email' => (string) $user->getEmail(),
            'url' => $this->urls->generate('app_reset_password', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            'hours' => max(1, (int) round(($token->getExpiresAt()->getTimestamp() - time()) / 3600)),
            'created' => $created,
            'super_admin' => \in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true),
        ];

        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Envie de Jouer'))
            ->to(new Address((string) $user->getEmail()))
            ->subject($created ? 'Votre accès à l\'administration d\'Envie de Jouer' : 'Choisir un nouveau mot de passe d\'administration')
            ->text($this->twig->render('emails/admin_welcome.txt.twig', $context))
            ->html($this->twig->render('emails/admin_welcome.html.twig', $context));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Courriel d\'accueil du compte non envoye.', [
                'user_id' => $user->getId(),
                'exception' => $e::class,
            ]);
        }
    }
}
