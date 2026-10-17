<?php

namespace App\Repository;

use App\Entity\MembershipApplication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembershipApplication>
 */
class MembershipApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembershipApplication::class);
    }

    /**
     * @return list<MembershipApplication>
     */
    public function findLatest(int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.submittedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les bulletins dont l'adresse se termine par le suffixe donne.
     * Reserve aux bulletins fictifs : le suffixe est un domaine inexistant.
     */
    public function deleteDemo(string $emailSuffix): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->where('a.email LIKE :suffix')
            ->setParameter('suffix', '%'.$emailSuffix)
            ->getQuery()
            ->execute();
    }
}
