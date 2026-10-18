<?php

namespace App\Service;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Repository\MemberRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\String\u;

/**
 * Rattache un bulletin a un adherent, en creant celui-ci au besoin : c'est ici
 * que naissent les numeros EDJ-0001.
 *
 * Deux bulletins appartiennent a la meme personne quand nom, prenom et date de
 * naissance coincident une fois normalises — accents, casse, traits d'union et
 * espaces multiples mis de cote. Le rapprochement est volontairement strict :
 * mieux vaut deux numeros pour une personne, que deux personnes sous un meme
 * numero, qui donnerait a l'une l'acces a la fiche de l'autre (§ espace
 * adherent).
 *
 * Le rang vient d'une sequence PostgreSQL et non d'un « max + 1 » : nextval
 * est hors transaction, donc deux depots simultanes ne peuvent pas recevoir le
 * meme numero. Le revers est assume : un bulletin abandonne en cours de
 * validation laisse un trou dans la numerotation.
 */
final class MemberDirectory
{
    public const SEQUENCE = 'member_number_seq';

    public function __construct(
        private readonly MemberRepository $members,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Donne au bulletin son adherent — celui qui existe deja, ou un nouveau.
     * Le bulletin n'est pas enregistre ici : l'appelant le persiste comme
     * avant, et l'adherent part au meme flush.
     */
    public function attach(MembershipApplication $application): Member
    {
        $key = self::identityKey(
            (string) $application->getLastName(),
            (string) $application->getFirstName(),
            $application->getBirthDate(),
        );

        $member = $this->members->findOneByIdentityKey($key);
        if (null === $member) {
            $member = new Member($this->nextNumber(), $key);
            $this->entityManager->persist($member);
        }

        $application->setMember($member);

        return $member;
    }

    /**
     * Efface l'empreinte d'identite d'un adherent dont plus aucun bulletin ne
     * porte de donnees personnelles. Sans cela, l'anonymisation laisserait de
     * quoi reconnaitre la personne au depot suivant.
     */
    public function forgetIfFullyAnonymized(Member $member): void
    {
        if (null === $member->getIdentityKey()) {
            return;
        }

        foreach ($member->getApplications() as $application) {
            if (!$application->isAnonymized()) {
                return;
            }
        }

        $member->forgetIdentity();
    }

    public function nextNumber(): string
    {
        $rank = (int) $this->connection->fetchOne(sprintf("SELECT nextval('%s')", self::SEQUENCE));

        return Member::PREFIX.str_pad((string) $rank, 4, '0', \STR_PAD_LEFT);
    }

    /**
     * Empreinte stable d'une identite. Publique et statique : la migration de
     * reprise et les tests la calculent sans conteneur.
     */
    public static function identityKey(string $lastName, string $firstName, ?\DateTimeInterface $birthDate): string
    {
        return hash('sha256', implode('|', [
            self::normalize($lastName),
            self::normalize($firstName),
            $birthDate?->format('Y-m-d') ?? '',
        ]));
    }

    private static function normalize(string $value): string
    {
        return u($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->toString();
    }
}
