<?php

namespace App\Repository;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
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
     * Philibert, droit a l'image et moyen de reglement s'ajoutent aux quatre
     * premiers : « les cheques de la saison », « les refus de photo », « ceux
     * dont l'adresse part chez Philibert ». « none » en moyen de reglement
     * designe les bulletins sans aucun encaissement — en attente, annules ou
     * gratuits.
     *
     * @param array{q?: ?string, status?: ?MembershipStatus, type?: ?MembershipType, season?: ?int, philibert?: ?bool, images?: ?string, payment?: PaymentMethod|string|null} $filters
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

        if (null !== ($philibert = $filters['philibert'] ?? null)) {
            $query->andWhere('a.philibertConsent = :philibert')->setParameter('philibert', $philibert);
        }

        // « refused » : la case « je refuse les photos » est cochee.
        if ('refused' === ($filters['images'] ?? null)) {
            $query->andWhere('a.imageRightsRefused = true');
        } elseif ('allowed' === ($filters['images'] ?? null)) {
            $query->andWhere('a.imageRightsRefused = false');
        }

        $payment = $filters['payment'] ?? null;
        if ($payment instanceof PaymentMethod) {
            $query->andWhere('a.paymentMethod = :payment')->setParameter('payment', $payment);
        } elseif ('none' === $payment) {
            $query->andWhere('a.paymentMethod IS NULL');
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
     * Tous les bulletins valides, avec leur adherent, du plus ancien au plus
     * recent : la matiere des courbes du tableau de bord, qui comparent des
     * saisons entre elles. Une seule requete — un adherent charge en meme temps
     * que son bulletin, jamais une requete par ligne.
     *
     * @return list<MembershipApplication>
     */
    public function findAllValidated(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.member', 'm')
            ->addSelect('m')
            ->where('a.status = :validated')
            ->setParameter('validated', MembershipStatus::Validated)
            ->orderBy('a.submittedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
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
     * Ce qu'on transmet a Philibert : l'adresse et la date d'adhesion des
     * adherents **actifs de la saison en cours** qui ont autorise la
     * communication, sans doublon, du plus ancien au plus recent.
     *
     * La liste part chez un tiers : elle ne nomme que des adherents a jour.
     * Actif veut dire adhesion annuelle validee pour la saison — une adhesion
     * en attente n'est pas encaissee, une adhesion annulee ne compte plus, celle
     * d'une saison passee n'engage plus personne, et la formule consommateur,
     * gratuite a l'annee, n'ouvre pas droit aux avantages du programme. Un
     * bulletin anonymise n'a de toute facon plus d'adresse.
     *
     * L'autorisation porte sur l'adresse e-mail : la date d'adhesion s'y ajoute
     * parce que Philibert doit savoir depuis quand la personne adhere, rien de
     * plus. Ni nom, ni telephone.
     *
     * @return list<array{email: string, joinedAt: \DateTimeImmutable}>
     */
    public function findPhilibertRows(?\DateTimeImmutable $now = null): array
    {
        [$from, $to] = self::seasonBounds(MembershipSeasons::of($now ?? new \DateTimeImmutable()));

        $rows = $this->createQueryBuilder('a')
            ->select('a.email AS email, a.submittedAt AS joinedAt')
            ->where('a.philibertConsent = true')
            ->andWhere('a.anonymizedAt IS NULL')
            ->andWhere('a.email IS NOT NULL')
            ->andWhere('a.status = :validated')
            ->andWhere('a.membershipType != :consumer')
            ->andWhere('a.submittedAt >= :from AND a.submittedAt < :to')
            ->setParameter('validated', MembershipStatus::Validated)
            ->setParameter('consumer', MembershipType::Consumer)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            // Deux bulletins du meme foyer partagent souvent la meme seconde de
            // depot (la colonne n'a pas de fraction) : sans departage, c'est le
            // moteur qui choisit quelle graphie de l'adresse survit a la
            // deduplication, et l'export change d'une execution a l'autre.
            ->orderBy('a.submittedAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // La casse ne distingue pas deux adresses : un meme foyer qui depose
        // plusieurs bulletins ne la recoit qu'une fois, avec sa premiere date.
        $unique = [];
        foreach ($rows as $row) {
            $unique[mb_strtolower((string) $row['email'])] ??= ['email' => (string) $row['email'], 'joinedAt' => $row['joinedAt']];
        }

        return array_values($unique);
    }

    /**
     * Les adherents a jour d'une saison, pour une feuille d'emargement : un bulletin
     * valide par personne, sans compte anonymise, classes par nom.
     *
     * Les consommateurs et les mineurs ne sont pris que sur demande : ils n'ont en
     * general pas voix a l'assemblee. Seul le necessaire est lu pour la feuille — nom,
     * prenom, numero — jamais de coordonnees.
     *
     * @return list<MembershipApplication>
     */
    public function findForAttendance(int $season, bool $withMinors, bool $withConsumers): array
    {
        [$from, $to] = self::seasonBounds($season);

        $qb = $this->createQueryBuilder('a')
            ->where('a.status = :validated')
            ->andWhere('a.anonymizedAt IS NULL')
            ->andWhere('a.submittedAt >= :from AND a.submittedAt < :to')
            ->setParameter('validated', MembershipStatus::Validated)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.id', 'ASC');

        if (!$withConsumers) {
            $qb->andWhere('a.membershipType != :consumer')->setParameter('consumer', MembershipType::Consumer);
        }

        $unique = [];
        foreach ($qb->getQuery()->getResult() as $application) {
            if (!$withMinors && $application->isMinor()) {
                continue;
            }
            // Un adherent qui a deux bulletins valides dans la saison (passage de
            // consommateur a annuel) ne signe qu'une fois.
            $unique[$application->getMember()?->getId() ?? 'a'.$application->getId()] = $application;
        }

        $list = array_values($unique);
        usort($list, static fn (MembershipApplication $x, MembershipApplication $y) => [mb_strtolower((string) $x->getLastName()), mb_strtolower((string) $x->getFirstName())] <=> [mb_strtolower((string) $y->getLastName()), mb_strtolower((string) $y->getFirstName())]);

        return $list;
    }

    /**
     * Les consommateurs a jour d'une saison, classes par nom : la liste que
     * l'on pointe a l'entree d'une seance.
     *
     * Un bulletin valide, de la formule consommateur, non anonymise. Les
     * bulletins en attente n'y sont pas : on ne fait pas payer un euro a
     * quelqu'un dont l'adhesion n'est pas encore actee.
     *
     * @return list<MembershipApplication>
     */
    public function findConsumers(int $season): array
    {
        [$from, $to] = self::seasonBounds($season);

        /** @var list<MembershipApplication> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.status = :validated')
            ->andWhere('a.membershipType = :consumer')
            ->andWhere('a.anonymizedAt IS NULL')
            ->andWhere('a.submittedAt >= :from AND a.submittedAt < :to')
            ->setParameter('validated', MembershipStatus::Validated)
            ->setParameter('consumer', MembershipType::Consumer)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.lastName', 'ASC')
            ->addOrderBy('a.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Les bulletins valides d'une saison, avec leur adherent : la matiere d'un
     * envoi de courriel. Un bulletin anonymise n'a plus ni nom ni adresse, et
     * n'est pas rendu.
     *
     * @return list<MembershipApplication>
     */
    public function findValidatedInSeason(int $season): array
    {
        [$from, $to] = self::seasonBounds($season);

        /** @var list<MembershipApplication> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('m')
            ->join('a.member', 'm')
            ->andWhere('a.status = :validated')
            ->andWhere('a.anonymizedAt IS NULL')
            ->andWhere('a.submittedAt >= :from AND a.submittedAt < :to')
            ->setParameter('validated', MembershipStatus::Validated)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.lastName', 'ASC')
            ->addOrderBy('a.firstName', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
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
