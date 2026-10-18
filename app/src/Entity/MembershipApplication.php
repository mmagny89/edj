<?php

namespace App\Entity;

use App\Enum\MembershipSource;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Repository\MembershipApplicationRepository;
use App\Service\MemberDirectory;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Bulletin d'adhésion depose depuis le site, reprenant le formulaire papier
 * de l'association. Une adhesion = un bulletin : les membres d'une famille
 * remplissent chacun le leur et designent le meme adherent principal.
 */
#[ORM\Entity(repositoryClass: MembershipApplicationRepository::class)]
// L'index existe depuis la migration initiale (la liste se trie sur la date de
// depot) ; le declarer ici evite que Doctrine veuille le supprimer.
#[ORM\Index(name: 'idx_membership_application_submitted_at', columns: ['submitted_at'])]
#[ORM\Index(name: 'idx_membership_application_member', columns: ['member_id'])]
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
    #[Assert\LessThanOrEqual('tomorrow', message: 'La date ne peut pas être dans le futur.')]
    private \DateTimeImmutable $submittedAt;

    /**
     * Bulletin rempli par l'adherent sur le site, ou saisi par un
     * administrateur d'apres une fiche papier.
     */
    #[ORM\Column(length: 10, enumType: MembershipSource::class, options: ['default' => 'en_ligne'])]
    private MembershipSource $source = MembershipSource::Online;

    /**
     * Identifiant de l'administrateur qui a saisi une fiche papier, pour savoir
     * qui a engage l'association. Nul pour un bulletin en ligne.
     */
    #[ORM\Column(nullable: true)]
    private ?int $enteredById = null;

    /**
     * Date de l'anonymisation, nulle tant que le bulletin porte des donnees
     * personnelles. Une ligne anonymisee est conservee — la formule, les
     * autorisations et la date servent encore aux statistiques de
     * l'association — mais elle ne designe plus personne.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $anonymizedAt = null;

    /**
     * L'adherent, au sens de la personne : c'est lui qui porte le numero
     * EDJ-0001, stable d'une saison a l'autre. Nul uniquement le temps qu'un
     * bulletin soit rattache (voir MemberDirectory).
     */
    #[ORM\ManyToOne(targetEntity: Member::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Member $member = null;

    #[ORM\Column(length: 20, enumType: MembershipStatus::class, options: ['default' => 'en_attente'])]
    private MembershipStatus $status = MembershipStatus::Pending;

    /**
     * Moyen de reglement de la cotisation, renseigne a la validation. Nul pour
     * une adhesion consommateur, gratuite a l'annee.
     */
    #[ORM\Column(length: 20, enumType: PaymentMethod::class, nullable: true)]
    private ?PaymentMethod $paymentMethod = null;

    /**
     * Date du dernier changement d'etat, et identifiant de l'administrateur qui
     * l'a decide. L'association doit pouvoir dire qui a valide un encaissement
     * ou annule une adhesion.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $statusChangedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $statusChangedById = null;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function setMember(?Member $member): static
    {
        $this->member = $member;

        return $this;
    }

    public function getMemberNumber(): ?string
    {
        return $this->member?->getNumber();
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function isValidated(): bool
    {
        return MembershipStatus::Validated === $this->status;
    }

    public function isCancelled(): bool
    {
        return MembershipStatus::Cancelled === $this->status;
    }

    public function isPending(): bool
    {
        return MembershipStatus::Pending === $this->status;
    }

    public function getPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getStatusChangedAt(): ?\DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    public function getStatusChangedById(): ?int
    {
        return $this->statusChangedById;
    }

    /**
     * Valide l'adhesion : la cotisation est encaissee, ou la formule est
     * gratuite. Le moyen de reglement est obligatoire des que la formule est
     * payante — c'est la seule trace de l'encaissement cote site.
     */
    public function validate(?PaymentMethod $paymentMethod, ?int $adminId, ?\DateTimeImmutable $now = null): static
    {
        if (MembershipType::Consumer !== $this->membershipType && null === $paymentMethod) {
            throw new \LogicException('Une adhesion payante ne se valide pas sans moyen de reglement.');
        }

        $this->status = MembershipStatus::Validated;
        $this->paymentMethod = $paymentMethod;

        return $this->recordStatusChange($adminId, $now);
    }

    /**
     * Annule l'adhesion. Le moyen de reglement est efface : il ne designerait
     * plus qu'un encaissement qui n'a pas lieu d'etre, ou qui a ete rembourse.
     */
    public function cancel(?int $adminId, ?\DateTimeImmutable $now = null): static
    {
        $this->status = MembershipStatus::Cancelled;
        $this->paymentMethod = null;

        return $this->recordStatusChange($adminId, $now);
    }

    /**
     * Remet le bulletin en attente : rattrape une validation ou une annulation
     * decidee par erreur.
     */
    public function reopen(?int $adminId, ?\DateTimeImmutable $now = null): static
    {
        $this->status = MembershipStatus::Pending;
        $this->paymentMethod = null;

        return $this->recordStatusChange($adminId, $now);
    }

    private function recordStatusChange(?int $adminId, ?\DateTimeImmutable $now): static
    {
        $this->statusChangedAt = $now ?? new \DateTimeImmutable();
        $this->statusChangedById = $adminId;

        return $this;
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
        if (MembershipType::Family === $this->membershipType) {
            if (!$this->principalMemberName) {
                $context->buildViolation("Indiquez le prénom et le nom de l'adhérent principal de la famille.")
                    ->atPath('principalMemberName')
                    ->addViolation();
            } elseif ($this->designatesSelfAsPrincipal()) {
                // L'adhesion famille a 5 € se greffe sur une adhesion
                // individuelle a 10 € deja prise par quelqu'un d'autre du
                // foyer. Se designer soi-meme, c'est declarer une famille d'une
                // seule personne : l'association encaisserait 5 € la ou elle
                // doit en encaisser 10.
                $context->buildViolation("L'adhérent principal est la personne de votre foyer qui a déjà pris l'adhésion individuelle à 10 € : ce ne peut pas être vous.")
                    ->atPath('principalMemberName')
                    ->addViolation();
            }
        }

        if (!$this->isMinor()) {
            return;
        }

        // Un mineur n'adhere jamais pour son propre compte : il se rattache a
        // l'adhesion individuelle d'un adulte de son foyer, par la formule
        // famille. L'adhesion individuelle engage son titulaire aupres de
        // l'association, ce qu'un mineur ne peut pas faire seul.
        if (MembershipType::Individual === $this->membershipType) {
            $context->buildViolation("Un mineur ne peut pas prendre l'adhésion individuelle : choisissez l'adhésion famille, rattachée à l'adulte de votre foyer qui a déjà adhéré.")
                ->atPath('membershipType')
                ->addViolation();
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

    /**
     * Vrai quand le nom saisi comme adherent principal est celui du titulaire
     * du bulletin, a la casse, aux accents et aux traits d'union pres, et quel
     * que soit l'ordre prenom / nom.
     */
    public function designatesSelfAsPrincipal(): bool
    {
        if (!$this->principalMemberName) {
            return false;
        }

        $principal = MemberDirectory::normalize($this->principalMemberName);
        $parts = [MemberDirectory::normalize((string) $this->firstName), MemberDirectory::normalize((string) $this->lastName)];

        return \in_array($principal, [implode(' ', $parts), implode(' ', array_reverse($parts))], true);
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

    public function getAnonymizedAt(): ?\DateTimeImmutable
    {
        return $this->anonymizedAt;
    }

    public function isAnonymized(): bool
    {
        return null !== $this->anonymizedAt;
    }

    /**
     * Efface tout ce qui designe une personne, de facon irreversible : nom,
     * coordonnees, signature, responsable legal, adherent principal.
     *
     * Ce qui reste : la formule, les deux autorisations, la date de depot et
     * l'annee de naissance — ramenee au 1er janvier, de quoi distinguer un
     * mineur d'un adulte dans les statistiques sans retrouver une date exacte.
     * L'adresse e-mail est remplacee par une adresse unique du domaine
     * reserve « .invalid », qui ne peut recevoir aucun courrier.
     *
     * Idempotente : anonymiser deux fois ne change rien, et conserve la date
     * de la premiere anonymisation.
     */
    public function anonymize(?\DateTimeImmutable $now = null): static
    {
        if ($this->isAnonymized()) {
            return $this;
        }

        $this->lastName = 'ANONYMISÉ';
        $this->firstName = '';
        $this->birthDate = $this->birthDate?->setDate((int) $this->birthDate->format('Y'), 1, 1);
        $this->address = null;
        $this->postalCode = '00000';
        $this->city = '—';
        $this->phone = null;
        $this->email = sprintf('anonyme-%d@anonyme.invalid', $this->id ?? 0);
        $this->principalMemberName = null;
        $this->guardianLastName = null;
        $this->guardianFirstName = null;
        $this->guardianEmail = null;
        $this->signaturePlace = '—';
        $this->signatureName = '—';
        $this->anonymizedAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function getSource(): MembershipSource
    {
        return $this->source;
    }

    public function isPaper(): bool
    {
        return MembershipSource::Paper === $this->source;
    }

    public function getEnteredById(): ?int
    {
        return $this->enteredById;
    }

    /**
     * Marque le bulletin comme saisi d'apres une fiche papier remise a
     * l'association, par l'administrateur donne.
     */
    public function enterFromPaper(?int $adminId): static
    {
        $this->source = MembershipSource::Paper;
        $this->enteredById = $adminId;

        return $this;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }
}
