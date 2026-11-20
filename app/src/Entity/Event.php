<?php

namespace App\Entity;

use App\Enum\EventType;
use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /**
     * LudiPassion (boutique de jeux) tient-elle un stand ? Null tant que ce
     * n'est pas renseigne : « on ne sait pas encore » n'est pas « non ».
     */
    #[ORM\Column(nullable: true)]
    private ?bool $ludipassionPresent = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $raffleEnabled = false;

    /**
     * Prix du ticket en centimes : un entier, pas un flottant, pour qu'un total
     * ne derive jamais d'un centime.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le prix du ticket doit être supérieur à zéro.')]
    private ?int $raffleTicketPriceCents = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le nombre de tickets doit être supérieur à zéro.')]
    private ?int $raffleTicketCount = null;

    /**
     * Lots de la tombola, un par ligne.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: 'La liste des lots ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $rafflePrizes = null;

    /**
     * @var Collection<int, EventContribution>
     */
    #[ORM\OneToMany(targetEntity: EventContribution::class, mappedBy: 'event', cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $contributions;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->contributions = new ArrayCollection();
    }

    /**
     * Les lots, un par ligne, sans lignes vides.
     *
     * @return list<string>
     */
    public function rafflePrizeList(): array
    {
        $lines = preg_split('/\R/', (string) $this->rafflePrizes) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => '' !== $line));
    }

    /**
     * Prix du ticket ecrit pour etre lu : « 2 € » ou « 1,50 € ».
     */
    public function raffleTicketPriceLabel(): ?string
    {
        if (null === $this->raffleTicketPriceCents) {
            return null;
        }

        $euros = $this->raffleTicketPriceCents / 100;

        return rtrim(rtrim(number_format($euros, 2, ',', ' '), '0'), ',').' €';
    }

    #[Assert\Callback]
    public function validateRaffle(ExecutionContextInterface $context): void
    {
        if (!$this->raffleEnabled) {
            return;
        }

        if (null === $this->raffleTicketPriceCents) {
            $context->buildViolation('Indiquez le prix du ticket de tombola.')->atPath('raffleTicketPriceCents')->addViolation();
        }

        if ([] === $this->rafflePrizeList()) {
            $context->buildViolation('Indiquez au moins un lot.')->atPath('rafflePrizes')->addViolation();
        }
    }

    public function isLudipassionPresent(): ?bool
    {
        return $this->ludipassionPresent;
    }

    public function setLudipassionPresent(?bool $ludipassionPresent): static
    {
        $this->ludipassionPresent = $ludipassionPresent;

        return $this;
    }

    public function isRaffleEnabled(): bool
    {
        return $this->raffleEnabled;
    }

    public function setRaffleEnabled(bool $raffleEnabled): static
    {
        $this->raffleEnabled = $raffleEnabled;

        return $this;
    }

    public function getRaffleTicketPriceCents(): ?int
    {
        return $this->raffleTicketPriceCents;
    }

    public function setRaffleTicketPriceCents(?int $cents): static
    {
        $this->raffleTicketPriceCents = $cents;

        return $this;
    }

    public function getRaffleTicketCount(): ?int
    {
        return $this->raffleTicketCount;
    }

    public function setRaffleTicketCount(?int $count): static
    {
        $this->raffleTicketCount = $count;

        return $this;
    }

    public function getRafflePrizes(): ?string
    {
        return $this->rafflePrizes;
    }

    public function setRafflePrizes(?string $rafflePrizes): static
    {
        $this->rafflePrizes = $rafflePrizes;

        return $this;
    }

    /**
     * @return Collection<int, EventContribution>
     */
    public function getContributions(): Collection
    {
        return $this->contributions;
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
