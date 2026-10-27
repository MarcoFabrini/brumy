<?php

declare(strict_types=1);

namespace App\Tests\Functional\Multitenancy;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\OrgRole;
use App\Enum\ShareRole;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;

/**
 * Un veicolo condiviso in sola lettura (share `viewer`) si può guardare e basta:
 * nessun endpoint di scrittura deve funzionare, per nessuna risorsa collegata al veicolo.
 */
final class ReadOnlyShareTest extends ApiTestCase
{
    private Organization $org;
    private Vehicle $vehicle;
    private string $viewerToken;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->org, $this->ownerToken] = $this->createAuthenticatedUser();
        $this->vehicle = VehicleFactory::createOne(['organization' => $this->org]);

        $viewer = $this->memberWithShare(ShareRole::VIEWER);
        $this->viewerToken = $this->sessionFor($viewer);
    }

    public function testViewerCanReadEverythingOnTheSharedVehicle(): void
    {
        $maintenance = MaintenanceFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle]);
        $refueling = RefuelingFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle]);
        $expense = ExpenseFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle]);
        $reminder = ReminderFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle]);
        $vid = $this->vehicle->getId();

        foreach ([
            '/api/vehicles/'.$vid,
            '/api/vehicles/'.$vid.'/stats',
            '/api/maintenances?vehicleId='.$vid,
            '/api/maintenances/'.$maintenance->getId(),
            '/api/refuelings?vehicleId='.$vid,
            '/api/refuelings/'.$refueling->getId(),
            '/api/expenses?vehicleId='.$vid,
            '/api/expenses/'.$expense->getId(),
            '/api/reminders?vehicleId='.$vid,
            '/api/reminders/'.$reminder->getId(),
        ] as $url) {
            $this->jsonRequest('GET', $url, session: $this->viewerToken);
            self::assertResponseIsSuccessful("GET $url deve funzionare per il viewer");
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('writeEndpoints')]
    public function testViewerCannotWrite(string $method, string $urlTemplate, array $body): void
    {
        $ids = [
            '{vehicle}' => $this->vehicle->getId(),
            '{maintenance}' => MaintenanceFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle])->getId(),
            '{refueling}' => RefuelingFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle])->getId(),
            '{expense}' => ExpenseFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle])->getId(),
            '{reminder}' => ReminderFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle])->getId(),
        ];
        $url = strtr($urlTemplate, array_map('strval', $ids));
        $body = json_decode(strtr(json_encode($body, JSON_THROW_ON_ERROR), array_map('strval', $ids)), true, flags: JSON_THROW_ON_ERROR);
        // I vehicleId nei payload sono segnaposto stringa nel provider: qui diventano interi.
        if (isset($body['vehicleId'])) {
            $body['vehicleId'] = (int) $body['vehicleId'];
        }

        $this->jsonRequest($method, $url, $body === [] ? null : $body, session: $this->viewerToken);

        self::assertResponseStatusCodeSame(403, "$method $url deve essere vietato al viewer");
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function writeEndpoints(): iterable
    {
        $vehicle = ['name' => 'Hack', 'brand' => 'X', 'model' => 'Y', 'year' => 2020, 'type' => 'car', 'fuelType' => 'diesel'];

        yield 'vehicle update' => ['PUT', '/api/vehicles/{vehicle}', $vehicle];
        yield 'vehicle delete' => ['DELETE', '/api/vehicles/{vehicle}', []];
        yield 'vehicle archive' => ['POST', '/api/vehicles/{vehicle}/archive', []];
        yield 'vehicle unarchive' => ['POST', '/api/vehicles/{vehicle}/unarchive', []];
        yield 'share create' => ['POST', '/api/vehicles/{vehicle}/shares', ['userIds' => [1]]];
        yield 'share list' => ['GET', '/api/vehicles/{vehicle}/shares', []];
        yield 'share candidates' => ['GET', '/api/vehicles/{vehicle}/share-candidates', []];
        yield 'transfer candidates' => ['GET', '/api/vehicles/{vehicle}/transfer-candidates', []];
        yield 'vehicle transfer' => ['POST', '/api/vehicles/{vehicle}/transfer', ['userId' => 1]];

        yield 'maintenance create' => ['POST', '/api/maintenances', [
            'vehicleId' => '{vehicle}', 'performedAt' => '2026-01-10', 'km' => 1000, 'type' => 'oil_change', 'description' => 'x',
        ]];
        yield 'maintenance update' => ['PUT', '/api/maintenances/{maintenance}', [
            'vehicleId' => '{vehicle}', 'performedAt' => '2026-01-10', 'km' => 1000, 'type' => 'oil_change', 'description' => 'x',
        ]];
        yield 'maintenance delete' => ['DELETE', '/api/maintenances/{maintenance}', []];

        yield 'refueling create' => ['POST', '/api/refuelings', [
            'vehicleId' => '{vehicle}', 'refueledAt' => '2026-01-10', 'km' => 1000, 'liters' => '40.000', 'pricePerLiter' => '1.8000',
        ]];
        yield 'refueling update' => ['PUT', '/api/refuelings/{refueling}', [
            'vehicleId' => '{vehicle}', 'refueledAt' => '2026-01-10', 'km' => 1000, 'liters' => '40.000', 'pricePerLiter' => '1.8000',
        ]];
        yield 'refueling delete' => ['DELETE', '/api/refuelings/{refueling}', []];

        yield 'expense create' => ['POST', '/api/expenses', [
            'vehicleId' => '{vehicle}', 'occurredAt' => '2026-01-10', 'category' => 'other', 'description' => 'x', 'amount' => '10.00',
        ]];
        yield 'expense update' => ['PUT', '/api/expenses/{expense}', [
            'vehicleId' => '{vehicle}', 'occurredAt' => '2026-01-10', 'category' => 'other', 'description' => 'x', 'amount' => '10.00',
        ]];
        yield 'expense delete' => ['DELETE', '/api/expenses/{expense}', []];

        yield 'reminder create' => ['POST', '/api/reminders', [
            'vehicleId' => '{vehicle}', 'description' => 'x', 'dueDate' => '2030-01-01',
        ]];
        yield 'reminder update' => ['PUT', '/api/reminders/{reminder}', [
            'vehicleId' => '{vehicle}', 'description' => 'x', 'dueDate' => '2030-01-01',
        ]];
        yield 'reminder complete' => ['POST', '/api/reminders/{reminder}/complete', []];
        yield 'reminder delete' => ['DELETE', '/api/reminders/{reminder}', []];
    }

    public function testViewerCannotUploadOrDeleteAttachments(): void
    {
        // L'owner carica un allegato: il viewer può scaricarlo ma non cancellarlo né aggiungerne.
        $this->useSession($this->ownerToken);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'vehicle', 'entityId' => (string) $this->vehicle->getId()],
            files: ['file' => $this->makeUploadedFile('a.png', $this->pngBytes(), 'image/png')],
        );
        self::assertResponseStatusCodeSame(201);
        $attachmentId = $this->jsonBody()['id'];

        $this->useSession($this->viewerToken);
        $this->client->request('GET', '/api/attachments/'.$attachmentId);
        self::assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'vehicle', 'entityId' => (string) $this->vehicle->getId()],
            files: ['file' => $this->makeUploadedFile('b.png', $this->pngBytes(), 'image/png')],
        );
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('DELETE', '/api/attachments/'.$attachmentId, session: $this->viewerToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testViewerWritesLeaveDataUntouched(): void
    {
        $maintenance = MaintenanceFactory::createOne([
            'organization' => $this->org,
            'vehicle' => $this->vehicle,
            'description' => 'Originale',
        ]);

        $this->jsonRequest('PUT', '/api/maintenances/'.$maintenance->getId(), [
            'vehicleId' => $this->vehicle->getId(),
            'performedAt' => '2026-01-10',
            'km' => 1,
            'type' => 'oil_change',
            'description' => 'Manomessa',
        ], session: $this->viewerToken);
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('GET', '/api/maintenances/'.$maintenance->getId(), session: $this->ownerToken);
        self::assertSame('Originale', $this->jsonBody()['description']);
    }

    public function testLegacyEditorShareIsReadOnlyToo(): void
    {
        $editorToken = $this->sessionFor($this->memberWithShare(ShareRole::EDITOR));

        $this->jsonRequest('GET', '/api/vehicles/'.$this->vehicle->getId(), session: $editorToken);
        self::assertResponseIsSuccessful();
        self::assertSame(['canEdit' => false, 'canDelete' => false, 'canShare' => false], $this->jsonBody()['permissions']);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $this->vehicle->getId(),
            'occurredAt' => '2026-01-10',
            'category' => 'parking',
            'description' => 'Parcheggio',
            'amount' => '5.00',
        ], session: $editorToken);
        self::assertResponseStatusCodeSame(403, 'Una condivisione non aggiunge dati');

        $this->jsonRequest('POST', '/api/vehicles/'.$this->vehicle->getId().'/shares', ['userIds' => [1]], session: $editorToken);
        self::assertResponseStatusCodeSame(403, 'Una condivisione non allarga né gestisce le condivisioni');

        $this->jsonRequest('DELETE', '/api/vehicles/'.$this->vehicle->getId(), session: $editorToken);
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('POST', '/api/vehicles/'.$this->vehicle->getId().'/archive', session: $editorToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testViewerDoesNotSeeOtherVehiclesOfTheOrganization(): void
    {
        $private = VehicleFactory::createOne(['organization' => $this->org]);

        $this->jsonRequest('GET', '/api/vehicles/'.$private->getId(), session: $this->viewerToken);
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('GET', '/api/maintenances?vehicleId='.$private->getId(), session: $this->viewerToken);
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $private->getId(),
            'occurredAt' => '2026-01-10',
            'category' => 'parking',
            'description' => 'x',
            'amount' => '5.00',
        ], session: $this->viewerToken);
        self::assertResponseStatusCodeSame(403);
    }

    private function memberWithShare(ShareRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $this->org, 'user' => $user, 'role' => OrgRole::MEMBER]);
        VehicleShareFactory::createOne(['vehicle' => $this->vehicle, 'user' => $user, 'role' => $role]);

        return $user;
    }

}
