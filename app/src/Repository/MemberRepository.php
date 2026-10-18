<?php

namespace App\Repository;

use App\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Member>
 */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    public function findOneByIdentityKey(string $identityKey): ?Member
    {
        return $this->findOneBy(['identityKey' => $identityKey]);
    }

    public function findOneByNumber(string $number): ?Member
    {
        return $this->findOneBy(['number' => $number]);
    }

    /**
     * Supprime les adherents qu'aucun bulletin ne designe plus. N'arrive qu'a
     * la purge des bulletins fictifs : un vrai bulletin n'est jamais supprime.
     */
    public function deleteOrphans(): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'DELETE FROM App\\Entity\\Member m
                 WHERE NOT EXISTS (SELECT 1 FROM App\\Entity\\MembershipApplication a WHERE a.member = m)',
            )
            ->execute();
    }
}
