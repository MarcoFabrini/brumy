<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Attachment;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\VehicleShare;
use App\Enum\FuelType;
use App\Enum\OrgRole;
use App\Enum\ShareRole;
use App\Enum\UserValueKind;
use App\Repository\UserRepository;
use App\Service\Storage\AttachmentStorageInterface;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserValueFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Isolamento dei dati e pulizia: promemoria "in arrivo" per utente, allegati orfani,
 * condivisioni residue, account anonimizzati, coerenza dei dati in ingresso.
 */
final class DataHygieneTest extends ApiTestCase
{
    // ---------------- /api/reminders/upcoming ----------------

    public function testUpcomingShowsMemberOnlyTheOwnedVehicles(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $shared = VehicleFactory::createOne(['organization' => $org]);
        $hidden = VehicleFactory::createOne(['organization' => $org]);
        $own = VehicleFactory::createOne(['organization' => $org]);
        $member = $this->memberWithShare($org, $shared);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $own, 'user' => $member]);
        $visible = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $own, 'dueDate' => new \DateTimeImmutable('+3 days')]);
        // Condiviso in sola lettura o senza accesso: non sono scadenze sue.
        ReminderFactory::createOne(['organization' => $org, 'vehicle' => $shared, 'dueDate' => new \DateTimeImmutable('+3 days')]);
        ReminderFactory::createOne(['organization' => $org, 'vehicle' => $hidden, 'dueDate' => new \DateTimeImmutable('+3 days')]);

        $this->jsonRequest('GET', '/api/reminders/upcoming', accessToken: $this->tokenFor($member, $org));

        self::assertResponseIsSuccessful();
        self::assertSame([$visible->getId()], array_column($this->jsonBody(), 'id'));
    }

    public function testUpcomingExcludesArchivedVehiclesForEveryone(): void
    {
        [$owner, $org, $ownerToken] = $this->createAuthenticatedUser();
        $archived = VehicleFactory::createOne(['organization' => $org, 'archivedAt' => new \DateTimeImmutable('-1 day')]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $archived, 'user' => $owner]);
        ReminderFactory::createOne(['organization' => $org, 'vehicle' => $archived, 'dueDate' => new \DateTimeImmutable('+3 days')]);

        $this->jsonRequest('GET', '/api/reminders/upcoming', accessToken: $ownerToken);

        self::assertSame([], $this->jsonBody());
    }

    public function testArchivedVehicleCanBeRestored(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/archive', accessToken: $token);
        self::assertNotNull($this->jsonBody()['archivedAt']);

        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/unarchive', accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody()['archivedAt']);
    }

    // ---------------- allegati orfani ----------------

    public function testDeletingAVehicleRemovesItsAttachmentsAndFiles(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $maintenance = MaintenanceFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        $other = VehicleFactory::createOne(['organization' => $org]);

        $onVehicle = $this->upload($token, 'vehicle', $vehicle->getId());
        $onMaintenance = $this->upload($token, 'maintenance', $maintenance->getId());
        $onOtherVehicle = $this->upload($token, 'vehicle', $other->getId());

        $this->jsonRequest('DELETE', '/api/vehicles/'.$vehicle->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(204);

        self::assertNull($this->attachmentRow($onVehicle['id']));
        self::assertNull($this->attachmentRow($onMaintenance['id']));
        self::assertFalse($this->storedFileExists($onVehicle['path']));
        self::assertFalse($this->storedFileExists($onMaintenance['path']));

        self::assertNotNull($this->attachmentRow($onOtherVehicle['id']), 'Gli allegati di altri veicoli non si toccano');
        self::assertTrue($this->storedFileExists($onOtherVehicle['path']));
    }

    #[DataProvider('recordKinds')]
    public function testDeletingARecordRemovesItsAttachments(string $kind): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $factory = match ($kind) {
            'maintenance' => MaintenanceFactory::class,
            'expense' => ExpenseFactory::class,
            'refueling' => RefuelingFactory::class,
            default => ReminderFactory::class,
        };
        $record = $factory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        $attachment = $this->upload($token, $kind, $record->getId());

        $this->jsonRequest('DELETE', '/api/'.$kind.'s/'.$record->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(204);

        self::assertNull($this->attachmentRow($attachment['id']));
        self::assertFalse($this->storedFileExists($attachment['path']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recordKinds(): iterable
    {
        yield 'maintenance' => ['maintenance'];
        yield 'expense' => ['expense'];
        yield 'refueling' => ['refueling'];
        yield 'reminder' => ['reminder'];
    }

    public function testDeletingTheOrganizationRemovesAttachmentFiles(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $attachment = $this->upload($token, 'vehicle', $vehicle->getId());

        $this->jsonRequest('DELETE', '/api/organizations/'.$org->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(204);

        self::assertFalse($this->storedFileExists($attachment['path']));
    }

    public function testDeletingTheOrganizationRemovesValuesInUse(): void
    {
        // Le FK dei record verso le voci sono RESTRICT: il cascade dell'org deve comunque passare
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $station = UserValueFactory::createOne(['user' => $user, 'organization' => $org, 'kind' => UserValueKind::STATION]);
        $workshop = UserValueFactory::createOne(['user' => $user, 'organization' => $org, 'kind' => UserValueKind::WORKSHOP]);
        RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'station' => $station]);
        MaintenanceFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'workshop' => $workshop]);
        ExpenseFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]); // categoria di partenza dell'utente

        $this->jsonRequest('DELETE', '/api/organizations/'.$org->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(0, $em->getRepository(UserValue::class)->count([]));
    }

    // ---------------- membri e condivisioni ----------------

    public function testRemovingAMemberDropsTheirVehicleSharesOfThatOrganization(): void
    {
        [, $org, $ownerToken] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $member = $this->memberWithShare($org, $vehicle);
        $membership = OrganizationMemberFactory::repository()->findOneBy(['user' => $member, 'organization' => $org]);
        self::assertNotNull($membership);

        $this->jsonRequest('DELETE', '/api/organizations/'.$org->getId().'/members/'.$membership->getId(), accessToken: $ownerToken);
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(0, $em->getRepository(VehicleShare::class)->count(['user' => $member->getId()]));
    }

    // ---------------- istanza e account anonimizzati ----------------

    public function testInstanceAdminSkipsAnonymizedAccounts(): void
    {
        $gone = UserFactory::createOne(['email' => 'deleted-1-abc'.User::ANONYMIZED_EMAIL_SUFFIX]);
        $real = UserFactory::createOne(['email' => 'admin@test.it']);
        $repo = static::getContainer()->get(UserRepository::class);

        self::assertFalse($repo->isInstanceAdmin($gone));
        self::assertTrue($repo->isInstanceAdmin($real), 'Il ruolo passa al primo account reale');
    }

    // ---------------- dati in ingresso coerenti ----------------

    public function testRefuelingWithFuelTheVehicleDoesNotUseIsRejected(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org, 'fuelType' => FuelType::GASOLINE]);

        $this->jsonRequest('POST', '/api/refuelings', [
            'vehicleId' => $vehicle->getId(),
            'refueledAt' => '2026-05-01',
            'km' => 1000,
            'liters' => '30.000',
            'pricePerLiter' => '1.8000',
            'fuelType' => 'diesel',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('fuelType', $this->jsonBody()['errors'][0]['field']);
    }

    /**
     * @param array<string, mixed> $recurrence
     */
    #[DataProvider('incoherentRecurrence')]
    public function testExpenseRecurrenceMustBeCoherent(array $recurrence): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-05-01',
            'category' => 'insurance',
            'description' => 'Assicurazione',
            'amount' => '400.00',
            ...$recurrence,
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incoherentRecurrence(): iterable
    {
        yield 'recurring without period' => [['recurring' => true]];
        yield 'period without recurring' => [['recurring' => false, 'recurringPeriod' => 'yearly']];
    }

    public function testRecurringExpenseWithPeriodIsAccepted(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-05-01',
            'category' => 'insurance',
            'description' => 'Assicurazione',
            'amount' => '400.00',
            'recurring' => true,
            'recurringPeriod' => 'yearly',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
    }

    public function testOversizedNotesAreRejectedInsteadOfFailingInTheDatabase(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-05-01',
            'category' => 'insurance',
            'description' => 'x',
            'amount' => '1.00',
            'notes' => str_repeat('a', 70_000),
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testHugePageNumberDoesNotCrash(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('GET', '/api/refuelings?vehicleId='.$vehicle->getId().'&page=99999999999999999999', accessToken: $token);

        self::assertResponseIsSuccessful();
    }

    // ---------------- helpers ----------------

    private function memberWithShare(\App\Entity\Organization $org, \App\Entity\Vehicle $vehicle): User
    {
        $user = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $user, 'role' => OrgRole::MEMBER]);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $user, 'role' => ShareRole::VIEWER]);

        return $user;
    }

    private function tokenFor(User $user, \App\Entity\Organization $org): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($user, [
            'user_id' => $user->getId(),
            'active_org_id' => $org->getId(),
        ]);
    }

    /**
     * @return array{id: int, path: string}
     */
    private function upload(string $token, string $entityType, ?int $entityId): array
    {
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => $entityType, 'entityId' => (string) $entityId],
            files: ['file' => $this->makeUploadedFile('a.png', $this->pngBytes(), 'image/png')],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_X_CLIENT_TYPE' => 'mobile'],
        );
        self::assertResponseStatusCodeSame(201);
        $id = (int) $this->jsonBody()['id'];

        $row = $this->attachmentRow($id);
        self::assertNotNull($row);
        $path = $row->getStoredPath();
        self::assertTrue($this->storedFileExists($path), 'Il file deve esistere dopo l\'upload');

        return ['id' => $id, 'path' => $path];
    }

    private function attachmentRow(int $id): ?Attachment
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Attachment::class, $id);
    }

    private function storedFileExists(string $storedPath): bool
    {
        return static::getContainer()->get(AttachmentStorageInterface::class)->exists($storedPath);
    }
}
