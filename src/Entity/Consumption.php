<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Consumption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Event $event;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(name: 'member_number', referencedColumnName: 'member_number', nullable: false)]
    private Member $member;

    #[ORM\ManyToOne(targetEntity: Consumable::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Consumable $consumable;

    #[ORM\Column(type: 'integer')]
    private int $quantity; // Quantité consommée

    #[ORM\Column(type: 'datetime')]
    private \DateTime $consumedAt; // Date/heure de la consommation

    // Getters et setters
    public function getId(): int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): self
    {
        $this->event = $event;
        return $this;
    }

    public function getMember(): Member
    {
        return $this->member;
    }

    public function setMember(Member $member): self
    {
        $this->member = $member;
        return $this;
    }

    public function getConsumable(): Consumable
    {
        return $this->consumable;
    }

    public function setConsumable(Consumable $consumable): self
    {
        $this->consumable = $consumable;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getConsumedAt(): \DateTime
    {
        return $this->consumedAt;
    }

    public function setConsumedAt(\DateTime $consumedAt): self
    {
        $this->consumedAt = $consumedAt;
        return $this;
    }
}
