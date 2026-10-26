<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserValueKind;
use App\Repository\UserValueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Voce privata di un utente in una lista ricorrente (distributori, officine, categorie di spesa)
 * dentro un'organizzazione. L'unicità è su `nameKey`, ottenuta da {@see self::keyOf()}.
 * Eliminare è morbido ({@see self::softDelete()}): la voce resta per i record che la usano, ma esce dalla lista.
 */
#[ORM\Entity(repositoryClass: UserValueRepository::class)]
#[ORM\Table(name: 'user_values')]
#[ORM\UniqueConstraint(name: 'uniq_user_values_list_key', columns: ['user_id', 'organization_id', 'kind', 'name_key'])]
class UserValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    #[Groups(['user_value:nested'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 20, enumType: UserValueKind::class)]
    private UserValueKind $kind;

    #[ORM\Column(length: 200)]
    #[Groups(['user_value:nested'])]
    private string $name;

    /**
     * Collation binaria: l'unicità segue esattamente keyOf(), non la collation accent-insensitive del DB.
     * null per una voce eliminata: il suo nome torna libero (l'indice unico ammette più NULL).
     */
    #[ORM\Column(name: 'name_key', length: 200, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
    private ?string $nameKey;

    /** Voce di partenza da cui viene (valore di `ExpenseCategory` o `TaxType`, secondo il tipo); null = creata dall'utente. */
    #[ORM\Column(name: 'starter_key', length: 30, nullable: true)]
    private ?string $starterKey = null;

    /**
     * Solo distributori: il distributore ufficiale (id dell'open data MIMIT, vedi {@see FuelStation}) da cui prendere il
     * prezzo. Intero semplice, senza chiave esterna: la tabella dei distributori si riscrive ogni giorno e un distributore
     * chiuso sparisce, mentre la voce resta (senza prezzo).
     */
    #[ORM\Column(name: 'fuel_station_id', type: 'integer', nullable: true)]
    private ?int $fuelStationId = null;

    /**
     * Solo distributori collegati: il suo prezzo si salva ogni giorno ({@see FuelPriceHistory}) e compare nei grafici
     * dell'andamento. Scollegando la voce si smette di seguirlo.
     */
    #[ORM\Column(name: 'follows_price', type: 'boolean', options: ['default' => false])]
    private bool $followsPrice = false;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** $name deve già avere gli spazi normalizzati e nessuno spazio ai bordi. */
    public function __construct(User $user, Organization $organization, UserValueKind $kind, string $name, ?string $starterKey = null)
    {
        $this->user = $user;
        $this->organization = $organization;
        $this->kind = $kind;
        $this->name = $name;
        $this->nameKey = self::keyOf($name);
        $this->starterKey = $starterKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Nome come si salva: ogni sequenza di spazi Unicode diventa uno spazio, niente spazi ai bordi. '' = vuoto. */
    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** Chiave di unicità: nome normalizzato ({@see self::normalizeName()}) in minuscolo. Gli accenti contano. */
    public static function keyOf(string $name): string
    {
        return mb_strtolower(self::normalizeName($name));
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->nameKey = self::keyOf($name);
    }

    /** Esce dalla lista e dai suggerimenti; i record che la usano continuano a mostrarne il nome. */
    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->nameKey = null;
    }

    /** Collega (o scollega, con null) la voce a un distributore ufficiale. Solo per i distributori. */
    public function linkFuelStation(?int $fuelStationId): void
    {
        if ($this->kind !== UserValueKind::STATION) {
            throw new \LogicException('Only stations can be linked to a fuel station.');
        }
        $this->fuelStationId = $fuelStationId;
        if ($fuelStationId === null) {
            $this->followsPrice = false;
        }
    }

    /** Segue (o smette di seguire) il prezzo del distributore collegato. Solo per i distributori collegati. */
    public function followPrice(bool $follow): void
    {
        if ($follow && ($this->kind !== UserValueKind::STATION || $this->fuelStationId === null)) {
            throw new \LogicException('Only stations linked to a fuel station can follow its price.');
        }
        $this->followsPrice = $follow;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getKind(): UserValueKind { return $this->kind; }
    public function getName(): string { return $this->name; }
    public function getNameKey(): ?string { return $this->nameKey; }
    public function getStarterKey(): ?string { return $this->starterKey; }
    public function getFuelStationId(): ?int { return $this->fuelStationId; }
    public function followsPrice(): bool { return $this->followsPrice; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
}
