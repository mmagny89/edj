<?php

namespace App\Entity;

use App\Repository\EventContributionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ce qu'une personne propose d'apporter a une nuit ou un week-end du jeu :
 * des jeux, a boire, a manger.
 *
 * Saisie sans compte, depuis la page publique de l'evenement : un prenom et un
 * objet, rien d'autre. Elle disparait avec l'evenement (suppression en cascade
 * en base).
 */
#[ORM\Entity(repositoryClass: EventContributionRepository::class)]
#[ORM\Index(name: 'idx_event_contribution_event', columns: ['event_id'])]
class EventContribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'contributions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Event $event = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Indiquez votre prénom.')]
    #[Assert\Length(max: 60, maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $name = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez ce que vous apportez.')]
    #[Assert\Length(max: 120, maxMessage: 'Le texte ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $item = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Event $event = null)
    {
        $this->event = $event;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = null === $name ? null : trim($name);

        return $this;
    }

    public function getItem(): ?string
    {
        return $this->item;
    }

    public function setItem(?string $item): static
    {
        $this->item = null === $item ? null : trim($item);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
