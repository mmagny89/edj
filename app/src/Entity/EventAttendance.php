<?php

namespace App\Entity;

use App\Enum\PaymentMethod;
use App\Repository\EventAttendanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le passage d'un consommateur un jour donne, et le reglement qui va avec.
 *
 * Un pointage se rattache a un evenement du calendrier, aujourd'hui ou passe, et
 * porte la date du jour pointe (copiee : elle date la recette et survit a la
 * purge de l'evenement). Une personne n'a qu'un pointage par jour.
 *
 * La formule consommateur est gratuite a l'annee et se paie 1 € par
 * evenement : c'est ici que cet euro est enregistre, et c'est la seule trace
 * dont dispose la tresorerie pour ces encaissements.
 *
 * Presence et reglement sont deux faits distincts. Une personne peut etre
 * venue gratuitement : paidAt reste nul, rien n'est encaisse et rien n'est a
 * relancer. C'est aussi le cas d'un adherent annuel pointe par un
 * administrateur.
 */
#[ORM\Entity(repositoryClass: EventAttendanceRepository::class)]
#[ORM\Table(name: 'event_attendance')]
#[ORM\UniqueConstraint(name: 'uniq_event_attendance_jour', columns: ['application_id', 'occurred_on'])]
#[ORM\Index(name: 'idx_event_attendance_event', columns: ['event_id'])]
#[ORM\Index(name: 'idx_event_attendance_occurred_on', columns: ['occurred_on'])]
class EventAttendance
{
    /** Ce que doit un consommateur par evenement, en centimes. */
    public const PRICE_CENTS = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * L'evenement du calendrier de ce jour-la, quand il y en a un, tant qu'il
     * existe. La purge nocturne supprime les evenements passes : le lien se vide
     * alors (SET NULL) et le pointage reste, porteur de sa propre date et de son
     * propre libelle. Sans cela, la purge emporterait des encaissements que la
     * tresorerie doit garder.
     */
    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Event $event;

    /**
     * Le jour de la seance, copie a la saisie : c'est lui qui date la recette
     * dans le journal, et il survit a l'evenement.
     */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $occurredOn;

    /** Le titre de la seance, copie pour la meme raison. */
    #[ORM\Column(length: 150)]
    private string $label;

    /**
     * Le bulletin, et non la personne : c'est lui qui porte la formule et la
     * saison, et c'est sur lui que se lit le droit au tarif consommateur.
     */
    #[ORM\ManyToOne(targetEntity: MembershipApplication::class, inversedBy: 'attendances')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MembershipApplication $application;

    /** En centimes, pour ne jamais arrondir un total. */
    #[ORM\Column(options: ['default' => self::PRICE_CENTS])]
    private int $amountCents = self::PRICE_CENTS;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 20, enumType: PaymentMethod::class, nullable: true)]
    private ?PaymentMethod $paymentMethod = null;

    /** Le jour ou cet euro a ete depose ou recu sur le compte. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $cashedOn = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $recordedAt;

    /**
     * L'identifiant de qui a pointe, jamais son nom : comme l'historique d'un
     * bulletin, cette ligne survit a la suppression du compte.
     */
    #[ORM\Column(nullable: true)]
    private ?int $recordedById = null;

    /** Libelle d'une seance qui n'est pas au calendrier. */
    public const DEFAULT_LABEL = 'Séance';

    public function __construct(
        \DateTimeImmutable $occurredOn,
        MembershipApplication $application,
        ?Event $event = null,
        ?int $recordedById = null,
        ?\DateTimeImmutable $recordedAt = null,
    ) {
        $this->event = $event;
        // Le jour, sans l'heure : c'est ce que compare l'index unique.
        $this->occurredOn = $occurredOn->setTime(0, 0);
        $this->label = (string) ($event?->getTitle() ?? self::DEFAULT_LABEL);
        $this->application = $application;
        $application->addAttendance($this);
        $this->recordedById = $recordedById;
        $this->recordedAt = $recordedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function getOccurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function getLabel(): string
    {
        return $this->label;
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

    public function getCashedOn(): ?\DateTimeImmutable
    {
        return $this->cashedOn;
    }

    public function recordCashing(?\DateTimeImmutable $on): static
    {
        if (!$this->isPaid()) {
            throw new \LogicException('Une venue gratuite ne s\'encaisse pas.');
        }

        $this->cashedOn = $on?->setTime(0, 0);

        return $this;
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

    public function markFree(): static
    {
        $this->paidAt = null;
        $this->paymentMethod = null;
        $this->cashedOn = null;

        return $this;
    }
}
