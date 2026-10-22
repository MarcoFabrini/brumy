<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use App\Entity\Attachment;
use App\Entity\AuditLog;
use App\Entity\Concern\VehicleScoped;
use App\Entity\Expense;
use App\Entity\Maintenance;
use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\PushSubscription;
use App\Entity\Refueling;
use App\Entity\Reminder;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\VehicleShare;
use App\Enum\AttachmentEntityType;
use App\Enum\ShareRole;
use App\Repository\AttachmentRepository;
use App\Repository\AuditLogRepository;
use App\Repository\ExpenseRepository;
use App\Repository\MaintenanceRepository;
use App\Repository\OrganizationInvitationRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\RefuelingRepository;
use App\Repository\ReminderRepository;
use App\Repository\VehicleRepository;
use App\Repository\VehicleShareRepository;

/**
 * GDPR Art. 20 (data portability) — costruisce snapshot JSON dell'utente (#5.4).
 *
 * Include:
 * - profilo (email, name, locale, timestamps)
 * - memberships (org_id, role, joined_at)
 * - push_subscriptions (endpoint metadata, mai chiavi)
 * - vehicles_owned: i veicoli di cui l'utente è PROPRIETARIO (share `admin` accettato) in qualsiasi
 *   org, archiviati inclusi, con maintenance + refueling + expense + reminder + attachments (metadata).
 *   Conta la proprietà, non il ruolo nell'org: un owner d'org non esporta i veicoli degli altri
 *   membri, un member che possiede auto sì
 * - vehicle_shares_received: riferimento minimo ai veicoli solo condivisi con lui (nessun record)
 * - audit_log: le righe di audit generate dall'utente (azione, entità, timestamp, IP, user agent)
 * - invitations_sent: gli inviti a organizzazioni che ha mandato
 *
 * Esclude:
 * - password hash (sensibile, no portabilità)
 * - refresh_tokens (auth-only, non personal data)
 * - allegati binari (solo metadata; copia file separata se serve)
 * - p256dh / authSecret push (chiavi crypto)
 * - audit `changes` (diff dei campi: possono contenere dati di altri membri) e hash dei token d'invito
 */
