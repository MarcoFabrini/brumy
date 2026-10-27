<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\TimestampableTrait;
use App\Repository\UserRepository;
use App\Security\BackupCode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'auth.email_taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, TwoFactorInterface, BackupCodeInterface
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
     * Nuovo indirizzo chiesto dal profilo, in attesa che l'utente apra il link mandato lì. Finché non lo conferma
     * l'identificatore (login, inviti, unicità) resta `email`: un indirizzo non dimostrato non diventa mai login.
     */
    #[ORM\Column(name: 'pending_email', length: 180, nullable: true)]
    private ?string $pendingEmail = null;

    /**
     * Ultima organizzazione scelta come attiva: una sessione nuova (ripresa da remember-me, altro dispositivo) riparte
     * da qui se l'utente ne è ancora membro accettato ({@see \App\Service\ActiveOrganizationResolver}). Mai esposta.
     */
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'last_active_organization_id', nullable: true, onDelete: 'SET NULL')]
    private ?Organization $lastActiveOrganization = null;

    /**
     * Segreto TOTP cifrato con {@see \App\Service\SecretCipher}. Presente con `totpEnabledAt` nullo = configurazione
     * in attesa di conferma. Mai esposto.
     */
    #[ORM\Column(name: 'totp_secret', length: 255, nullable: true)]
    private ?string $totpSecret = null;

    /** Quando la verifica in due passaggi è stata attivata (null = non attiva). */
    #[ORM\Column(name: 'totp_enabled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    /**
     * Hash SHA-256 dei codici di recupero ancora validi ({@see BackupCode::hash()}); un codice usato sparisce.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'backup_codes', type: 'json', options: ['default' => '[]'])]
    private array $backupCodes = [];

    /**
     * Segreto TOTP in chiaro, non persistito: lo passa il listener che decifra {@see self::$totpSecret} al caricamento
     * (l'entity non conosce la cifratura) o il servizio che lo ha appena generato.
     */
    private ?string $totpPlainSecret = null;

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
        if ($this->email === '') {
            throw new \LogicException('Utente senza email: non ha un identificativo.');
        }

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

    /**
     * Uguaglianza per la sessione ({@see \Symfony\Component\Security\Http\Firewall\ContextListener}): stesso id,
     * stessa email e stessa password. Un cambio password (anche da reset o GDPR) o la conferma di un cambio email
     * deautenticano ogni altra sessione alla richiesta successiva. I ruoli non contano: derivano dalle membership e
     * una promozione o un invito accettato non devono buttare fuori l'utente.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || $user->getId() !== $this->id || $user->getUserIdentifier() !== $this->email) {
            return false;
        }

        return self::samePassword($this->password, $user->getPassword());
    }

    /**
     * Quel che finisce nella sessione: id, email e un'impronta della password (crc32c, come raccomanda Symfony), non
     * l'hash vero. Il resto si rilegge dal DB a ogni richiesta.
     *
     * @return array{id: int|null, email: string, password: string}
     */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'password' => hash('crc32c', $this->password)];
    }

    /** @param array{id: int|null, email: string, password: string} $data */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->password = $data['password'];
        $this->memberships = new ArrayCollection();
    }

    // ----- organizzazione attiva -----

    public function getLastActiveOrganization(): ?Organization
    {
        return $this->lastActiveOrganization;
    }

    public function setLastActiveOrganization(?Organization $organization): self
    {
        $this->lastActiveOrganization = $organization;
        return $this;
    }

    // ----- verifica in due passaggi (TOTP + codici di recupero) -----

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabledAt !== null;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    /** 6 cifre, SHA1, 30 s: i parametri che ogni app di autenticazione supporta. */
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if ($this->totpPlainSecret === null) {
            return null;
        }

        return new TotpConfiguration($this->totpPlainSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    public function getEncryptedTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    /** Segreto decifrato al caricamento (o, se indecifrabile, uno usa-e-getta che non fa valere nessun codice dell'app). */
    public function useTotpSecret(string $plainSecret): void
    {
        $this->totpPlainSecret = $plainSecret;
    }

    /** Configurazione iniziata e non ancora confermata con un codice. */
    public function hasPendingTotpSetup(): bool
    {
        return $this->totpSecret !== null && $this->totpEnabledAt === null;
    }

    /** Inizia (o ricomincia) la configurazione con un segreto nuovo, non ancora attivo. */
    public function beginTotpSetup(string $encryptedSecret, string $plainSecret): void
    {
        if ($this->isTotpAuthenticationEnabled()) {
            throw new \LogicException('Verifica in due passaggi già attiva');
        }
        $this->totpSecret = $encryptedSecret;
        $this->totpPlainSecret = $plainSecret;
        $this->backupCodes = [];
    }

    /**
     * Conferma la configurazione in attesa: da qui il login chiede il codice.
     *
     * @param list<string> $backupCodeHashes
     */
    public function enableTotp(\DateTimeImmutable $now, array $backupCodeHashes): void
    {
        if (!$this->hasPendingTotpSetup()) {
            throw new \LogicException('Nessuna configurazione in attesa');
        }
        $this->totpEnabledAt = $now;
        $this->backupCodes = $backupCodeHashes;
    }

    /** Azzera tutto: segreto, attivazione e codici di recupero (disattivazione, reset da comando, GDPR). */
    public function disableTwoFactor(): void
    {
        $this->totpSecret = null;
        $this->totpPlainSecret = null;
        $this->totpEnabledAt = null;
        $this->backupCodes = [];
    }

    /** @param list<string> $backupCodeHashes i precedenti smettono di valere */
    public function replaceBackupCodes(array $backupCodeHashes): void
    {
        if (!$this->isTotpAuthenticationEnabled()) {
            throw new \LogicException('Verifica in due passaggi non attiva');
        }
        $this->backupCodes = $backupCodeHashes;
    }

    public function backupCodesRemaining(): int
    {
        return \count($this->backupCodes);
    }

    public function isBackupCode(string $code): bool
    {
        $hash = BackupCode::hash($code);
        foreach ($this->backupCodes as $stored) {
            if (hash_equals($stored, $hash)) {
                return true;
            }
        }

        return false;
    }

    /** Un codice di recupero vale una volta sola. */
    public function invalidateBackupCode(string $code): void
    {
        $hash = BackupCode::hash($code);
        $this->backupCodes = array_values(array_filter($this->backupCodes, static fn (string $stored): bool => !hash_equals($stored, $hash)));
    }

    /** Password uguali, anche quando una delle due è l'impronta crc32c salvata in sessione. */
    private static function samePassword(string $a, string $b): bool
    {
        return hash_equals($a, $b)
            || (\strlen($a) === 8 && hash_equals($a, hash('crc32c', $b)))
            || (\strlen($b) === 8 && hash_equals($b, hash('crc32c', $a)));
    }
}
