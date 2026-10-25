<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\TimestampableTrait;
use App\Enum\FuelType;
use App\Enum\VehicleType;
use App\Repository\VehicleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ORM\Table(name: 'vehicles')]
#[ORM\Index(name: 'idx_vehicles_org', columns: ['organization_id'])]
#[ORM\HasLifecycleCallbacks]
class Vehicle
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:nested'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['vehicle:read'])]
    private Organization $organization;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:write', 'vehicle:nested'])]
    private string $name;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:write', 'vehicle:nested'])]
    private string $brand;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:write', 'vehicle:nested'])]
    private string $model;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1900, max: 2100, notInRangeMessage: 'vehicle.year.out_of_range')]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:write'])]
    private int $year;

    #[ORM\Column(name: 'license_plate', length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:write'])]
    private ?string $licensePlate = null;

    #[ORM\Column(length: 17, nullable: true)]
    #[Assert\Length(max: 17)]
    #[Groups(['vehicle:read', 'vehicle:write'])]
    private ?string $vin = null;

    #[ORM\Column(length: 20, enumType: VehicleType::class, options: ['default' => 'car'])]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:write'])]
    private VehicleType $type = VehicleType::CAR;

    #[ORM\Column(name: 'fuel_type', length: 20, enumType: FuelType::class)]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:write'])]
    private FuelType $fuelType = FuelType::GASOLINE;

    /**
     * Seconda fonte di alimentazione per veicoli bi-fuel (es. benzina + GPL,
     * benzina + metano, ibrido benzina + elettrico).
     * Deve essere diverso da $fuelType; null se il veicolo è mono-carburante.
     */
    #[ORM\Column(name: 'secondary_fuel_type', length: 20, enumType: FuelType::class, nullable: true)]
    #[Groups(['vehicle:read', 'vehicle:list', 'vehicle:write'])]
    private ?FuelType $secondaryFuelType = null;

    #[ORM\Column(name: 'initial_km', type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['vehicle:read', 'vehicle:write'])]
    private int $initialKm = 0;

    #[ORM\Column(name: 'photo_path', length: 500, nullable: true)]
    #[Groups(['vehicle:read'])]
    private ?string $photoPath = null;

    /** Prezzo d'acquisto, solo informativo: non entra nei costi né nei grafici. */
    #[ORM\Column(name: 'purchase_price', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['vehicle:read', 'vehicle:write'])]
    private ?string $purchasePrice = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['vehicle:read', 'vehicle:write'])]
    private ?string $notes = null;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['vehicle:read', 'vehicle:list'])]
    private ?\DateTimeImmutable $archivedAt = null;

    /**
     * @var Collection<int, VehicleShare>
     */
    #[ORM\OneToMany(targetEntity: VehicleShare::class, mappedBy: 'vehicle', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $shares;

    public function __construct()
    {
        $this->shares = new ArrayCollection();
    }

    /**
     * Vincolo: il secondario non può coincidere con il primario.
     */
    #[Assert\Callback]
    public function validateFuelTypes(ExecutionContextInterface $ctx): void
    {
        if ($this->secondaryFuelType !== null && $this->secondaryFuelType === $this->fuelType) {
            $ctx->buildViolation('vehicle.duplicate_fuel_type')
                ->atPath('secondaryFuelType')
                ->addViolation();
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function setOrganization(Organization $org): self { $this->organization = $org; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): self { $this->name = $v; return $this; }
    public function getBrand(): string { return $this->brand; }
    public function setBrand(string $v): self { $this->brand = $v; return $this; }
    public function getModel(): string { return $this->model; }
    public function setModel(string $v): self { $this->model = $v; return $this; }
    public function getYear(): int { return $this->year; }
    public function setYear(int $v): self { $this->year = $v; return $this; }
    public function getLicensePlate(): ?string { return $this->licensePlate; }
    public function setLicensePlate(?string $v): self { $this->licensePlate = $v; return $this; }
    public function getVin(): ?string { return $this->vin; }
    public function setVin(?string $v): self { $this->vin = $v; return $this; }
    public function getType(): VehicleType { return $this->type; }
    public function setType(VehicleType $v): self { $this->type = $v; return $this; }
    public function getFuelType(): FuelType { return $this->fuelType; }
    public function setFuelType(FuelType $v): self { $this->fuelType = $v; return $this; }
    public function getSecondaryFuelType(): ?FuelType { return $this->secondaryFuelType; }
    public function setSecondaryFuelType(?FuelType $v): self { $this->secondaryFuelType = $v; return $this; }
    public function isBiFuel(): bool { return $this->secondaryFuelType !== null; }
    /** @return list<FuelType> */
    public function getAllFuelTypes(): array {
        return $this->secondaryFuelType === null
            ? [$this->fuelType]
            : [$this->fuelType, $this->secondaryFuelType];
    }
    public function getInitialKm(): int { return $this->initialKm; }
    public function setInitialKm(int $v): self { $this->initialKm = $v; return $this; }
    public function getPhotoPath(): ?string { return $this->photoPath; }
    public function setPhotoPath(?string $v): self { $this->photoPath = $v; return $this; }
    public function getPurchasePrice(): ?string { return $this->purchasePrice; }
    public function setPurchasePrice(?string $p): self { $this->purchasePrice = $p; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): self { $this->notes = $v; return $this; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function setArchivedAt(?\DateTimeImmutable $v): self { $this->archivedAt = $v; return $this; }
    public function isArchived(): bool { return $this->archivedAt !== null; }

    /** @return Collection<int, VehicleShare> */
    public function getShares(): Collection { return $this->shares; }
}
