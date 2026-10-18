<?php

namespace App\Entity;

use App\Enum\MembershipEventType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une decision du bureau sur un bulletin : validation, annulation, remise en
 * attente, anonymisation.
 *
 * L'historique ne garde que l'identifiant de l'administrateur, jamais son
 * nom : l'adresse se retrouve au moment de l'afficher, et un compte supprime
 * se lit « compte supprime » plutot que de laisser une adresse derriere lui.
 * Il ne garde non plus aucune donnee de l'adherent — c'est ce qui lui permet
 * de survivre a l'anonymisation du bulletin qu'il raconte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership_event')]
#[ORM\Index(name: 'idx_membership_event_application', columns: ['application_id'])]
class MembershipEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MembershipApplication::class, inversedBy: 'history')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MembershipApplication $application;

    #[ORM\Column(length: 20, enumType: MembershipEventType::class)]
    private MembershipEventType $type;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(nullable: true)]
    private ?int $actorId;

    /**
     * Precision de la decision, en clair : le moyen de reglement d'une
     * validation. Nul quand il n'y a rien a preciser.
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $detail;

    public function __construct(
        MembershipApplication $application,
        MembershipEventType $type,
        ?int $actorId,
        ?\DateTimeImmutable $occurredAt = null,
        ?string $detail = null,
    ) {
        $this->application = $application;
        $this->type = $type;
        $this->actorId = $actorId;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
        $this->detail = $detail;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): MembershipEventType
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }
}
