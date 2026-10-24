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
 * Le courriel qui porte le lien de reinitialisation du mot de passe d'un compte
 * d'administration.
 *
 * Il part a l'adresse du compte, jamais a une adresse saisie dans le
 * formulaire : c'est ce qui fait tenir l'authentification. Un echec d'envoi ne
 * fait pas echouer la demande — la reponse doit rester la meme que le compte
 * existe ou non —, mais il est journalise : un transport mal configure ne doit
 * pas passer inapercu, c'est la panne la plus probable de cette fonction.
 */
final class AdminPasswordResetNotifier
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

    public function send(User $user, ResetPasswordToken $token): void
    {
        $minutes = max(1, (int) round(($token->getExpiresAt()->getTimestamp() - time()) / 60));
        $context = [
            'url' => $this->urls->generate('app_reset_password', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            'minutes' => $minutes,
        ];

        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Envie de Jouer'))
            ->to(new Address((string) $user->getEmail()))
            ->subject('Réinitialiser votre mot de passe d\'administration')
            ->text($this->twig->render('emails/admin_password_reset.txt.twig', $context))
            ->html($this->twig->render('emails/admin_password_reset.html.twig', $context));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Courriel de reinitialisation du mot de passe non envoye.', [
                'user_id' => $user->getId(),
                'exception' => $e::class,
            ]);
        }
    }
}
