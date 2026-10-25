<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\TimestampableTrait;
use App\Entity\Concern\VehicleScoped;
use App\Repository\ComponentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Pezzo comprato per un veicolo (modello/codice, numero di serie, dove è stato preso), usato in zero o più lavori.
 * Il nome è unico nel veicolo a meno di maiuscole e spazi (`nameKey`, come {@see UserValue::keyOf()}): scrivere
 * il nome in un lavoro trova il componente che c'è. Eliminare è morbido ({@see self::softDelete()}): il componente
 * esce dall'elenco e dai suggerimenti, i lavori che l'hanno usato continuano a mostrarlo.
 */
#[ORM\Entity(repositoryClass: ComponentRepository::class)]
#[ORM\Table(name: 'components')]
#[ORM\Index(name: 'idx_components_org', columns: ['organization_id'])]
#[ORM\UniqueConstraint(name: 'uniq_components_vehicle_key', columns: ['vehicle_id', 'name_key'])]
#[ORM\HasLifecycleCallbacks]
class Component implements VehicleScoped
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    #[Groups(['component:read', 'component:list', 'component:nested'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['component:read', 'component:list'])]
    private Vehicle $vehicle;

    #[ORM\Column(length: 200)]
    #[Groups(['component:read', 'component:list', 'component:nested'])]
    private string $name;

    /** null per un componente eliminato: il nome torna libero (l'indice unico ammette più NULL). */
    #[ORM\Column(name: 'name_key', length: 200, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
    private ?string $nameKey;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** Modello o codice del pezzo. */
    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['component:read', 'component:list'])]
    private ?string $code = null;

    #[ORM\Column(name: 'serial_number', length: 200, nullable: true)]
    #[Groups(['component:read'])]
    private ?string $serialNumber = null;

    /** Voce della lista negozi del proprietario del veicolo. */
    #[ORM\ManyToOne(targetEntity: UserValue::class)]
    #[ORM\JoinColumn(name: 'shop_id', nullable: true, onDelete: 'RESTRICT')]
    #[Groups(['component:read', 'component:list'])]
    private ?UserValue $shop = null;

    #[ORM\Column(name: 'purchased_at', type: 'date_immutable', nullable: true)]
    #[Groups(['component:read', 'component:list'])]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['component:read', 'component:list'])]
    private ?string $price = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['component:read'])]
    private ?string $notes = null;

    /** $name deve essere già normalizzato ({@see UserValue::normalizeName()}) e non vuoto. */
    public function __construct(Vehicle $vehicle, string $name)
    {
        $this->vehicle = $vehicle;
        $this->organization = $vehicle->getOrganization();
        $this->rename($name);
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->nameKey = UserValue::keyOf($name);
    }

    public function getId(): ?int { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getVehicle(): Vehicle { return $this->vehicle; }
    public function getName(): string { return $this->name; }
    public function getNameKey(): ?string { return $this->nameKey; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    /** Esce dall'elenco e dai suggerimenti; i lavori che lo usano continuano a mostrarlo. */
    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->nameKey = null;
    }

    /** Nei lavori: un componente eliminato si mostra ma non si apre. */
    #[Groups(['component:nested'])]
    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $c): self { $this->code = $c; return $this; }
    public function getSerialNumber(): ?string { return $this->serialNumber; }
    public function setSerialNumber(?string $s): self { $this->serialNumber = $s; return $this; }
    public function getShop(): ?UserValue { return $this->shop; }
    public function setShop(?UserValue $s): self { $this->shop = $s; return $this; }
    public function getPurchasedAt(): ?\DateTimeImmutable { return $this->purchasedAt; }
    public function setPurchasedAt(?\DateTimeImmutable $d): self { $this->purchasedAt = $d; return $this; }
    public function getPrice(): ?string { return $this->price; }
    public function setPrice(?string $p): self { $this->price = $p; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
}
