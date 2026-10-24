<?php

namespace App\Entity;

use App\Repository\MemberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un adherent, au sens d'une personne — par opposition au bulletin, qui est
 * l'acte d'adherer pour une saison. Une personne qui readhere l'annee suivante
 * depose un second bulletin et garde le meme numero : c'est tout l'objet de
 * cette table.
 *
 * Le rapprochement se fait sur une empreinte d'identite (voir identityKey) et
 * non sur l'adresse e-mail : une famille partage souvent une seule adresse, et
 * une adresse change au fil des annees.
 */
#[ORM\Entity(repositoryClass: MemberRepository::class)]
#[ORM\Table(name: 'member')]
#[ORM\UniqueConstraint(name: 'uniq_member_number', columns: ['number'])]
#[ORM\UniqueConstraint(name: 'uniq_member_identity_key', columns: ['identity_key'])]
class Member
{
    /**
     * Prefixe du numero d'adherent, puis le rang sur au moins quatre chiffres :
     * EDJ-0001. Au-dela de 9999, le numero s'allonge sans rien casser, la
     * comparaison se faisant toujours sur la chaine entiere.
     */
    public const PREFIX = 'EDJ-';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $number;

    /**
     * Empreinte de l'identite — nom, prenom et date de naissance normalises,
     * condenses — qui sert a reconnaitre la meme personne d'une saison a
     * l'autre. Elle n'est pas reversible, mais reste une donnee personnelle :
     * elle est effacee des que tous les bulletins de l'adherent sont
     * anonymises, et le numero survit seul, ne designant plus personne.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $identityKey = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * Date a laquelle la personne a demande a ne plus recevoir les messages de
     * l'association, nulle tant qu'elle les accepte. Sur l'adherent et non sur le
     * bulletin : le refus vaut pour toutes ses saisons, et survit a un
     * renouvellement. Les courriels de gestion — confirmation d'adhesion, lien
     * d'acces a sa fiche — ne sont pas des messages de l'association et ne sont
     * pas concernes.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $mailingOptOutAt = null;

    /**
     * @var Collection<int, MembershipApplication>
     */
    #[ORM\OneToMany(targetEntity: MembershipApplication::class, mappedBy: 'member')]
    private Collection $applications;

    public function __construct(string $number, ?string $identityKey = null)
    {
        $this->number = $number;
        $this->identityKey = $identityKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->applications = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getIdentityKey(): ?string
    {
        return $this->identityKey;
    }

    /**
     * Remplace l'empreinte d'identite apres une correction de nom ou de date de
     * naissance : sans cela, le prochain renouvellement de la personne, saisi
     * sous son vrai nom, ne la retrouverait pas et lui donnerait un second
     * numero.
     */
    public function reidentify(string $identityKey): static
    {
        $this->identityKey = $identityKey;

        return $this;
    }

    public function isOptedOutOfMailings(): bool
    {
        return null !== $this->mailingOptOutAt;
    }

    public function getMailingOptOutAt(): ?\DateTimeImmutable
    {
        return $this->mailingOptOutAt;
    }

    /**
     * Sans effet si la personne s'est deja desinscrite : la date d'origine est
     * celle qui compte, et un second clic sur le lien ne la repousse pas.
     */
    public function optOutOfMailings(?\DateTimeImmutable $now = null): static
    {
        $this->mailingOptOutAt ??= $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function optBackIntoMailings(): static
    {
        $this->mailingOptOutAt = null;

        return $this;
    }

    public function forgetIdentity(): static
    {
        $this->identityKey = null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, MembershipApplication>
     */
    public function getApplications(): Collection
    {
        return $this->applications;
    }
}
