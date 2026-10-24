<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Le compte portant cette adresse, sans tenir compte de la casse : une
     * personne qui retape son adresse en majuscules doit retrouver son compte.
     */
    public function findOneByEmailIgnoringCase(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<User>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de super-administrateurs actifs. Les roles sont un tableau JSON :
     * le filtre se fait en PHP, sur une table de quelques lignes.
     */
    public function countActiveSuperAdmins(): int
    {
        return \count(array_filter(
            $this->findAll(),
            static fn (User $user) => $user->isSuperAdmin() && !$user->isBlocked(),
        ));
    }
}
