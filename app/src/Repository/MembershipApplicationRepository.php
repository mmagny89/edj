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

    /**
     * Adresses des adherents qui ont autorise leur communication a Philibert,
     * hors bulletins anonymises, sans doublon, de la plus ancienne a la plus
     * recente.
     *
     * @return list<string>
     */
    public function findPhilibertEmails(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.email')
            ->where('a.philibertConsent = true')
            ->andWhere('a.anonymizedAt IS NULL')
            ->orderBy('a.submittedAt', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        // La casse ne distingue pas deux adresses : un meme foyer qui depose
        // deux bulletins ne doit pas recevoir deux fois le message.
        $unique = [];
        foreach ($rows as $email) {
            $unique[mb_strtolower((string) $email)] ??= (string) $email;
        }

        return array_values($unique);
    }
}
