<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\AuditLog;
use App\Entity\OrganizationInvitation;
use App\Entity\PushSubscription;
use App\Enum\AttachmentEntityType;
use App\Enum\AuditAction;
use App\Enum\RecurringPeriod;
use App\Service\Gdpr\GdprExportService;
use App\Tests\Factory\AttachmentFactory;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use Doctrine\ORM\EntityManagerInterface;
use App\Enum\OrgRole;
use App\Enum\PushPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Test GDPR Art. 20 export service (#5.4).
 *
 * Verifica:
 * - struttura output (user, memberships, vehicles_owned, ecc.)
 * - password hash NON è incluso
 * - si esporta per PROPRIETÀ del veicolo (share admin accettato), non per ruolo nell'org
 * - vehicles + nested data popolati, veicoli solo condivisi ridotti a un riferimento
 */
final class GdprExportServiceTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    public function testExportContainsUserProfile(): void
    {
        $user = UserFactory::createOne(['email' => 'export@test.it', 'firstName' => 'Mario', 'lastName' => 'Rossi']);
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);

        $exporter = static::getContainer()->get(GdprExportService::class);
        $data = $exporter->export($user);

        self::assertSame('export@test.it', $data['user']['email']);
        self::assertSame('Mario', $data['user']['first_name']);
        self::assertSame('Rossi', $data['user']['last_name']);
        self::assertArrayNotHasKey('password', $data['user'], 'Password hash must never be in GDPR export');
    }

    public function testExportListsMemberships(): void
    {
        $user = UserFactory::createOne();
        $orgA = OrganizationFactory::createOne(['slug' => 'org-a']);
        $orgB = OrganizationFactory::createOne(['slug' => 'org-b']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $orgA, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $orgB, 'role' => OrgRole::MEMBER]);

        $exporter = static::getContainer()->get(GdprExportService::class);
        $data = $exporter->export($user);

        self::assertCount(2, $data['memberships']);
        $slugs = array_column($data['memberships'], 'organization_slug');
        self::assertContains('org-a', $slugs);
        self::assertContains('org-b', $slugs);
    }

    public function testExportIncludesVehiclesAndMaintenance(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $vehicle = VehicleFactory::createOne(['organization' => $org, 'name' => 'La Panda']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        MaintenanceFactory::createOne(['vehicle' => $vehicle]);

        $exporter = static::getContainer()->get(GdprExportService::class);
        $data = $exporter->export($user);

        self::assertCount(1, $data['vehicles_owned']);
        $exportedVehicle = $data['vehicles_owned'][0];
        self::assertSame('La Panda', $exportedVehicle['name']);
        self::assertCount(1, $exportedVehicle['maintenance']);
    }

    public function testExportIncludesAttachmentsOfMaintenanceAndReminders(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        $maintenance = MaintenanceFactory::createOne(['vehicle' => $vehicle, 'organization' => $org]);
        $reminder = ReminderFactory::createOne(['vehicle' => $vehicle, 'organization' => $org]);
        foreach ([[AttachmentEntityType::MAINTENANCE, $maintenance->getId()], [AttachmentEntityType::REMINDER, $reminder->getId()]] as [$type, $id]) {
            AttachmentFactory::createOne(['organization' => $org, 'entityType' => $type, 'entityId' => (string) $id]);
        }

        $data = static::getContainer()->get(GdprExportService::class)->export($user);

        $exported = $data['vehicles_owned'][0];
        self::assertCount(1, $exported['maintenance'][0]['attachments']);
        self::assertCount(1, $exported['reminders'][0]['attachments']);
    }

    public function testExportIncludesReminders(): void
    {
        // Regressione: serializeReminder chiamava metodi rimossi dall'entità
        // (isRecurring/getRecurringPeriod) → fatal su export con promemoria.
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        ReminderFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'description' => 'Revisione']);

        $exporter = static::getContainer()->get(GdprExportService::class);
        $data = $exporter->export($user);

        $reminders = $data['vehicles_owned'][0]['reminders'];
        self::assertCount(1, $reminders);
        self::assertSame('Revisione', $reminders[0]['description']);
        self::assertArrayHasKey('due_date', $reminders[0]);
    }

    public function testExportIncludesReminderRecurrenceAndCompletion(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        $reminder = ReminderFactory::createOne([
            'vehicle' => $vehicle,
            'organization' => $org,
            'dueKm' => 10_000,
            'recurrenceMonths' => 12,
            'recurrenceKm' => 10_000,
            'notifyKmBefore' => 500,
        ]);
        $reminder->markCompleted(new \DateTimeImmutable('2026-11-30'), 9800, new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $exported = static::getContainer()->get(GdprExportService::class)->export($user)['vehicles_owned'][0]['reminders'][0];

        self::assertSame(12, $exported['recurrence_months']);
        self::assertSame(10_000, $exported['recurrence_km']);
        self::assertSame(500, $exported['notify_km_before']);
        self::assertSame('2026-11-30', $exported['completed_on']);
        self::assertSame(9800, $exported['completed_km']);
    }

    public function testMemberWhoOwnsCarsExportsThemWithoutBeingOrgOwner(): void
    {
        $member = UserFactory::createOne();
        $org = OrganizationFactory::createOne(['name' => 'Famiglia']);
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $mine = VehicleFactory::createOne(['organization' => $org, 'name' => 'Mia']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $mine, 'user' => $member]);
        RefuelingFactory::createOne(['vehicle' => $mine, 'organization' => $org]);

        $data = static::getContainer()->get(GdprExportService::class)->export($member);

        self::assertCount(1, $data['vehicles_owned']);
        self::assertSame('Mia', $data['vehicles_owned'][0]['name']);
        self::assertSame('Famiglia', $data['vehicles_owned'][0]['organization']['name']);
        self::assertCount(1, $data['vehicles_owned'][0]['refueling']);
    }

    public function testOrgOwnerDoesNotExportOtherMembersCars(): void
    {
        $owner = UserFactory::createOne();
        $member = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $owner, 'organization' => $org, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $ownerCar = VehicleFactory::createOne(['organization' => $org, 'name' => 'Auto del titolare']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $ownerCar, 'user' => $owner]);
        $memberCar = VehicleFactory::createOne(['organization' => $org, 'name' => 'Auto del membro']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $memberCar, 'user' => $member]);
        MaintenanceFactory::createOne(['vehicle' => $memberCar, 'organization' => $org, 'description' => 'riservato-al-membro']);

        $data = static::getContainer()->get(GdprExportService::class)->export($owner);

        self::assertSame(['Auto del titolare'], array_column($data['vehicles_owned'], 'name'));
        // Nessuna traccia dell'auto del membro in nessuna sezione del file
        self::assertStringNotContainsString('Auto del membro', json_encode($data, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('riservato-al-membro', json_encode($data, \JSON_THROW_ON_ERROR));
    }

    public function testSharedWithMeIsAMinimalReferenceWithoutRecords(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $org = OrganizationFactory::createOne(['name' => 'Org altrui']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $theirs = VehicleFactory::createOne(['organization' => $org, 'name' => 'Auto condivisa', 'licensePlate' => 'ZZ999ZZ']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $theirs, 'user' => $other]);
        VehicleShareFactory::createOne(['vehicle' => $theirs, 'user' => $user]);
        RefuelingFactory::createOne(['vehicle' => $theirs, 'organization' => $org, 'station' => 'distributore-privato']);

        $data = static::getContainer()->get(GdprExportService::class)->export($user);

        self::assertSame([], $data['vehicles_owned']);
        self::assertCount(1, $data['vehicle_shares_received']);
        $ref = $data['vehicle_shares_received'][0];
        self::assertSame($theirs->getId(), $ref['vehicle_id']);
        self::assertSame('Auto condivisa', $ref['vehicle_name']);
        self::assertSame('viewer', $ref['role']);
        self::assertSame('Org altrui', $ref['organization_name']);
        self::assertArrayNotHasKey('refueling', $ref);
        $json = json_encode($data, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('distributore-privato', $json);
        self::assertStringNotContainsString('ZZ999ZZ', $json);
    }

    public function testOwnedArchivedVehiclesAreIncludedAndAcrossOrganizations(): void
    {
        $user = UserFactory::createOne();
        $orgA = OrganizationFactory::createOne();
        $orgB = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $orgA, 'role' => OrgRole::MEMBER]);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $orgB, 'role' => OrgRole::MEMBER]);
        $archived = VehicleFactory::createOne(['organization' => $orgA, 'name' => 'Archiviata', 'archivedAt' => new \DateTimeImmutable('-1 month')]);
        $other = VehicleFactory::createOne(['organization' => $orgB, 'name' => 'Altra org']);
        foreach ([$archived, $other] as $v) {
            VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $v, 'user' => $user]);
        }
        // Share admin ancora in attesa: non è proprietà
        $pending = VehicleFactory::createOne(['organization' => $orgA, 'name' => 'In attesa']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $pending, 'user' => $user, 'acceptedAt' => null]);

        $data = static::getContainer()->get(GdprExportService::class)->export($user);

        self::assertSame(['Archiviata', 'Altra org'], array_column($data['vehicles_owned'], 'name'));
        self::assertNotNull($data['vehicles_owned'][0]['archived_at']);
        self::assertSame('car', $data['vehicles_owned'][0]['type']);
        self::assertCount(1, $data['vehicle_shares_received'], 'La share admin non accettata resta un semplice riferimento');
        self::assertNull($data['vehicle_shares_received'][0]['accepted_at']);
    }

    public function testExpenseExportsRecurringAndNotes(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        ExpenseFactory::createOne([
            'vehicle' => $vehicle,
            'organization' => $org,
            'recurring' => true,
            'recurringPeriod' => RecurringPeriod::YEARLY,
            'recurringUntil' => new \DateTimeImmutable('2030-03-31'),
            'notes' => 'bollo annuale',
        ]);

        $data = static::getContainer()->get(GdprExportService::class)->export($user);

        $expense = $data['vehicles_owned'][0]['expenses'][0];
        self::assertTrue($expense['recurring']);
        self::assertSame('yearly', $expense['recurring_period']);
        self::assertSame('2030-03-31', $expense['recurring_until']);
        self::assertSame('bollo annuale', $expense['notes']);
    }

    public function testExportIncludesAuditLogRowsAndInvitationsSent(): void
    {
        $user = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([[$user, 'Vehicle', '12', '203.0.113.7', 'Firefox/130'], [$stranger, 'Vehicle', '99', '198.51.100.1', 'Chrome']] as [$u, $class, $id, $ip, $ua]) {
            $log = (new AuditLog())
                ->setOrganization($org)
                ->setUser($u)
                ->setEntityClass($class)
                ->setEntityId($id)
                ->setAction(AuditAction::UPDATED)
                ->setIpAddress($ip)
                ->setUserAgent($ua);
            $em->persist($log);
        }
        $em->persist(new OrganizationInvitation($org, 'collega@test.it', OrgRole::MEMBER, hash('sha256', 'a'), new \DateTimeImmutable('+7 days'), $user));
        $em->persist(new OrganizationInvitation($org, 'altro@test.it', OrgRole::MEMBER, hash('sha256', 'b'), new \DateTimeImmutable('+7 days'), $stranger));
        $em->flush();

        $data = static::getContainer()->get(GdprExportService::class)->export($user);

        self::assertCount(1, $data['audit_log']);
        self::assertSame('updated', $data['audit_log'][0]['action']);
        self::assertSame('Vehicle', $data['audit_log'][0]['entity_class']);
        self::assertSame('12', $data['audit_log'][0]['entity_id']);
        self::assertSame('203.0.113.7', $data['audit_log'][0]['ip_address']);
        self::assertSame('Firefox/130', $data['audit_log'][0]['user_agent']);
        self::assertNotEmpty($data['audit_log'][0]['created_at']);

        self::assertCount(1, $data['invitations_sent']);
        self::assertSame('collega@test.it', $data['invitations_sent'][0]['email']);
        self::assertArrayNotHasKey('token_hash', $data['invitations_sent'][0]);
    }

    public function testEveryRecordGetsItsOwnAttachmentsFromTheBatchLoad(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        $maintenances = MaintenanceFactory::createMany(6, ['vehicle' => $vehicle, 'organization' => $org]);
        foreach ($maintenances as $m) {
            AttachmentFactory::createOne(['organization' => $org, 'entityType' => AttachmentEntityType::MAINTENANCE, 'entityId' => (string) $m->getId()]);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->find(\App\Entity\User::class, $user->getId());
        self::assertNotNull($user);

        $data = static::getContainer()->get(GdprExportService::class)->export($user);

        self::assertCount(6, $data['vehicles_owned'][0]['maintenance']);
        foreach ($data['vehicles_owned'][0]['maintenance'] as $row) {
            self::assertCount(1, $row['attachments']);
        }
    }

    public function testAttachmentRepositoryGroupsByEntityIdAndScopesToOrganizations(): void
    {
        $org = OrganizationFactory::createOne();
        $otherOrg = OrganizationFactory::createOne();
        AttachmentFactory::createOne(['organization' => $org, 'entityType' => AttachmentEntityType::EXPENSE, 'entityId' => '5']);
        AttachmentFactory::createOne(['organization' => $org, 'entityType' => AttachmentEntityType::EXPENSE, 'entityId' => '5']);
        AttachmentFactory::createOne(['organization' => $org, 'entityType' => AttachmentEntityType::EXPENSE, 'entityId' => '6']);
        AttachmentFactory::createOne(['organization' => $otherOrg, 'entityType' => AttachmentEntityType::EXPENSE, 'entityId' => '5']);
        AttachmentFactory::createOne(['organization' => $org, 'entityType' => AttachmentEntityType::MAINTENANCE, 'entityId' => '5']);

        $grouped = static::getContainer()->get(\App\Repository\AttachmentRepository::class)
            ->findGroupedByEntityIds(AttachmentEntityType::EXPENSE, [5, 6, 7], [$org]);

        self::assertEqualsCanonicalizing([5, 6], array_keys($grouped));
        self::assertCount(2, $grouped[5]);
        self::assertCount(1, $grouped[6]);
    }

    public function testExportHasMetadataBlock(): void
    {
        $user = UserFactory::createOne();

        $exporter = static::getContainer()->get(GdprExportService::class);
        $data = $exporter->export($user);

        self::assertArrayHasKey('export_meta', $data);
        self::assertSame('json', $data['export_meta']['format']);
        self::assertStringContainsString('Art. 20', $data['export_meta']['gdpr_article']);
    }

    public function testPushSubscriptionsAreExportedWithoutTheirSecrets(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([[$user, 'mine'], [$other, 'theirs']] as [$owner, $label]) {
            $em->persist(
                (new PushSubscription())
                    ->setUser($owner)
                    ->setPlatform(PushPlatform::WEB)
                    ->setEndpoint('https://fcm.googleapis.com/fcm/send/'.str_repeat($label[0], 80))
                    ->setP256dh('P256DH-SECRET-'.$label)
                    ->setAuthSecret('AUTH-SECRET-'.$label)
                    ->setDeviceLabel('Telefono '.$label),
            );
        }
        $em->flush();

        $data = static::getContainer()->get(GdprExportService::class)->export($user);

        self::assertCount(1, $data['push_subscriptions'], 'Solo i device dell\'utente');
        $sub = $data['push_subscriptions'][0];
        self::assertSame('Telefono mine', $sub['device_label']);
        // L'endpoint è un URL-capability (chi lo conosce può spedire push al device): se ne esporta solo un prefisso
        self::assertSame('https://fcm.googleapis.com/fcm/s…', $sub['endpoint_prefix']);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('P256DH-SECRET', $json);
        self::assertStringNotContainsString('AUTH-SECRET', $json);
        self::assertStringNotContainsString('theirs', $json);
    }
}
