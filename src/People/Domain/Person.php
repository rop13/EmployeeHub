<?php

declare(strict_types=1);

namespace App\People\Domain;

use App\People\Infrastructure\Persistence\Doctrine\PersonRepository;
use App\SharedKernel\Tenancy\TenantOwnedInterface;
use App\Tenant\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Uniqueness on email is scoped per company, not global — two different
 * companies (tenants) may have a person with the same email address. This
 * is also the login identifier: PersonRepository's user provider looks up
 * by email alone and fails loudly (rather than guessing) if that ever
 * matches more than one company — see
 * PersonRepository::loadUserByIdentifier().
 *
 * A two-tier role system only (ROLE_ADMIN, ROLE_EMPLOYEE) — no manager
 * self-reference, no team. If a third tier or resource-level authorization
 * becomes a real need, that's the point to revisit this.
 *
 * $platformAdmin is deliberately NOT a third value of $role/VALID_ROLES.
 * $role is company-scoped standing (employee vs. admin-of-my-company);
 * $platformAdmin is an orthogonal, platform-wide capability (see
 * App\OAuth\UI\Http\Controller\OAuthClientController's class docblock for
 * why OAuth2 client registration specifically needs this: OAuthClient is
 * not tenant-scoped, so gating it on plain ROLE_ADMIN would let any
 * company's admin register a client able to harvest identity data for
 * every OTHER company's people too, once any of them authorized it). The
 * two concepts stay independently settable and independently checked —
 * a Person can be a company ROLE_ADMIN, a platform admin, both, or
 * neither. Grantable only via the app:grant-platform-admin console
 * command — never exposed in PersonType or any UI, so a company admin
 * managing their own company's people can never grant this to themselves
 * or anyone else through the normal person-management screens.
 */
#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_person_company_email', columns: ['company_id', 'email'])]
class Person implements TenantOwnedInterface, UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_EMPLOYEE = 'EMPLOYEE';
    public const string ROLE_ADMIN = 'ADMIN';

    /** @var string[] */
    public const array VALID_ROLES = [self::ROLE_EMPLOYEE, self::ROLE_ADMIN];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'people')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Company $company = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    private ?string $lastName = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    /**
     * @var string the hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::VALID_ROLES)]
    private string $role = self::ROLE_EMPLOYEE;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $platformAdmin = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
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
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password ?? '';
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        // Hierarchical: an admin can do everything an employee can — one
        // role field, not a set the caller has to reason about combining.
        $roles = match ($this->role) {
            self::ROLE_ADMIN => ['ROLE_ADMIN', 'ROLE_EMPLOYEE'],
            default => ['ROLE_EMPLOYEE'],
        };

        // Orthogonal to $role entirely: a platform admin keeps whatever
        // their own company-scoped standing already grants them, on top
        // of this platform-wide capability.
        if ($this->platformAdmin) {
            $roles[] = 'ROLE_PLATFORM_ADMIN';
        }

        return $roles;
    }

    /**
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        if ($this->email === null || $this->email === '') {
            throw new \LogicException('Cannot use a Person with no email as a security user.');
        }

        return $this->email;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // No plaintext/temporary credentials are ever stored on this entity.
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platformAdmin;
    }

    /**
     * Deliberately not wired into PersonType — see this property's
     * class-level docblock. Only App\People\Application\Command\
     * GrantPlatformAdminCommand calls this.
     */
    public function setPlatformAdmin(bool $platformAdmin): static
    {
        $this->platformAdmin = $platformAdmin;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
