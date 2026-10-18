<?php

namespace App\Repository;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Service\MemberDirectory;
use App\Service\MembershipSeasons;
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
     * La liste de l'administration, filtree.
     *
     * Les quatre filtres se combinent (« les adhesions famille de la saison
     * 2027 qui n'ont pas encore paye »), parce que c'est ainsi qu'on s'en sert
     * : relancer les impayes d'une saison, retrouver un foyer. Aucun n'est
     * obligatoire ; sans aucun, la liste est celle d'avant.
     *
     * @param array{q?: ?string, status?: ?MembershipStatus, type?: ?MembershipType, season?: ?int} $filters
     *
     * @return list<MembershipApplication>
     */
    public function findFiltered(array $filters, int $limit = 200): array
    {
        $query = $this->createQueryBuilder('a')
            ->leftJoin('a.member', 'm')
            ->addSelect('m')
            ->orderBy('a.submittedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit);

        $term = trim((string) ($filters['q'] ?? ''));
        if ('' !== $term) {
            // Le nom de l'adherent principal fait partie de la recherche :
            // c'est ce qui permet de retrouver un foyer entier en tapant le nom
            // de celui qui porte la cotisation.
            $query
                ->andWhere($query->expr()->orX(
                    'LOWER(a.lastName) LIKE :q',
                    'LOWER(a.firstName) LIKE :q',
                    'LOWER(a.email) LIKE :q',
                    'LOWER(a.principalMemberName) LIKE :q',
                    'LOWER(m.number) LIKE :q',
                ))
                ->setParameter('q', '%'.mb_strtolower($term).'%');
        }

        if (($filters['status'] ?? null) instanceof MembershipStatus) {
            $query->andWhere('a.status = :status')->setParameter('status', $filters['status']);
        }

        if (($filters['type'] ?? null) instanceof MembershipType) {
            $query->andWhere('a.membershipType = :type')->setParameter('type', $filters['type']);
        }

        if (null !== ($season = $filters['season'] ?? null)) {
            // Une saison court du 1er octobre au 30 septembre et porte le
            // millesime de l'annee qu'elle couvre (voir MembershipSeasons) :
            // la saison 2027 va du 1er octobre 2026 au 30 septembre 2027.
            [$from, $to] = self::seasonBounds($season);
            $query
                ->andWhere('a.submittedAt >= :from AND a.submittedAt < :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to);
        }

        return $query->getQuery()->getResult();
    }

    /**
     * Les saisons qui portent au moins un bulletin, de la plus recente a la
     * plus ancienne : ce que le filtre propose. Une saison sans bulletin n'a
     * rien a montrer.
     *
     * @return list<int>
     */
    public function seasonsWithApplications(): array
    {
        $dates = $this->createQueryBuilder('a')
            ->select('MIN(a.submittedAt) AS first, MAX(a.submittedAt) AS last')
            ->getQuery()
            ->getSingleResult();

        if (null === $dates['first']) {
            return [];
        }

        $from = MembershipSeasons::of(new \DateTimeImmutable((string) $dates['first']));
        $to = MembershipSeasons::of(new \DateTimeImmutable((string) $dates['last']));

        return array_reverse(range($from, $to));
    }

    /**
     * Tous les bulletins d'un adherent, du plus recent au plus ancien : ce que
     * la fiche d'administration montre de son parcours, saison apres saison.
     * Les bulletins annules et anonymises en font partie — c'est l'historique,
     * pas la liste de ce qui compte encore.
     *
     * @return list<MembershipApplication>
     */
    public function findAllForMember(Member $member): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.member = :member')
            ->setParameter('member', $member)
            ->orderBy('a.submittedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le bulletin non annule d'un adherent pour une saison, s'il en existe un.
     * Sert a ne laisser renouveler qu'une fois : un bulletin en attente compte
     * — la personne a deja redepose, elle n'a plus qu'a payer.
     */
    public function findInSeason(Member $member, int $season): ?MembershipApplication
    {
        [$from, $to] = self::seasonBounds($season);

        return $this->createQueryBuilder('a')
            ->where('a.member = :member')
            ->andWhere('a.status != :cancelled')
            ->andWhere('a.submittedAt >= :from AND a.submittedAt < :to')
            ->setParameter('member', $member)
            ->setParameter('cancelled', MembershipStatus::Cancelled)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Tous les bulletins valides de ces adherents, toutes saisons confondues.
     * Sert a distinguer un renouvellement d'une premiere adhesion sans
     * parcourir la collection de chaque adherent, qui reste vide pour un
     * adherent cree dans la meme unite de travail.
     *
     * @param list<Member> $members
     *
     * @return list<MembershipApplication>
     */
    public function findValidatedForMembers(array $members): array
    {
        if ([] === $members) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->where('a.member IN (:members)')
            ->andWhere('a.status = :status')
            ->setParameter('members', $members)
            ->setParameter('status', MembershipStatus::Validated)
            ->getQuery()
            ->getResult();
    }

    /**
     * Le bulletin d'adhesion individuelle qui porte ce nom, s'il en existe un.
     * Sert a dire sur la fiche si l'adherent principal designe par un bulletin
     * famille correspond bien a quelqu'un : le bulletin public laisse saisir un
     * nom libre, que personne ne verifie au depot.
     */
    public function findIndividualByName(string $name): ?MembershipApplication
    {
        $normalized = MemberDirectory::normalize($name);

        foreach ($this->findBy(['membershipType' => MembershipType::Individual, 'anonymizedAt' => null]) as $candidate) {
            $parts = [
                MemberDirectory::normalize((string) $candidate->getFirstName()),
                MemberDirectory::normalize((string) $candidate->getLastName()),
            ];

            if (\in_array($normalized, [implode(' ', $parts), implode(' ', array_reverse($parts))], true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Le bulletin que l'espace adherent presente : le plus recent de la
     * personne, parmi ceux qui portent encore ses donnees. Un bulletin
     * anonymise n'a plus rien a montrer, ni fiche ni carte.
     */
    public function findLatestForMember(Member $member): ?MembershipApplication
    {
        return $this->createQueryBuilder('a')
            ->where('a.member = :member')
            ->andWhere('a.anonymizedAt IS NULL')
            ->setParameter('member', $member)
            ->orderBy('a.submittedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
     * Adresses a transmettre a Philibert : celles dont le titulaire a coche
     * l'autorisation ET dont l'adhesion est **validee pour la saison en
     * cours**, sans doublon, de la plus ancienne a la plus recente.
     *
     * La liste part chez un tiers : elle ne doit nommer que des adherents a
     * jour. Une adhesion en attente n'est pas encaissee, une adhesion annulee
     * ne compte plus, et celle d'une saison passee n'engage plus personne — la
     * transmettre serait communiquer l'adresse de quelqu'un qui n'est plus
     * adherent. Un bulletin anonymise n'a de toute facon plus d'adresse.
     *
     * L'autorisation porte sur l'adresse e-mail seule : la liste ne contient
     * rien d'autre.
     *
     * @return list<string>
     */
    public function findPhilibertEmails(?\DateTimeImmutable $now = null): array
    {
        [$from, $to] = self::seasonBounds(MembershipSeasons::of($now ?? new \DateTimeImmutable()));

        $rows = $this->createQueryBuilder('a')
            ->select('a.email')
            ->where('a.philibertConsent = true')
            ->andWhere('a.anonymizedAt IS NULL')
            ->andWhere('a.status = :validated')
            ->andWhere('a.submittedAt >= :from AND a.submittedAt < :to')
            ->setParameter('validated', MembershipStatus::Validated)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            // Deux bulletins du meme foyer partagent souvent la meme seconde de
            // depot (la colonne n'a pas de fraction) : sans departage, c'est le
            // moteur qui choisit quelle graphie de l'adresse survit a la
            // deduplication, et l'export change d'une execution a l'autre.
            ->orderBy('a.submittedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        // La casse ne distingue pas deux adresses : un meme foyer qui depose
        // plusieurs bulletins ne doit pas recevoir plusieurs fois le message.
        $unique = [];
        foreach ($rows as $email) {
            $unique[mb_strtolower((string) $email)] ??= (string) $email;
        }

        return array_values($unique);
    }

    /**
     * Du 1er octobre de l'annee precedente (inclus) au 1er octobre du
     * millesime (exclu) : la saison 2027 va du 1er octobre 2026 au 30
     * septembre 2027.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    public static function seasonBounds(int $season): array
    {
        return [
            new \DateTimeImmutable(sprintf('%d-10-01 00:00:00', $season - 1)),
            new \DateTimeImmutable(sprintf('%d-10-01 00:00:00', $season)),
        ];
    }
}
