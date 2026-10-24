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
     * evenement et leur bulletin deja charges : le journal de caisse en
     * affiche la date et le nom, et une lecture paresseuse ferait une requete
     * par ligne.
     *
     * Les bornes sont celles de la saison associative, lues sur la date de
     * l'evenement et non sur celle du pointage : une soiree pointee le
     * lendemain appartient a la soiree.
     *
     * @return list<EventAttendance>
     */
    public function findForSeason(int $season): array
    {
        [$from, $to] = MembershipApplicationRepository::seasonBounds($season);

        return $this->createQueryBuilder('p')
            ->addSelect('e', 'a')
            ->join('p.event', 'e')
            ->join('p.application', 'a')
            ->andWhere('e.startsAt >= :from AND e.startsAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startsAt', 'DESC')
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
