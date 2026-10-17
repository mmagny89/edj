<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Garde la date de derniere connexion d'un compte : c'est ce qui permet de voir
 * un compte dormant, ou une connexion que personne ne reconnait.
 */
final class RecordLoginListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->recordLogin();
        $this->entityManager->flush();

        // L'adresse e-mail n'est jamais ecrite au journal : l'identifiant interne
        // suffit a retrouver le compte (conventions, section 24, point 7).
        $this->securityLogger->info('Connexion reussie.', ['user_id' => $user->getId()]);
    }
}
