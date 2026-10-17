<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const ROLE_CONTRIBUTOR = 'ROLE_CONTRIBUTOR';

    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * Date du blocage, nulle tant que le compte est actif. Un compte bloque ne
     * se connecte plus, et ses sessions ouvertes sont refusees a la requete
     * suivante (voir isEqualTo()).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $blockedAt = null;

    /**
     * Vrai tant que le mot de passe est un mot de passe provisoire, communique
     * par un administrateur : le compte doit alors le changer avant toute autre
     * action.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $mustChangePassword = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getBlockedAt(): ?\DateTimeImmutable
    {
        return $this->blockedAt;
    }

    public function isBlocked(): bool
    {
        return null !== $this->blockedAt;
    }

    public function block(?\DateTimeImmutable $now = null): static
    {
        $this->blockedAt ??= $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function unblock(): static
    {
        $this->blockedAt = null;

        return $this;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function setMustChangePassword(bool $mustChangePassword): static
    {
        $this->mustChangePassword = $mustChangePassword;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(?\DateTimeImmutable $now = null): static
    {
        $this->lastLoginAt = $now ?? new \DateTimeImmutable();

        return $this;
    }

    public function isSuperAdmin(): bool
    {
        return \in_array(self::ROLE_SUPER_ADMIN, $this->roles, true);
    }

    /**
     * Decide si la session d'un utilisateur est toujours valable : Symfony
     * recharge l'utilisateur depuis la base a chaque requete et compare.
     *
     * Une session est refusee si le compte est bloque, si son mot de passe a
     * change ou si ses roles ont change. Sans cela, bloquer un compte ne
     * coupait rien : la personne gardait sa session ouverte, pour de bon.
     *
     * Le mot de passe de la session est un condense CRC32C (voir __serialize),
     * d'ou la comparaison par hash().
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || $user->isBlocked()) {
            return false;
        }

        if ($this->getUserIdentifier() !== $user->getUserIdentifier()) {
            return false;
        }

        $stored = (string) $this->password;
        $current = (string) $user->getPassword();
        if ($stored !== $current && !hash_equals($stored, hash('crc32c', $current))) {
            return false;
        }

        $mine = $this->getRoles();
        $theirs = $user->getRoles();
        sort($mine);
        sort($theirs);

        return $mine === $theirs;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }
}
