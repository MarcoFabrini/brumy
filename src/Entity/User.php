<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\TimestampableTrait;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'auth.email_taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    #[Groups(['user:read', 'user:list', 'user:name'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Groups(['user:read', 'user:list'])]
    private string $email;

    /**
     * @var string The hashed password (Argon2id)
     */
    #[ORM\Column]
    private string $password;

    #[ORM\Column(name: 'first_name', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:list', 'user:name'])]
    private string $firstName;

    #[ORM\Column(name: 'last_name', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:list', 'user:name'])]
    private string $lastName;

    #[ORM\Column(length: 5, options: ['default' => 'it'])]
    #[Assert\Choice(choices: ['it', 'en'])]
    #[Groups(['user:read'])]
    private string $locale = 'it';

    /**
     * Path relativo (dallo storage root) della foto profilo. Mai esposto
     * direttamente al client: l'API espone solo `hasAvatar`.
     */
    #[ORM\Column(name: 'avatar_path', length: 500, nullable: true)]
    private ?string $avatarPath = null;

    /**
     * Quando l'utente ha confermato il proprio indirizzo email (null = non verificato).
     * Gli account creati prima dell'introduzione della verifica sono grandfathered
     * (impostati = created_at dalla migrazione).
     */
    #[ORM\Column(name: 'email_verified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /**
     * Versione delle credenziali di sessione, copiata nel claim `token_version` di ogni access token: incrementata
     * a ogni revoca di sessioni; gli access token con una versione diversa vengono rifiutati
     * ({@see \App\Security\JwtUserProvider}). Mai esposta (nessun gruppo) e mai impostata a mano: cambia solo
     * con {@see self::incrementTokenVersion()}, sotto il lock di {@see \App\Service\RefreshTokenService}.
     */
    #[ORM\Column(name: 'token_version', options: ['default' => 0])]
    private int $tokenVersion = 0;

    /**
     * Nuovo indirizzo chiesto dal profilo, in attesa che l'utente apra il link mandato lì. Finché non lo conferma
     * l'identificatore (login, inviti, unicità) resta `email`: un indirizzo non dimostrato non diventa mai login.
     */
    #[ORM\Column(name: 'pending_email', length: 180, nullable: true)]
    private ?string $pendingEmail = null;

    /**
     * @var Collection<int, OrganizationMember>
     */
    #[ORM\OneToMany(targetEntity: OrganizationMember::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memberships;

    /** Email degli account anonimizzati (GDPR Art. 17): `deleted-<id>-<hash>@anonymized.local`. */
    public const ANONYMIZED_EMAIL_SUFFIX = '@anonymized.local';

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower(trim($email));
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): self
    {
        $this->password = $hashedPassword;
        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function markEmailVerified(): self
    {
        $this->emailVerifiedAt ??= new \DateTimeImmutable();
        return $this;
    }

    public function getPendingEmail(): ?string
    {
        return $this->pendingEmail;
    }

    /** Chiede di cambiare email: l'identificatore non cambia finché {@see self::confirmPendingEmail()}. */
    public function requestEmailChange(string $email): self
    {
        $email = strtolower(trim($email));
        $this->pendingEmail = $email === $this->email ? null : $email;
        return $this;
    }

    public function cancelPendingEmail(): self
    {
        $this->pendingEmail = null;
        return $this;
    }

    /** Il link mandato al nuovo indirizzo è stato aperto: diventa l'email dell'account, già verificata. */
    public function confirmPendingEmail(): self
    {
        if ($this->pendingEmail === null) {
            throw new \LogicException('Nessun cambio email in attesa');
        }
        $this->email = $this->pendingEmail;
        $this->pendingEmail = null;
        $this->emailVerifiedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getTokenVersion(): int
    {
        return $this->tokenVersion;
    }

    /**
     * Invalida tutti gli access token emessi finora. Va chiamato solo con la riga `users` riletta sotto lock
     * (PESSIMISTIC_WRITE) nella stessa transazione del flush: altrimenti due incrementi concorrenti partirebbero
     * dallo stesso valore e uno andrebbe perso.
     */
    public function incrementTokenVersion(): self
    {
        ++$this->tokenVersion;
        return $this;
    }

    public function isAnonymized(): bool
    {
        return str_ends_with($this->email, self::ANONYMIZED_EMAIL_SUFFIX);
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): self
    {
        $this->avatarPath = $avatarPath;
        return $this;
    }

    public function hasAvatar(): bool
    {
        return $this->avatarPath !== null;
    }

    /**
     * @return Collection<int, OrganizationMember>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(OrganizationMember $membership): self
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setUser($this);
        }
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->memberships as $membership) {
            $roles[] = $membership->getRole()->toSymfonyRole();
        }
        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
        // Niente da pulire (no plaintext credentials in memoria)
    }
}
