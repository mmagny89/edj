<?php

namespace App\Entity;

use App\Enum\MembershipEventType;
use App\Enum\MembershipSource;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Repository\MembershipApplicationRepository;
use App\Service\MemberDirectory;
use App\Service\MembershipSeasons;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    /** En dessous de cet age, l'adhesion est gratuite. */
    public const FREE_UNDER_AGE = 8;

    /**
     * Ce qu'un champ de texte libre ne peut pas contenir : un caractere de
     * controle (retour a la ligne, octet nul, caractere invisible) ou un chevron.
     * Un nom, une adresse ou une ville n'en ont jamais l'usage ; ils ne servent
     * qu'a glisser du balisage ou de quoi fausser un courriel, un export ou un
     * tableur. Les lettres accentuees, apostrophes, tirets et chiffres passent.
     */
    private const FREE_TEXT = '/^[^\p{C}<>]*$/u';

    private const FREE_TEXT_MESSAGE = 'Ce champ contient des caractères non autorisés.';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'Le nom ne peut pas depasser {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
    private ?string $lastName = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'Le prénom ne peut pas depasser {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
    private ?string $firstName = null;

    // Obligatoire pour un bulletin en ligne ou une fiche papier (voir
    // validateConditionalFields), facultative pour une personne reprise d'un fichier.
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Assert\LessThan('today', message: 'La date de naissance doit être passée.')]
    #[Assert\GreaterThan('-120 years', message: 'Cette date de naissance ne semble pas correcte.')]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
    private ?string $address = null;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank(message: 'Le code postal est obligatoire.')]
    #[Assert\Regex(pattern: '/^\d{5}$/', message: 'Le code postal doit comporter cinq chiffres.')]
    private ?string $postalCode = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville est obligatoire.')]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
    private ?string $city = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[Assert\Regex(
        pattern: '/^[0-9 .+()-]{6,}$/',
        message: 'Ce numéro de téléphone ne semble pas valide.',
    )]
    private ?string $phone = null;

    /**
     * Obligatoire pour un bulletin depose en ligne — c'est le seul moyen pour
     * l'association de repondre —, facultative pour une fiche papier ou un fichier
     * repris : beaucoup d'adherents n'en ont pas, ou n'en ont jamais donne. Sans
     * adresse, la personne n'a ni confirmation ni lien d'acces ; le bureau la joint
     * autrement. Voir validateConditionalFields().
     */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: "Cette adresse e-mail n'est pas valide.")]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 20, enumType: MembershipType::class)]
    #[Assert\NotNull(message: "Choisissez une formule d'adhésion.")]
    private ?MembershipType $membershipType = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
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
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
    private ?string $guardianLastName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
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
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
    private ?string $signaturePlace = null;

    /**
     * Signature electronique : le nom saisi doit correspondre a celui du
     * bulletin, ou a celui du responsable legal pour un mineur.
     */
    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Signez en saisissant votre nom et prénom.')]
    #[Assert\Length(max: 200)]
    #[Assert\Regex(pattern: self::FREE_TEXT, message: self::FREE_TEXT_MESSAGE)]
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

    /**
     * Les decisions du bureau sur ce bulletin, de la plus ancienne a la plus
     * recente : qui a valide, annule, remis en attente ou anonymise, et quand.
     *
     * @var Collection<int, MembershipEvent>
     */
    #[ORM\OneToMany(targetEntity: MembershipEvent::class, mappedBy: 'application', cascade: ['persist'])]
    #[ORM\OrderBy(['occurredAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $history;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
        $this->history = new ArrayCollection();
    }

    /**
     * Brouillon du bulletin de la saison suivante, a partir du precedent : tout
     * ce qui se reutilise est repris, rattache au meme adherent — donc au meme
     * numero, sans repasser par le rapprochement d'identite, qu'une faute de
     * frappe corrigee aurait fait echouer.
     *
     * Ne sont PAS repris : la signature, le lieu et l'acceptation des statuts
     * et du reglement. Un renouvellement est un nouvel engagement : il se signe
     * et se coche de nouveau, le reglement ayant pu changer entre-temps.
     */
    public static function renewalOf(self $previous): self
    {
        $draft = new self();
        $draft->member = $previous->member;
        $draft->lastName = $previous->lastName;
        $draft->firstName = $previous->firstName;
        $draft->birthDate = $previous->birthDate;
        $draft->address = $previous->address;
        $draft->postalCode = $previous->postalCode;
        $draft->city = $previous->city;
        $draft->phone = $previous->phone;
        $draft->email = $previous->email;
        $draft->membershipType = $previous->membershipType;
        $draft->principalMemberName = $previous->principalMemberName;
        $draft->philibertConsent = $previous->philibertConsent;
        $draft->imageRightsRefused = $previous->imageRightsRefused;
        $draft->guardianLastName = $previous->guardianLastName;
        $draft->guardianFirstName = $previous->guardianFirstName;
        $draft->guardianEmail = $previous->guardianEmail;

        return $draft;
    }

    /**
     * @return Collection<int, MembershipEvent>
     */
    public function getHistory(): Collection
    {
        return $this->history;
    }

    /**
     * Garde la trace d'une correction faite par le bureau sur la fiche. Seul le
     * fait de la correction est note, jamais ce qui a change : l'historique ne
     * doit retenir aucune donnee de l'adherent, pour survivre a l'anonymisation.
     */
    public function recordEdit(?int $adminId, ?\DateTimeImmutable $at = null): static
    {
        $this->record(MembershipEventType::Edited, $adminId, $at);

        return $this;
    }

    private function record(MembershipEventType $type, ?int $actorId, ?\DateTimeImmutable $at, ?string $detail = null): void
    {
        $this->history->add(new MembershipEvent($this, $type, $actorId, $at, $detail));
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
     * Valide l'adhesion : la cotisation est encaissee, ou il n'y a rien a
     * encaisser. Le moyen de reglement est obligatoire des qu'un montant est
     * du — c'est la seule trace de l'encaissement cote site.
     */
    public function validate(?PaymentMethod $paymentMethod, ?int $adminId, ?\DateTimeImmutable $now = null): static
    {
        // Le controle porte sur le montant du et non sur la formule : un
        // enfant de moins de huit ans ne doit rien, quelle que soit la
        // formule, et exiger un moyen de reglement bloquerait sa validation.
        if (($this->amountDue() ?? 0) > 0 && null === $paymentMethod) {
            throw new \LogicException('Une adhesion payante ne se valide pas sans moyen de reglement.');
        }

        $this->status = MembershipStatus::Validated;
        $this->paymentMethod = $paymentMethod;
        $this->record(MembershipEventType::Validated, $adminId, $now, $paymentMethod?->value);

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
        $this->record(MembershipEventType::Cancelled, $adminId, $now);

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
        $this->record(MembershipEventType::Reopened, $adminId, $now);

        return $this->recordStatusChange($adminId, $now);
    }

    /**
     * Inscrit dans l'historique le passage d'une formule a une autre.
     *
     * Le changement de formule lui-meme — le champ — se fait par le formulaire ;
     * cette methode n'en garde que la trace : de quoi, vers quoi, par qui. Elle
     * ne touche pas a l'etat du bulletin : une adhesion consommateur validee, qui
     * devient annuelle, doit de nouveau etre encaissee, ce que l'appelant decide
     * (remise en attente, ou validation avec le moyen de reglement).
     */
    public function recordFormulaChange(MembershipType $from, ?int $adminId, ?\DateTimeImmutable $now = null): static
    {
        if (null === $this->membershipType || $from === $this->membershipType) {
            return $this;
        }

        $this->record(MembershipEventType::Upgraded, $adminId, $now, sprintf('%s → %s', $from->value, $this->membershipType->value));

        return $this;
    }

    /**
     * Vrai pour une adhesion consommateur de la saison donnee, ni annulee ni
     * anonymisee : la seule qu'on puisse faire passer a l'annuel. Une adhesion
     * d'une saison passee ne se transforme pas — elle se renouvelle.
     */
    public function canBecomeAnnual(int $season): bool
    {
        return MembershipType::Consumer === $this->membershipType
            && !$this->isCancelled()
            && !$this->isAnonymized()
            && MembershipSeasons::of($this->submittedAt) === $season;
    }

    private function recordStatusChange(?int $adminId, ?\DateTimeImmutable $now): static
    {
        $this->statusChangedAt = $now ?? new \DateTimeImmutable();
        $this->statusChangedById = $adminId;

        return $this;
    }

    /**
     * Age de l'adherent au depot du bulletin. C'est la date qui compte pour la
     * cotisation : un enfant qui adhere a sept ans ne devient pas redevable le
     * jour de ses huit ans.
     */
    public function ageAtSubmission(): ?int
    {
        return $this->birthDate?->diff($this->submittedAt)->y;
    }

    /**
     * Ce que doit la personne, en euros. Nul quand il n'y a rien a encaisser.
     *
     * Deux regles se superposent : le tarif de la formule, et la gratuite des
     * moins de huit ans. C'est ici et non sur la formule que le montant se
     * calcule, parce qu'il depend de l'adherent autant que de ce qu'il a
     * choisi.
     */
    public function amountDue(): ?int
    {
        if (null === $this->membershipType) {
            return null;
        }

        $age = $this->ageAtSubmission();

        return null !== $age && $age < self::FREE_UNDER_AGE ? 0 : $this->membershipType->amountDue();
    }

    public function amountDueLabel(): string
    {
        $amount = $this->amountDue();

        if (0 === $amount) {
            return sprintf('0 € (moins de %d ans)', self::FREE_UNDER_AGE);
        }

        return null !== $amount && $amount > 0
            ? $amount.' €'
            : (string) $this->membershipType?->amountDueLabel();
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
    // Groupe « upgrade » : le passage de consommateur a annuel ne rejoue que ces
    // regles de formule, pas la signature ni l'acceptation des statuts, deja
    // donnees au depot du bulletin et qu'on ne redemande pas pour changer de formule.
    #[Assert\Callback(groups: ['Default', 'upgrade'])]
    public function validateConditionalFields(ExecutionContextInterface $context): void
    {
        // En ligne, l'adresse est le seul moyen de repondre : elle est obligatoire.
        // Une fiche papier ou un fichier repris peut ne pas en avoir.
        if (MembershipSource::Online === $this->source && (null === $this->email || '' === trim($this->email))) {
            $context->buildViolation("L'adresse e-mail est obligatoire.")->atPath('email')->addViolation();
        }

        // Une personne reprise d'un fichier peut ne pas avoir de date de naissance ;
        // les autres bulletins la demandent.
        if (null === $this->birthDate && MembershipSource::Import !== $this->source) {
            $context->buildViolation('La date de naissance est obligatoire.')->atPath('birthDate')->addViolation();
        }

        // Les regles de formule (adherent principal d'une famille, formule et
        // responsable legal d'un mineur) bloquent un bulletin saisi. Une fiche reprise
        // d'un fichier decrit une personne deja inscrite sur papier : elle ne les
        // subit pas, et l'importeur les affiche comme des avertissements a completer
        // (formulaIssues). Les refuser ici empecherait de corriger la fiche — nom,
        // telephone, adresse — tant que ce point n'est pas renseigne.
        if (MembershipSource::Import !== $this->source) {
            foreach ($this->formulaIssues() as $path => $message) {
                $context->buildViolation($message)->atPath($path)->addViolation();
            }
        }

        // Designer son propre nom comme adherent principal est une erreur quelle que
        // soit l'origine de la fiche : c'est une valeur fausse, pas un oubli.
        if (MembershipType::Family === $this->membershipType && $this->principalMemberName && $this->designatesSelfAsPrincipal()) {
            // L'adhesion famille a 5 € se greffe sur une adhesion
            // individuelle a 10 € deja prise par quelqu'un d'autre du
            // foyer. Se designer soi-meme, c'est declarer une famille d'une
            // seule personne : l'association encaisserait 5 € la ou elle
            // doit en encaisser 10.
            $context->buildViolation("L'adhérent principal est la personne de votre foyer qui a déjà pris l'adhésion individuelle à 10 € : ce ne peut pas être vous.")
                ->atPath('principalMemberName')
                ->addViolation();
        }

        // L'avantage Philibert fait partie de l'adhesion annuelle : la formule
        // consommateur, gratuite a l'annee, n'y donne pas droit. Accepter la case
        // ici ferait transmettre a un tiers l'adresse de quelqu'un qui n'a droit a
        // rien chez lui.
        if (MembershipType::Consumer === $this->membershipType && $this->philibertConsent) {
            $context->buildViolation("La communication de votre adresse à Philibert est réservée aux adhérents à l'année : la formule consommateur n'y donne pas droit.")
                ->atPath('philibertConsent')
                ->addViolation();
        }
    }

    /**
     * Ce que la formule exige et que la fiche ne donne pas encore, par champ :
     * l'adherent principal d'une famille ; pour un mineur, une formule qui n'est
     * pas l'individuelle et un responsable legal complet.
     *
     * Un bulletin saisi doit n'en avoir aucun (validateConditionalFields). Une fiche
     * reprise d'un fichier peut en avoir : l'importeur les liste comme avertissements.
     *
     * @return array<string, string> chemin du champ => message
     */
    public function formulaIssues(): array
    {
        $issues = [];

        if (MembershipType::Family === $this->membershipType && !$this->principalMemberName) {
            $issues['principalMemberName'] = "Indiquez le prénom et le nom de l'adhérent principal de la famille.";
        }

        if (!$this->isMinor()) {
            return $issues;
        }

        // Un mineur n'adhere jamais pour son propre compte : il se rattache a
        // l'adhesion individuelle d'un adulte de son foyer, par la formule
        // famille. L'adhesion individuelle engage son titulaire aupres de
        // l'association, ce qu'un mineur ne peut pas faire seul.
        if (MembershipType::Individual === $this->membershipType) {
            $issues['membershipType'] = "Un mineur ne peut pas prendre l'adhésion individuelle : choisissez l'adhésion famille, rattachée à l'adulte de votre foyer qui a déjà adhéré.";
        }

        foreach ([
            'guardianLastName' => 'Indiquez le nom du responsable légal.',
            'guardianFirstName' => 'Indiquez le prénom du responsable légal.',
            'guardianEmail' => "Indiquez l'adresse e-mail du responsable légal.",
        ] as $field => $message) {
            if (!$this->{$field}) {
                $issues[$field] = $message;
            }
        }

        return $issues;
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
    public function anonymize(?\DateTimeImmutable $now = null, ?int $adminId = null): static
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
        $this->record(MembershipEventType::Anonymized, $adminId, $this->anonymizedAt);

        return $this;
    }

    public function getSource(): MembershipSource
    {
        return $this->source;
    }

    /**
     * Vrai d'un bulletin saisi par le bureau d'apres un papier : une fiche
     * remise a l'association, ou un fichier d'adherents repris en une fois.
     * Un import est l'equivalent de fiches papier, et se presente comme telles :
     * meme mention, meme fiche imprimee, memes droits de correction. La source
     * exacte reste lisible par getSource().
     */
    public function isPaper(): bool
    {
        return MembershipSource::Paper === $this->source || MembershipSource::Import === $this->source;
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

    public function isImported(): bool
    {
        return MembershipSource::Import === $this->source;
    }

    /**
     * Marque le bulletin comme repris d'un fichier d'adherents existants, par
     * l'administrateur donne. L'adhesion date de ce que dit le fichier, pas du jour
     * de l'import : c'est la date d'origine qui compte pour la saison.
     */
    public function enterFromImport(?int $adminId): static
    {
        $this->source = MembershipSource::Import;
        $this->enteredById = $adminId;

        return $this;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }
}
