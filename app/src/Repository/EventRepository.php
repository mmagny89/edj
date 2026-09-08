<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * Evenements en cours ou a venir, du plus proche au plus lointain.
     *
     * La comparaison porte sur la date de fin quand elle existe : un week-end
     * du jeu reste a l'affiche le dimanche, alors que sa date de debut est
     * deja passee.
     *
     * @return list<Event>
     */
    public function findUpcoming(?\DateTimeImmutable $from = null): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :from')
            ->setParameter('from', $from ?? new \DateTimeImmutable('today'))
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les evenements, les plus proches d'abord : la liste du
     * back-office, ou l'on veut aussi voir ce qui vient de passer.
     *
     * @return list<Event>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.startsAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cles "type|date" des evenements deja presents sur une periode, sous
     * forme de tableau indexe : la generation d'une saison teste chaque date
     * contre cet ensemble plutot que d'interroger la base des centaines de
     * fois.
     *
     * @return array<string, true>
     */
    public function existingKeys(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{type: \App\Enum\EventType, startsAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.type', 'e.startsAt')
            ->andWhere('e.startsAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        $keys = [];

        foreach ($rows as $row) {
            $keys[$row['type']->value.'|'.$row['startsAt']->format('Y-m-d')] = true;
        }

        return $keys;
    }

    /**
     * Evenements termines avant la date donnee. Sert a annoncer ce que la
     * purge va supprimer avant de le faire.
     *
     * @return list<Event>
     */
    public function findEndedBefore(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) < :before')
            ->setParameter('before', $before)
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les evenements termines avant la date donnee et renvoie leur
     * nombre.
     */
    public function deleteEndedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('COALESCE(e.endsAt, e.startsAt) < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
