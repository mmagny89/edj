<?php

namespace App\Repository;

use App\Entity\SeasonStamp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonStamp>
 */
class SeasonStampRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonStamp::class);
    }

    /**
     * @return list<SeasonStamp>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.year', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les tampons indexes par millesime, pour que la carte les retrouve sans
     * une requete par saison imprimee.
     *
     * @return array<int, SeasonStamp>
     */
    public function indexedByYear(): array
    {
        $stamps = [];
        foreach ($this->findAllOrdered() as $stamp) {
            $stamps[(int) $stamp->getYear()] = $stamp;
        }

        return $stamps;
    }

    public function findOneByYear(int $year): ?SeasonStamp
    {
        return $this->findOneBy(['year' => $year]);
    }
}
