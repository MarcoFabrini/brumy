<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(name: 'idx_refresh_tokens_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_refresh_tokens_expires', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_refresh_tokens_family', columns: ['family_id'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 128, unique: true)]
    private string $token;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * Quando il token è stato sostituito da una rotazione (`/refresh`). Distingue "ruotato" da "revocato
     * per altro" (logout, cambio password): solo un token ruotato che ricompare è un riuso. Nei token
     * ruotati coincide con `revokedAt`.
     */
    #[ORM\Column(name: 'rotated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    /**
     * Sessione di login a cui appartiene il token: nasce al login e passa invariata a ogni rotazione.
     * Se un token già ruotato viene ripresentato, si revoca tutta la famiglia (vedi RefreshTokenService).
     */
    #[ORM\Column(name: 'family_id', length: 32)]
    private string $familyId;

    /**
     * Valore in chiaro del token, presente SOLO sull'istanza appena emessa da
     * {@see \App\Service\RefreshTokenService::issue()}: serve a consegnarlo al client (cookie/body)
     * e non viene mai persistito. In DB c'è solo `token` = sha256 del valore in chiaro.
     */
    private ?string $plainToken = null;

    /** Org attiva della sessione: sopravvive alla rotazione, così il refresh non riporta alla prima org. */
    #[ORM\Column(name: 'active_organization_id', type: 'bigint', nullable: true)]
    private ?int $activeOrganizationId = null;

    /** Senza `$familyId` il token apre una nuova famiglia (un nuovo login); la rotazione passa quella esistente. */
    public function __construct(User $user, string $token, \DateTimeImmutable $expiresAt, ?string $familyId = null)
    {
        $this->user = $user;
        $this->token = $token;
        $this->expiresAt = $expiresAt;
        $this->familyId = $familyId ?? bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** Hash (sha256) del token, come salvato in DB. */
    public function getToken(): string
    {
        return $this->token;
    }

    public function getPlainToken(): string
    {
        return $this->plainToken ?? throw new \LogicException('Plain refresh token is only available right after issue().');
    }

    public function setPlainToken(string $plainToken): void
    {
        $this->plainToken = $plainToken;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function getFamilyId(): string
    {
        return $this->familyId;
    }

    public function isRotated(): bool
    {
        return $this->rotatedAt !== null;
    }

    public function getActiveOrganizationId(): ?int
    {
        return $this->activeOrganizationId;
    }

    public function setActiveOrganizationId(?int $id): void
    {
        $this->activeOrganizationId = $id;
    }

    /**
     * Allinea l'istanza in memoria a una rotazione già scritta in DB da
     * {@see \App\Repository\RefreshTokenRepository::rotateIfActive()} (UPDATE DQL, che non la tocca).
     */
    public function markRotated(\DateTimeImmutable $at): void
    {
        $this->revokedAt = $at;
        $this->rotatedAt = $at;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }
}
