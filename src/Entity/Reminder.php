<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\TimestampableTrait;
use App\Entity\Concern\VehicleScoped;
use App\Enum\ReminderType;
use App\Enum\ReminderUrgency;
use App\Repository\ReminderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReminderRepository::class)]
#[ORM\Table(name: 'reminders')]
#[ORM\Index(name: 'idx_reminders_org', columns: ['organization_id'])]
// MariaDB non supporta indici parziali (WHERE): indice pieno su tutti i reminder,
// comunque utile per le query "scadenze imminenti" filtrate su vehicle_id + due_date.
#[ORM\Index(name: 'idx_reminders_vehicle_active', columns: ['vehicle_id', 'due_date'])]
#[ORM\HasLifecycleCallbacks]
class Reminder implements VehicleScoped
{
    use TimestampableTrait;

    /** Anticipo di default dell'avviso a km: "in scadenza" quando mancano al massimo tanti km. */
    public const DEFAULT_NOTIFY_KM_BEFORE = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    #[Groups(['reminder:read', 'reminder:list'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['reminder:read', 'reminder:list'])]
    private Vehicle $vehicle;

    #[ORM\Column(length: 30, enumType: ReminderType::class)]
    #[Groups(['reminder:read', 'reminder:list', 'reminder:write'])]
    private ReminderType $type = ReminderType::CUSTOM;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    #[Groups(['reminder:read', 'reminder:list', 'reminder:write'])]
    private string $description;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['reminder:read', 'reminder:write'])]
    private ?string $notes = null;

    #[ORM\Column(name: 'due_date', type: 'date_immutable', nullable: true)]
    #[Groups(['reminder:read', 'reminder:list', 'reminder:write'])]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(name: 'due_km', type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['reminder:read', 'reminder:list', 'reminder:write'])]
    private ?int $dueKm = null;

    #[ORM\Column(name: 'notify_days_before', type: 'integer', options: ['default' => 30])]
    #[Assert\PositiveOrZero]
    #[Groups(['reminder:read', 'reminder:list', 'reminder:write'])]
    private int $notifyDaysBefore = 30;

    /** Un promemoria a km è "in scadenza" quando mancano al massimo tanti km alla scadenza. */
    #[ORM\Column(name: 'notify_km_before', type: 'integer', options: ['default' => self::DEFAULT_NOTIFY_KM_BEFORE])]
    #[Groups(['reminder:read', 'reminder:list'])]
    private int $notifyKmBefore = self::DEFAULT_NOTIFY_KM_BEFORE;

    /**
     * Ricorrenza a tempo, in mesi: al completamento il successore scade tanti mesi dopo la data
     * effettiva del completamento. Null = nessuna ricorrenza a tempo.
     */
    #[ORM\Column(name: 'recurrence_months', type: 'integer', nullable: true)]
    #[Groups(['reminder:read', 'reminder:list'])]
    private ?int $recurrenceMonths = null;

    /**
     * Ricorrenza a km: al completamento il successore scade tanti km dopo i km effettivi del
     * completamento. Null = nessuna ricorrenza a km.
     */
    #[ORM\Column(name: 'recurrence_km', type: 'integer', nullable: true)]
    #[Groups(['reminder:read', 'reminder:list'])]
    private ?int $recurrenceKm = null;

    /** Istante in cui è stato segnato completato: decide {@see self::isCompleted()} e i filtri delle query. */
    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['reminder:read', 'reminder:list'])]
    private ?\DateTimeImmutable $completedAt = null;

    /** Data effettiva del lavoro (può precedere `completedAt`): base della scadenza successiva a tempo. */
    #[ORM\Column(name: 'completed_on', type: 'date_immutable', nullable: true)]
    #[Groups(['reminder:read', 'reminder:list'])]
    private ?\DateTimeImmutable $completedOn = null;

    /** Km effettivi del completamento: base della scadenza successiva a km. */
    #[ORM\Column(name: 'completed_km', type: 'integer', nullable: true)]
    #[Groups(['reminder:read', 'reminder:list'])]
    private ?int $completedKm = null;

    /** Quando è partita l'ultima notifica (informativo: la deduplica usa `notifiedUrgency`). */
    #[ORM\Column(name: 'last_notified_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['reminder:read'])]
    private ?\DateTimeImmutable $lastNotifiedAt = null;

    /**
     * Livello di urgenza dell'ultima notifica mandata: una notifica per livello (in scadenza,
     * poi scaduto), non una al giorno. Torna null quando cambiano le scadenze del promemoria.
     */
    #[ORM\Column(name: 'notified_urgency', length: 10, nullable: true, enumType: ReminderUrgency::class)]
    private ?ReminderUrgency $notifiedUrgency = null;

    public function getId(): ?int { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function setOrganization(Organization $o): self { $this->organization = $o; return $this; }
    public function getVehicle(): Vehicle { return $this->vehicle; }
    public function setVehicle(Vehicle $v): self { $this->vehicle = $v; return $this; }
    public function getType(): ReminderType { return $this->type; }
    public function setType(ReminderType $t): self { $this->type = $t; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $d): self { $this->description = $d; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $d): self
    {
        if ($this->dueDate != $d) {
            $this->notifiedUrgency = null;
        }
        $this->dueDate = $d;
        return $this;
    }
    public function getDueKm(): ?int { return $this->dueKm; }
    public function setDueKm(?int $k): self
    {
        if ($this->dueKm !== $k) {
            $this->notifiedUrgency = null;
        }
        $this->dueKm = $k;
        return $this;
    }
    public function getNotifyDaysBefore(): int { return $this->notifyDaysBefore; }
    public function setNotifyDaysBefore(int $d): self
    {
        if ($this->notifyDaysBefore !== $d) {
            $this->notifiedUrgency = null;
        }
        $this->notifyDaysBefore = $d;
        return $this;
    }
    public function getNotifyKmBefore(): int { return $this->notifyKmBefore; }
    public function setNotifyKmBefore(int $km): self
    {
        if ($this->notifyKmBefore !== $km) {
            $this->notifiedUrgency = null;
        }
        $this->notifyKmBefore = $km;
        return $this;
    }
    public function getRecurrenceMonths(): ?int { return $this->recurrenceMonths; }
    /** Non tocca `notifiedUrgency`: la ricorrenza non cambia la scadenza corrente. */
    public function setRecurrenceMonths(?int $months): self { $this->recurrenceMonths = $months; return $this; }
    public function getRecurrenceKm(): ?int { return $this->recurrenceKm; }
    /** Non tocca `notifiedUrgency`: la ricorrenza non cambia la scadenza corrente. */
    public function setRecurrenceKm(?int $km): self { $this->recurrenceKm = $km; return $this; }
    public function isRecurring(): bool { return $this->recurrenceMonths !== null || $this->recurrenceKm !== null; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $d): self { $this->completedAt = $d; return $this; }
    public function isCompleted(): bool { return $this->completedAt !== null; }
    public function getCompletedOn(): ?\DateTimeImmutable { return $this->completedOn; }
    public function getCompletedKm(): ?int { return $this->completedKm; }

    /**
     * Segna il promemoria completato: `$at` è l'istante del click, `$completedOn`/`$completedKm` la data e i km
     * effettivi del lavoro. Un promemoria si completa una volta sola.
     */
    public function markCompleted(\DateTimeImmutable $completedOn, ?int $completedKm, \DateTimeImmutable $at): self
    {
        if ($this->isCompleted()) {
            throw new \LogicException('Il promemoria è già completato.');
        }
        $this->completedAt = $at;
        $this->completedOn = $completedOn;
        $this->completedKm = $completedKm;
        return $this;
    }
    public function getLastNotifiedAt(): ?\DateTimeImmutable { return $this->lastNotifiedAt; }
    public function getNotifiedUrgency(): ?ReminderUrgency { return $this->notifiedUrgency; }

    public function markNotified(ReminderUrgency $urgency): self
    {
        $this->lastNotifiedAt = new \DateTimeImmutable();
        $this->notifiedUrgency = $urgency;
        return $this;
    }

    /**
     * Urgenza a `$today`: la peggiore tra scadenza a data e a chilometri.
     * `$currentKm` serve solo per la parte a km; se ignoto, conta solo la data.
     * Si confrontano giorni di calendario: una scadenza di oggi è "in scadenza", non scaduta.
     */
    public function urgency(?int $currentKm, \DateTimeImmutable $today): ReminderUrgency
    {
        return $this->dateUrgency($today)->worst($this->kmUrgency($currentKm));
    }

    /** True se a questo livello di urgenza va mandata una notifica non ancora mandata. */
    public function needsNotification(ReminderUrgency $current): bool
    {
        return $current->isEscalationFrom($this->notifiedUrgency);
    }

    private function dateUrgency(\DateTimeImmutable $today): ReminderUrgency
    {
        if ($this->dueDate === null) {
            return ReminderUrgency::OK;
        }

        $daysLeft = (int) $today->setTime(0, 0)->diff($this->dueDate->setTime(0, 0))->format('%r%a');

        return match (true) {
            $daysLeft < 0 => ReminderUrgency::OVERDUE,
            $daysLeft <= $this->notifyDaysBefore => ReminderUrgency::SOON,
            default => ReminderUrgency::OK,
        };
    }

    private function kmUrgency(?int $currentKm): ReminderUrgency
    {
        if ($this->dueKm === null || $currentKm === null) {
            return ReminderUrgency::OK;
        }

        $kmLeft = $this->dueKm - $currentKm;

        return match (true) {
            $kmLeft < 0 => ReminderUrgency::OVERDUE,
            $kmLeft <= $this->notifyKmBefore => ReminderUrgency::SOON,
            default => ReminderUrgency::OK,
        };
    }
}
