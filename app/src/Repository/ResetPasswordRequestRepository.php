<?php

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    use ResetPasswordRequestRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        return new ResetPasswordRequest($user, $expiresAt, $selector, $hashedToken);
    }

    /**
     * Supprime toutes les demandes d'un compte. Appele une fois le mot de passe
     * change : le lien utilise est deja supprime par le bundle, mais un lien
     * demande plus tot, et encore valide, permettrait de le changer a nouveau.
     */
    public function removeAllFor(User $user): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
