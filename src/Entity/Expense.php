<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\VehicleScoped;
use App\Enum\ExpenseSection;
use App\Enum\RecurringPeriod;
use App\Repository\ExpenseRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
#[ORM\Table(name: 'expenses')]
#[ORM\Index(name: 'idx_expenses_org', columns: ['organization_id'])]
#[ORM\Index(name: 'idx_expenses_vehicle_date', columns: ['vehicle_id', 'occurred_at'])]
class Expense implements VehicleScoped
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    #[Groups(['expense:read', 'expense:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['expense:read', 'expense:list'])]
    private Vehicle $vehicle;

    #[ORM\Column(name: 'occurred_at', type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['expense:read', 'expense:list', 'expense:write'])]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 20, enumType: ExpenseSection::class, options: ['default' => 'other'])]
    #[Groups(['expense:read', 'expense:list'])]
    private ExpenseSection $section = ExpenseSection::OTHER;

    /**
     * Voce della lista del proprietario del veicolo: categoria (altre spese) o tipo di tassa (tasse); null solo per
     * l'assicurazione ({@see self::classify()}). RESTRICT: una voce in uso non sparisce di nascosto.
     */
    #[ORM\ManyToOne(targetEntity: UserValue::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'RESTRICT')]
    #[Groups(['expense:read', 'expense:list'])]
    private ?UserValue $category = null;

    /** Compagnia assicurativa (lista del proprietario), solo per l'assicurazione. */
    #[ORM\ManyToOne(targetEntity: UserValue::class)]
    #[ORM\JoinColumn(name: 'insurer_id', nullable: true, onDelete: 'RESTRICT')]
    #[Groups(['expense:read', 'expense:list'])]
    private ?UserValue $insurer = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    #[Groups(['expense:read', 'expense:list', 'expense:write'])]
    private string $description;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Groups(['expense:read', 'expense:list', 'expense:write'])]
    private string $amount;

    // Se ricorrente, `occurredAt` è la data del PRIMO addebito e ogni addebito già avvenuto entra nei costi
    // (vedi RecurringSchedule): modificare la spesa cambia tutti i suoi addebiti, non c'è storico per addebito.
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['expense:read', 'expense:list', 'expense:write'])]
    private bool $recurring = false;

    #[ORM\Column(name: 'recurring_period', length: 20, enumType: RecurringPeriod::class, nullable: true)]
    #[Groups(['expense:read', 'expense:list', 'expense:write'])]
    private ?RecurringPeriod $recurringPeriod = null;

    /** Ultima data possibile di addebito, inclusa (null = ancora in corso). */
    #[ORM\Column(name: 'recurring_until', type: 'date_immutable', nullable: true)]
    #[Groups(['expense:read', 'expense:list'])]
    private ?\DateTimeImmutable $recurringUntil = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['expense:read', 'expense:write'])]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    #[Groups(['expense:read'])]
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
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $d): self { $this->occurredAt = $d; return $this; }
    public function getSection(): ExpenseSection { return $this->section; }
    public function getCategory(): ?UserValue { return $this->category; }
    public function getInsurer(): ?UserValue { return $this->insurer; }

    /**
     * Sezione con la sua voce: tasse e altre spese hanno sempre la categoria (tipo di tassa / categoria) e nessuna
     * compagnia; l'assicurazione ha solo la compagnia, facoltativa.
     */
    public function classify(ExpenseSection $section, ?UserValue $category, ?UserValue $insurer = null): self
    {
        if ($section === ExpenseSection::INSURANCE) {
            $this->category = null;
            $this->insurer = $insurer;
        } else {
            $this->category = $category ?? throw new \InvalidArgumentException('Tasse e altre spese hanno una categoria.');
            $this->insurer = null;
        }
        $this->section = $section;

        return $this;
    }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $d): self { $this->description = $d; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $a): self { $this->amount = $a; return $this; }
    public function isRecurring(): bool { return $this->recurring; }
    public function setRecurring(bool $r): self { $this->recurring = $r; return $this; }
    public function getRecurringPeriod(): ?RecurringPeriod { return $this->recurringPeriod; }
    public function setRecurringPeriod(?RecurringPeriod $p): self { $this->recurringPeriod = $p; return $this; }
    public function getRecurringUntil(): ?\DateTimeImmutable { return $this->recurringUntil; }
    public function setRecurringUntil(?\DateTimeImmutable $d): self { $this->recurringUntil = $d; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
