<?php

namespace App\Entity;

use App\Enum\EventType;
use App\Repository\EventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Evenement saisi depuis le back-office et affiche dans le calendrier de la
 * page d'accueil.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Index(name: 'idx_event_starts_at', columns: ['starts_at'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(max: 150, maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $title = null;

    #[ORM\Column(length: 20, enumType: EventType::class)]
    #[Assert\NotNull(message: "Choisissez le type d'événement.")]
    private ?EventType $type = EventType::Special;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    private ?\DateTimeImmutable $startsAt = null;

    /**
     * Renseignee uniquement pour un evenement qui dure plusieurs jours — le
     * week-end du jeu, par exemple.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    /**
     * Horaire tel qu'il s'affiche : "18h30 - 00h", "Soirée", "Journée". Du
     * texte et non deux heures, parce que c'est souvent une plage approximative
     * ou une mention libre.
     */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: "L'horaire est obligatoire.")]
    #[Assert\Length(max: 60)]
    private ?string $timeLabel = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $description = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $location = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Dernier jour ou l'evenement a lieu : c'est lui qui decide s'il est
     * passe. Un week-end du jeu ne doit pas disparaitre du calendrier le
     * samedi soir alors qu'il se poursuit le dimanche.
     */
    public function lastDay(): ?\DateTimeImmutable
    {
        return $this->endsAt ?? $this->startsAt;
    }

    #[Assert\Callback]
    public function validateDateRange(ExecutionContextInterface $context): void
    {
        if (null === $this->startsAt || null === $this->endsAt) {
            return;
        }

        if ($this->endsAt < $this->startsAt) {
            $context->buildViolation('La date de fin ne peut pas précéder la date de début.')
                ->atPath('endsAt')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getType(): ?EventType
    {
        return $this->type;
    }

    public function setType(?EventType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getTimeLabel(): ?string
    {
        return $this->timeLabel;
    }

    public function setTimeLabel(?string $timeLabel): static
    {
        $this->timeLabel = $timeLabel;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
