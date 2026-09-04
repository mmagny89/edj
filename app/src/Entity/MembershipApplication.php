<?php

namespace App\Entity;

use App\Enum\MembershipType;
use App\Repository\MembershipApplicationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Bulletin d'adhésion depose depuis le site, reprenant le formulaire papier
 * de l'association. Une adhesion = un bulletin : les membres d'une famille
 * remplissent chacun le leur et designent le meme adherent principal.
 */
#[ORM\Entity(repositoryClass: MembershipApplicationRepository::class)]
class MembershipApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'Le nom ne peut pas depasser {{ limit }} caracteres.')]
    private ?string $lastName = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'Le prénom ne peut pas depasser {{ limit }} caracteres.')]
    private ?string $firstName = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull(message: 'La date de naissance est obligatoire.')]
    #[Assert\LessThan('today', message: 'La date de naissance doit être passée.')]
    #[Assert\GreaterThan('-120 years', message: 'Cette date de naissance ne semble pas correcte.')]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $address = null;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank(message: 'Le code postal est obligatoire.')]
    #[Assert\Regex(pattern: '/^\d{5}$/', message: 'Le code postal doit comporter cinq chiffres.')]
    private ?string $postalCode = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville est obligatoire.')]
    #[Assert\Length(max: 100)]
    private ?string $city = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[Assert\Regex(
        pattern: '/^[0-9 .+()-]{6,}$/',
        message: 'Ce numéro de téléphone ne semble pas valide.',
    )]
    private ?string $phone = null;

    /**
     * Obligatoire alors que le bulletin papier le laisse facultatif : depose
     * en ligne, c'est le seul moyen pour l'association de repondre.
     */
    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: "L'adresse e-mail est obligatoire.")]
    #[Assert\Email(message: "Cette adresse e-mail n'est pas valide.")]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 20, enumType: MembershipType::class)]
    #[Assert\NotNull(message: "Choisissez une formule d'adhésion.")]
    private ?MembershipType $membershipType = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $principalMemberName = null;

    #[ORM\Column]
    private bool $philibertConsent = false;

    /**
     * Formulation du bulletin papier : la case cochee vaut refus. On conserve
     * ce sens plutot que de l'inverser en "j'accepte", pour qu'un bulletin
     * papier et un bulletin en ligne se lisent de la meme facon.
     */
    #[ORM\Column]
    private bool $imageRightsRefused = false;

    #[ORM\Column]
    #[Assert\IsTrue(message: "Vous devez déclarer avoir lu et accepté les statuts.")]
    private bool $statutesAccepted = false;

    #[ORM\Column]
    #[Assert\IsTrue(message: "Vous devez déclarer avoir lu et accepté le règlement intérieur.")]
    private bool $rulesAccepted = false;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $guardianLastName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $guardianFirstName = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: "Cette adresse e-mail n'est pas valide.")]
    #[Assert\Length(max: 180)]
    private ?string $guardianEmail = null;

    /**
     * "Fait le ... a ..." du bulletin papier : la date est celle du depot,
     * le lieu est saisi.
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Indiquez le lieu de signature.')]
    #[Assert\Length(max: 100)]
    private ?string $signaturePlace = null;

    /**
     * Signature electronique : le nom saisi doit correspondre a celui du
     * bulletin, ou a celui du responsable legal pour un mineur.
     */
    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Signez en saisissant votre nom et prénom.')]
    #[Assert\Length(max: 200)]
    private ?string $signatureName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $submittedAt;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function isMinor(?\DateTimeImmutable $reference = null): bool
    {
        if (null === $this->birthDate) {
            return false;
        }

        return $this->birthDate->diff($reference ?? new \DateTimeImmutable())->y < 18;
    }

    /**
     * Les deux regles conditionnelles du bulletin papier : l'adherent
     * principal n'a de sens que pour une adhesion famille, et un mineur ne
     * peut adherer sans responsable legal.
     */
    #[Assert\Callback]
    public function validateConditionalFields(ExecutionContextInterface $context): void
    {
        if (MembershipType::Family === $this->membershipType && !$this->principalMemberName) {
            $context->buildViolation("Indiquez le prénom et le nom de l'adhérent principal de la famille.")
                ->atPath('principalMemberName')
                ->addViolation();
        }

        if (!$this->isMinor()) {
            return;
        }

        foreach ([
            'guardianLastName' => 'Indiquez le nom du responsable légal.',
            'guardianFirstName' => 'Indiquez le prénom du responsable légal.',
            'guardianEmail' => "Indiquez l'adresse e-mail du responsable légal.",
        ] as $field => $message) {
            if (!$this->{$field}) {
                $context->buildViolation($message)->atPath($field)->addViolation();
            }
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getMembershipType(): ?MembershipType
    {
        return $this->membershipType;
    }

    public function setMembershipType(?MembershipType $membershipType): static
    {
        $this->membershipType = $membershipType;

        return $this;
    }

    public function getPrincipalMemberName(): ?string
    {
        return $this->principalMemberName;
    }

    public function setPrincipalMemberName(?string $principalMemberName): static
    {
        $this->principalMemberName = $principalMemberName;

        return $this;
    }

    public function isPhilibertConsent(): bool
    {
        return $this->philibertConsent;
    }

    public function setPhilibertConsent(bool $philibertConsent): static
    {
        $this->philibertConsent = $philibertConsent;

        return $this;
    }

    public function isImageRightsRefused(): bool
    {
        return $this->imageRightsRefused;
    }

    public function setImageRightsRefused(bool $imageRightsRefused): static
    {
        $this->imageRightsRefused = $imageRightsRefused;

        return $this;
    }

    public function isStatutesAccepted(): bool
    {
        return $this->statutesAccepted;
    }

    public function setStatutesAccepted(bool $statutesAccepted): static
    {
        $this->statutesAccepted = $statutesAccepted;

        return $this;
    }

    public function isRulesAccepted(): bool
    {
        return $this->rulesAccepted;
    }

    public function setRulesAccepted(bool $rulesAccepted): static
    {
        $this->rulesAccepted = $rulesAccepted;

        return $this;
    }

    public function getGuardianLastName(): ?string
    {
        return $this->guardianLastName;
    }

    public function setGuardianLastName(?string $guardianLastName): static
    {
        $this->guardianLastName = $guardianLastName;

        return $this;
    }

    public function getGuardianFirstName(): ?string
    {
        return $this->guardianFirstName;
    }

    public function setGuardianFirstName(?string $guardianFirstName): static
    {
        $this->guardianFirstName = $guardianFirstName;

        return $this;
    }

    public function getGuardianEmail(): ?string
    {
        return $this->guardianEmail;
    }

    public function setGuardianEmail(?string $guardianEmail): static
    {
        $this->guardianEmail = $guardianEmail;

        return $this;
    }

    public function getSignaturePlace(): ?string
    {
        return $this->signaturePlace;
    }

    public function setSignaturePlace(?string $signaturePlace): static
    {
        $this->signaturePlace = $signaturePlace;

        return $this;
    }

    public function getSignatureName(): ?string
    {
        return $this->signatureName;
    }

    public function setSignatureName(?string $signatureName): static
    {
        $this->signatureName = $signatureName;

        return $this;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }
}