final class GdprExportService
{
    public function __construct(
        private readonly VehicleRepository $vehicleRepo,
        private readonly VehicleShareRepository $shareRepo,
        private readonly MaintenanceRepository $maintenanceRepo,
        private readonly RefuelingRepository $refuelingRepo,
        private readonly ExpenseRepository $expenseRepo,
        private readonly ReminderRepository $reminderRepo,
        private readonly AttachmentRepository $attachmentRepo,
        private readonly PushSubscriptionRepository $pushRepo,
        private readonly AuditLogRepository $auditRepo,
        private readonly OrganizationInvitationRepository $invitationRepo,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        return [
            'export_meta' => [
                'generated_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'gdpr_article' => 'Art. 20 — Right to data portability',
                'format' => 'json',
                'version' => 2,
            ],
            'user' => $this->serializeUser($user),
            'memberships' => $this->serializeMemberships($user),
            'push_subscriptions' => $this->serializePushSubscriptions($user),
            'vehicle_shares_received' => $this->serializeSharesReceived($user),
            'vehicles_owned' => $this->serializeOwnedVehicles($user),
            'audit_log' => $this->serializeAuditLog($user),
            'invitations_sent' => $this->serializeInvitationsSent($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'pending_email' => $user->getPendingEmail(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'locale' => $user->getLocale(),
            'created_at' => $user->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeMemberships(User $user): array
    {
        $out = [];
        foreach ($user->getMemberships() as $m) {
            $org = $m->getOrganization();
            $out[] = [
                'organization_id' => $org->getId(),
                'organization_slug' => $org->getSlug(),
                'organization_name' => $org->getName(),
                'role' => $m->getRole()->value,
                'joined_at' => $m->getCreatedAt()->format(\DATE_ATOM),
                'accepted_at' => $m->getAcceptedAt()?->format(\DATE_ATOM),
            ];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializePushSubscriptions(User $user): array
    {
        $out = [];
        foreach ($this->pushRepo->findBy(['user' => $user]) as $sub) {
            $out[] = $this->serializePushSubscription($sub);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePushSubscription(PushSubscription $sub): array
    {
        $endpoint = $sub->getEndpoint() ?? '';

        return [
            'platform' => $sub->getPlatform()->value,
            'endpoint_prefix' => $endpoint !== '' ? substr($endpoint, 0, 32).'…' : null,
            'device_label' => $sub->getDeviceLabel(),
            'user_agent' => $sub->getUserAgent(),
            'created_at' => $sub->getCreatedAt()->format(\DATE_ATOM),
            'last_seen_at' => $sub->getLastSeenAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * Riferimento minimo ai veicoli di altri condivisi con l'utente: niente record, che sono dati
     * del proprietario. I veicoli che possiede (share `admin` accettato) sono in `vehicles_owned`.
     *
     * @return list<array<string, mixed>>
     */
    private function serializeSharesReceived(User $user): array
    {
        $out = [];
        foreach ($this->shareRepo->findBy(['user' => $user], ['id' => 'ASC']) as $share) {
            if ($share->getRole() === ShareRole::ADMIN && $share->isAccepted()) {
                continue;
            }
            $out[] = $this->serializeVehicleShare($share);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVehicleShare(VehicleShare $share): array
    {
        $vehicle = $share->getVehicle();
        return [
            'vehicle_id' => $vehicle->getId(),
            'vehicle_name' => $vehicle->getName(),
            'role' => $share->getRole()->value,
            'organization_name' => $vehicle->getOrganization()->getName(),
            'invited_at' => $share->getCreatedAt()->format(\DATE_ATOM),
            'accepted_at' => $share->getAcceptedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeOwnedVehicles(User $user): array
    {
        $vehicles = $this->vehicleRepo->findAllOwnedByUser($user);
        if ($vehicles === []) {
            return [];
        }

        // Un solo giro di query per tipo di record (non una per veicolo e per record): poi si raggruppa in PHP.
        $orgs = [];
        foreach ($vehicles as $v) {
            $orgs[(int) $v->getOrganization()->getId()] = $v->getOrganization();
        }
        $orgs = array_values($orgs);

        $maintenance = $this->groupByVehicle($this->maintenanceRepo->findBy(['vehicle' => $vehicles], ['id' => 'ASC']));
        $refueling = $this->groupByVehicle($this->refuelingRepo->findBy(['vehicle' => $vehicles], ['id' => 'ASC']));
        $expenses = $this->groupByVehicle($this->expenseRepo->findBy(['vehicle' => $vehicles], ['id' => 'ASC']));
        $reminders = $this->groupByVehicle($this->reminderRepo->findBy(['vehicle' => $vehicles], ['id' => 'ASC']));

        $attachments = [
            AttachmentEntityType::VEHICLE->value => $this->attachmentRepo->findGroupedByEntityIds(
                AttachmentEntityType::VEHICLE,
                array_map(static fn (Vehicle $v) => (int) $v->getId(), $vehicles),
                $orgs,
            ),
            AttachmentEntityType::MAINTENANCE->value => $this->attachmentsOf(AttachmentEntityType::MAINTENANCE, $maintenance, $orgs),
            AttachmentEntityType::REFUELING->value => $this->attachmentsOf(AttachmentEntityType::REFUELING, $refueling, $orgs),
            AttachmentEntityType::EXPENSE->value => $this->attachmentsOf(AttachmentEntityType::EXPENSE, $expenses, $orgs),
            AttachmentEntityType::REMINDER->value => $this->attachmentsOf(AttachmentEntityType::REMINDER, $reminders, $orgs),
        ];

        $out = [];
        foreach ($vehicles as $v) {
            $id = (int) $v->getId();
            $out[] = $this->serializeVehicle($v) + [
                'maintenance' => array_map(fn (Maintenance $x) => $this->serializeMaintenance($x, $attachments), $maintenance[$id] ?? []),
                'refueling' => array_map(fn (Refueling $x) => $this->serializeRefueling($x, $attachments), $refueling[$id] ?? []),
                'expenses' => array_map(fn (Expense $x) => $this->serializeExpense($x, $attachments), $expenses[$id] ?? []),
                'reminders' => array_map(fn (Reminder $x) => $this->serializeReminder($x, $attachments), $reminders[$id] ?? []),
                'attachments' => $this->serializeAttachments($attachments[AttachmentEntityType::VEHICLE->value][$id] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @template T of VehicleScoped
     *
     * @param list<T> $records
     *
     * @return array<int, list<T>> vehicleId => record
     */
    private function groupByVehicle(array $records): array
    {
        $grouped = [];
        foreach ($records as $record) {
            $grouped[(int) $record->getVehicle()->getId()][] = $record;
        }
        return $grouped;
    }

    /**
     * @param array<int, list<Maintenance|Refueling|Expense|Reminder>> $recordsByVehicle
     * @param list<Organization>                                        $orgs
     *
     * @return array<int, list<Attachment>>
     */
    private function attachmentsOf(AttachmentEntityType $type, array $recordsByVehicle, array $orgs): array
    {
        $ids = [];
        foreach ($recordsByVehicle as $records) {
            foreach ($records as $record) {
                $ids[] = (int) $record->getId();
            }
        }
        return $this->attachmentRepo->findGroupedByEntityIds($type, $ids, $orgs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeAuditLog(User $user): array
    {
        return array_map(static fn (AuditLog $l) => [
            'id' => $l->getId(),
            'action' => $l->getAction()->value,
            'entity_class' => $l->getEntityClass(),
            'entity_id' => $l->getEntityId(),
            'organization_id' => $l->getOrganization()?->getId(),
            'ip_address' => $l->getIpAddress(),
            'user_agent' => $l->getUserAgent(),
            'created_at' => $l->getCreatedAt()->format(\DATE_ATOM),
        ], $this->auditRepo->findByUser($user));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeInvitationsSent(User $user): array
    {
        return array_map(static fn (OrganizationInvitation $i) => [
            'id' => $i->getId(),
            'organization_id' => $i->getOrganization()->getId(),
            'organization_name' => $i->getOrganization()->getName(),
            'email' => $i->getEmail(),
            'role' => $i->getRole()->value,
            'created_at' => $i->getCreatedAt()->format(\DATE_ATOM),
            'expires_at' => $i->getExpiresAt()->format(\DATE_ATOM),
            'used' => $i->isUsed(),
        ], $this->invitationRepo->findBy(['invitedBy' => $user], ['id' => 'ASC']));
    }

    /**
     * Dati del veicolo e della sua organizzazione; i record annidati li aggiunge serializeOwnedVehicles().
     *
     * @return array<string, mixed>
     */
    private function serializeVehicle(Vehicle $v): array
    {
        $org = $v->getOrganization();

        return [
            'id' => $v->getId(),
            'organization' => [
                'id' => $org->getId(),
                'slug' => $org->getSlug(),
                'name' => $org->getName(),
            ],
            'name' => $v->getName(),
            'type' => $v->getType()->value,
            'brand' => $v->getBrand(),
            'model' => $v->getModel(),
            'year' => $v->getYear(),
            'license_plate' => $v->getLicensePlate(),
            'vin' => $v->getVin(),
            'fuel_type' => $v->getFuelType()->value,
            'secondary_fuel_type' => $v->getSecondaryFuelType()?->value,
            'initial_km' => $v->getInitialKm(),
            'notes' => $v->getNotes(),
            'archived_at' => $v->getArchivedAt()?->format(\DATE_ATOM),
            'created_at' => $v->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @param list<Attachment> $attachments
     *
     * @return list<array<string, mixed>>
     */
    private function serializeAttachments(array $attachments): array
    {
        return array_map(fn (Attachment $x) => $this->serializeAttachment($x), $attachments);
    }

    /**
     * @param array<string, array<int, list<Attachment>>> $attachments tipo => entityId => allegati
     *
     * @return array<string, mixed>
     */
    private function serializeMaintenance(Maintenance $m, array $attachments): array
    {
        return [
            'id' => $m->getId(),
            'type' => $m->getType()->value,
            'category' => $m->getCategory()->value,
            'performed_at' => $m->getPerformedAt()->format('Y-m-d'),
            'km' => $m->getKm(),
            'cost' => $m->getCost(),
            'workshop' => $m->getWorkshop(),
            'description' => $m->getDescription(),
            'attachments' => $this->serializeAttachments($attachments[AttachmentEntityType::MAINTENANCE->value][(int) $m->getId()] ?? []),
        ];
    }

    /**
     * @param array<string, array<int, list<Attachment>>> $attachments tipo => entityId => allegati
     *
     * @return array<string, mixed>
     */
    private function serializeRefueling(Refueling $r, array $attachments): array
    {
        return [
            'id' => $r->getId(),
            'refueled_at' => $r->getRefueledAt()->format('Y-m-d'),
            'km' => $r->getKm(),
            'liters' => $r->getLiters(),
            'price_per_liter' => $r->getPricePerLiter(),
            'total_cost' => $r->getTotalCost(),
            'fuel_type' => $r->getFuelType()->value,
            'full_tank' => $r->isFullTank(),
            'station' => $r->getStation(),
            'notes' => $r->getNotes(),
            'attachments' => $this->serializeAttachments($attachments[AttachmentEntityType::REFUELING->value][(int) $r->getId()] ?? []),
        ];
    }

    /**
     * @param array<string, array<int, list<Attachment>>> $attachments tipo => entityId => allegati
     *
     * @return array<string, mixed>
     */
    private function serializeExpense(Expense $e, array $attachments): array
    {
        return [
            'id' => $e->getId(),
            'category' => $e->getCategory()->value,
            'occurred_at' => $e->getOccurredAt()->format('Y-m-d'),
            'amount' => $e->getAmount(),
            'description' => $e->getDescription(),
            'recurring' => $e->isRecurring(),
            'recurring_period' => $e->getRecurringPeriod()?->value,
            'recurring_until' => $e->getRecurringUntil()?->format('Y-m-d'),
            'notes' => $e->getNotes(),
            'attachments' => $this->serializeAttachments($attachments[AttachmentEntityType::EXPENSE->value][(int) $e->getId()] ?? []),
        ];
    }

    /**
     * @param array<string, array<int, list<Attachment>>> $attachments tipo => entityId => allegati
     *
     * @return array<string, mixed>
     */
    private function serializeReminder(Reminder $r, array $attachments): array
    {
        return [
            'id' => $r->getId(),
            'type' => $r->getType()->value,
            'description' => $r->getDescription(),
            'due_date' => $r->getDueDate()?->format('Y-m-d'),
            'due_km' => $r->getDueKm(),
            'notify_days_before' => $r->getNotifyDaysBefore(),
            'notify_km_before' => $r->getNotifyKmBefore(),
            'recurrence_months' => $r->getRecurrenceMonths(),
            'recurrence_km' => $r->getRecurrenceKm(),
            'completed_at' => $r->getCompletedAt()?->format(\DATE_ATOM),
            'completed_on' => $r->getCompletedOn()?->format('Y-m-d'),
            'completed_km' => $r->getCompletedKm(),
            'last_notified_at' => $r->getLastNotifiedAt()?->format(\DATE_ATOM),
            'attachments' => $this->serializeAttachments($attachments[AttachmentEntityType::REMINDER->value][(int) $r->getId()] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttachment(Attachment $a): array
    {
        return [
            'id' => $a->getId(),
            'entity_type' => $a->getEntityType()->value,
            'entity_id' => $a->getEntityId(),
            'original_filename' => $a->getOriginalFilename(),
            'mime_type' => $a->getMimeType(),
            'size_bytes' => $a->getSizeBytes(),
            'stored_path' => $a->getStoredPath(),
            'created_at' => $a->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
