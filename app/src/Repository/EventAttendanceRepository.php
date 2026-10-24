<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventAttendance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventAttendance>
 */
class EventAttendanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventAttendance::class);
    }

    /**
     * Les pointages d'un evenement, indexes par identifiant de bulletin : la
     * page de pointage teste chaque ligne de sa liste contre ce tableau
     * plutot que d'interroger la base une fois par personne.
     *
     * @return array<int, EventAttendance>
     */
    public function indexedByApplication(Event $event): array
    {
        $indexed = [];

        foreach ($this->findBy(['event' => $event]) as $attendance) {
            $indexed[(int) $attendance->getApplication()->getId()] = $attendance;
        }

        return $indexed;
    }

    /**
     * Les pointages d'une saison, du plus recent au plus ancien, avec leur
     * bulletin deja charge : le journal de caisse en affiche le nom, et une
     * lecture paresseuse ferait une requete par ligne.
     *
     * Les bornes sont celles de la saison associative, lues sur le jour de la
     * seance copie sur le pointage et non sur l'evenement, qui a pu etre
     * purge entre-temps.
     *
     * @return list<EventAttendance>
     */
    public function findForSeason(int $season): array
    {
        [$from, $to] = MembershipApplicationRepository::seasonBounds($season);

        return $this->createQueryBuilder('p')
            ->addSelect('a')
            ->join('p.application', 'a')
            ->andWhere('p.occurredOn >= :from AND p.occurredOn < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.occurredOn', 'DESC')
            ->addOrderBy('a.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de passages pointes sur un evenement, et combien sont regles.
     *
     * @return array{total: int, paid: int}
     */
    public function tallyFor(Event $event): array
    {
        $rows = $this->findBy(['event' => $event]);
        $paid = 0;

        foreach ($rows as $row) {
            if ($row->isPaid()) {
                ++$paid;
            }
        }

        return ['total' => \count($rows), 'paid' => $paid];
    }
}
