<?php

namespace App\Repository;

use App\Entity\MailCampaign;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailCampaign>
 */
class MailCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailCampaign::class);
    }

    /**
     * Les envois, du plus recent au plus ancien.
     *
     * @return list<MailCampaign>
     */
    public function findLatest(int $limit = 100): array
    {
        /** @var list<MailCampaign> $rows */
        $rows = $this->createQueryBuilder('c')
            ->orderBy('c.sentAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
