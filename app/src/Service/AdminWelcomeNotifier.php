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
use Twig\Environment;

/**
 * Le courriel d'accueil d'un compte d'administration : adresse de connexion,
 * mot de passe provisoire et consigne de le changer des la premiere connexion.
 *
 * Le mot de passe provisoire n'est pas durable : le compte est cree avec
 * « doit changer son mot de passe », et la premiere connexion l'impose. Un
 * echec d'envoi ne fait pas echouer la creation — le mot de passe reste
 * affiche a l'ecran de l'administrateur qui l'a creee — mais il est journalise.
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

    public function send(User $user, string $temporaryPassword): void
    {
        $context = [
            'email' => (string) $user->getEmail(),
            'password' => $temporaryPassword,
            'login_url' => $this->urls->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'super_admin' => \in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true),
        ];

        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Envie de Jouer'))
            ->to(new Address((string) $user->getEmail()))
            ->subject('Votre accès à l\'administration d\'Envie de Jouer')
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
