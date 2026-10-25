<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\VehicleScoped;
use App\Enum\FuelType;
use App\Repository\RefuelingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RefuelingRepository::class)]
#[ORM\Table(name: 'refuelings')]
#[ORM\Index(name: 'idx_refuelings_org', columns: ['organization_id'])]
#[ORM\Index(name: 'idx_refuelings_vehicle_date', columns: ['vehicle_id', 'refueled_at'])]
class Refueling implements VehicleScoped
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    #[Groups(['refueling:read', 'refueling:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['refueling:read', 'refueling:list'])]
    private Vehicle $vehicle;

    #[ORM\Column(name: 'refueled_at', type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['refueling:read', 'refueling:list', 'refueling:write'])]
    private \DateTimeImmutable $refueledAt;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    #[Groups(['refueling:read', 'refueling:list', 'refueling:write'])]
    private int $km;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 3)]
    #[Assert\Positive]
    #[Groups(['refueling:read', 'refueling:list', 'refueling:write'])]
    private string $liters;

    #[ORM\Column(name: 'price_per_liter', type: 'decimal', precision: 6, scale: 4)]
    #[Assert\Positive]
    #[Groups(['refueling:read', 'refueling:list', 'refueling:write'])]
    private string $pricePerLiter;

    /**
     * Generated column lato DB: `liters * price_per_liter`.
     * `insertable: false, updatable: false` perché il DB la calcola da solo.
     */
    #[ORM\Column(
        name: 'total_cost',
        type: 'decimal',
        precision: 10,
        scale: 2,
        insertable: false,
        updatable: false,
        columnDefinition: "NUMERIC(10,2) GENERATED ALWAYS AS (liters * price_per_liter) STORED",
    )]
    #[Groups(['refueling:read', 'refueling:list'])]
    private ?string $totalCost = null;

    #[ORM\Column(name: 'fuel_type', length: 20, enumType: FuelType::class)]
    #[Groups(['refueling:read', 'refueling:list', 'refueling:write'])]
    private FuelType $fuelType = FuelType::GASOLINE;

    #[ORM\Column(name: 'full_tank', type: 'boolean', options: ['default' => true])]
    #[Groups(['refueling:read', 'refueling:list', 'refueling:write'])]
    private bool $fullTank = true;

    /** Voce della lista distributori del proprietario del veicolo. RESTRICT: una voce in uso non sparisce di nascosto. */
    #[ORM\ManyToOne(targetEntity: UserValue::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: true, onDelete: 'RESTRICT')]
    #[Groups(['refueling:read'])]
    private ?UserValue $station = null;

    /** Descrizione facoltativa (es. il viaggio per cui si è fatto il pieno): il titolo della scheda. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['refueling:read', 'refueling:list', 'refueling:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['refueling:read', 'refueling:write'])]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['refueling:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function setOrganization(Organization $o): self { $this->organization = $o; return $this; }
    public function getVehicle(): Vehicle { return $this->vehicle; }
    public function setVehicle(Vehicle $v): self { $this->vehicle = $v; return $this; }
    public function getRefueledAt(): \DateTimeImmutable { return $this->refueledAt; }
    public function setRefueledAt(\DateTimeImmutable $d): self { $this->refueledAt = $d; return $this; }
    public function getKm(): int { return $this->km; }
    public function setKm(int $k): self { $this->km = $k; return $this; }
    public function getLiters(): string { return $this->liters; }
    public function setLiters(string $l): self { $this->liters = $l; return $this; }
    public function getPricePerLiter(): string { return $this->pricePerLiter; }
    public function setPricePerLiter(string $p): self { $this->pricePerLiter = $p; return $this; }
    public function getTotalCost(): ?string { return $this->totalCost; }
    public function getFuelType(): FuelType { return $this->fuelType; }
    public function setFuelType(FuelType $t): self { $this->fuelType = $t; return $this; }
    public function isFullTank(): bool { return $this->fullTank; }
    public function setFullTank(bool $f): self { $this->fullTank = $f; return $this; }
    public function getStation(): ?UserValue { return $this->station; }
    public function setStation(?UserValue $s): self { $this->station = $s; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
