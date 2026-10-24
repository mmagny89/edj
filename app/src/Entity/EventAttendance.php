<?php

namespace App\Entity;

use App\Enum\PaymentMethod;
use App\Repository\EventAttendanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le passage d'un consommateur a un evenement, et le reglement qui va avec.
 *
 * La formule consommateur est gratuite a l'annee et se paie 1 € par
 * evenement : c'est ici que cet euro est enregistre, et c'est la seule trace
 * dont dispose la tresorerie pour ces encaissements.
 *
 * Presence et reglement sont deux faits distincts. Une personne peut etre
 * venue sans avoir paye — elle apparait alors dans les impayes de la section
 * comptable — d'ou un paidAt nul plutot qu'un montant a zero : zero euro paye
 * et euro non encore paye ne se lisent pas pareil dans un journal de caisse.
 */
#[ORM\Entity(repositoryClass: EventAttendanceRepository::class)]
#[ORM\Table(name: 'event_attendance')]
#[ORM\UniqueConstraint(name: 'uniq_event_attendance_personne', columns: ['event_id', 'application_id'])]
#[ORM\Index(name: 'idx_event_attendance_event', columns: ['event_id'])]
class EventAttendance
{
    /** Ce que doit un consommateur par evenement, en centimes. */
    public const PRICE_CENTS = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    /**
     * Le bulletin, et non la personne : c'est lui qui porte la formule et la
     * saison, et c'est sur lui que se lit le droit au tarif consommateur.
     */
    #[ORM\ManyToOne(targetEntity: MembershipApplication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MembershipApplication $application;

    /** En centimes, pour ne jamais arrondir un total. */
    #[ORM\Column(options: ['default' => self::PRICE_CENTS])]
    private int $amountCents = self::PRICE_CENTS;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 20, enumType: PaymentMethod::class, nullable: true)]
    private ?PaymentMethod $paymentMethod = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $recordedAt;

    /**
     * L'identifiant de qui a pointe, jamais son nom : comme l'historique d'un
     * bulletin, cette ligne survit a la suppression du compte.
     */
    #[ORM\Column(nullable: true)]
    private ?int $recordedById = null;

    public function __construct(
        Event $event,
        MembershipApplication $application,
        ?int $recordedById = null,
        ?\DateTimeImmutable $recordedAt = null,
    ) {
        $this->event = $event;
        $this->application = $application;
        $this->recordedById = $recordedById;
        $this->recordedAt = $recordedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getApplication(): MembershipApplication
    {
        return $this->application;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function isPaid(): bool
    {
        return null !== $this->paidAt;
    }

    public function getPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getRecordedById(): ?int
    {
        return $this->recordedById;
    }

    /**
     * Le montant reellement encaisse : zero tant que le reglement n'est pas
     * enregistre, pour qu'un total se calcule sans condition a cote.
     */
    public function collectedCents(): int
    {
        return $this->isPaid() ? $this->amountCents : 0;
    }

    public function markPaid(PaymentMethod $method = PaymentMethod::Cash, ?\DateTimeImmutable $at = null): static
    {
        $this->paidAt = $at ?? new \DateTimeImmutable();
        $this->paymentMethod = $method;

        return $this;
    }

    public function markUnpaid(): static
    {
        $this->paidAt = null;
        $this->paymentMethod = null;

        return $this;
    }
}
