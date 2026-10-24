<?php

namespace App\Entity;

use App\Repository\MailCampaignRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un message envoye aux adherents d'une saison : ce qui a ete dit, a quelle
 * partie des adherents, par qui et quand.
 *
 * C'est la trace dont dispose le bureau — et la tresorerie, en cas de question
 * d'un adherent — pour savoir ce qui est parti. Elle ne garde aucun
 * destinataire : ni nom ni adresse, seulement des comptes. Une liste d'adresses
 * conservee a cote des adhesions survivrait a leur anonymisation.
 *
 * Le texte est celui saisi, variables non remplacees (« Bonjour {{prenom}} ») :
 * c'est ce que le bureau a ecrit, pas ce que chacun a lu.
 */
#[ORM\Entity(repositoryClass: MailCampaignRepository::class)]
#[ORM\Table(name: 'mail_campaign')]
#[ORM\Index(name: 'idx_mail_campaign_sent_at', columns: ['sent_at'])]
class MailCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $body;

    /** Millesime de la saison visee (2027 pour octobre 2026 a septembre 2027). */
    #[ORM\Column]
    private int $season;

    #[ORM\Column]
    private bool $includesAnnual;

    #[ORM\Column]
    private bool $includesConsumers;

    /** Messages mis en file d'envoi : un par adresse distincte. */
    #[ORM\Column]
    private int $recipientCount;

    /** Adherents de la saison sans aucune adresse a laquelle ecrire. */
    #[ORM\Column]
    private int $skippedWithoutAddress;

    /** Adherents ecartes parce qu'ils ont demande a ne plus recevoir de messages. */
    #[ORM\Column]
    private int $skippedOptedOut;

    /** Adherents dont l'adresse est deja celle d'un autre : un seul message part. */
    #[ORM\Column]
    private int $sharedAddress;

    /** Identifiant de l'administrateur, jamais son nom : voir MembershipEvent. */
    #[ORM\Column(nullable: true)]
    private ?int $sentById;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;

    public function __construct(
        string $subject,
        string $body,
        int $season,
        bool $includesAnnual,
        bool $includesConsumers,
        int $recipientCount,
        int $skippedWithoutAddress,
        int $skippedOptedOut,
        int $sharedAddress,
        ?int $sentById,
        ?\DateTimeImmutable $sentAt = null,
    ) {
        $this->subject = $subject;
        $this->body = $body;
        $this->season = $season;
        $this->includesAnnual = $includesAnnual;
        $this->includesConsumers = $includesConsumers;
        $this->recipientCount = $recipientCount;
        $this->skippedWithoutAddress = $skippedWithoutAddress;
        $this->skippedOptedOut = $skippedOptedOut;
        $this->sharedAddress = $sharedAddress;
        $this->sentById = $sentById;
        $this->sentAt = $sentAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSeason(): int
    {
        return $this->season;
    }

    public function includesAnnual(): bool
    {
        return $this->includesAnnual;
    }

    public function includesConsumers(): bool
    {
        return $this->includesConsumers;
    }

    public function getRecipientCount(): int
    {
        return $this->recipientCount;
    }

    public function getSkippedWithoutAddress(): int
    {
        return $this->skippedWithoutAddress;
    }

    public function getSkippedOptedOut(): int
    {
        return $this->skippedOptedOut;
    }

    public function getSharedAddress(): int
    {
        return $this->sharedAddress;
    }

    public function getSentById(): ?int
    {
        return $this->sentById;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
