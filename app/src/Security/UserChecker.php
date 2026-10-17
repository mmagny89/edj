<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse la connexion d'un compte bloque, avant meme la verification du mot de
 * passe : un compte bloque ne doit pas pouvoir se deviner.
 *
 * Le message est explicite : un compte bloque connait deja son existence, celui
 * qui l'a bloque la lui a dit. Mieux vaut qu'on contacte l'association que de
 * reessayer en boucle.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isBlocked()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est bloqué. Contactez un administrateur.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
