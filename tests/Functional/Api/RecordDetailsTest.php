<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\VehicleFactory;
use App\Tests\Support\ApiTestCase;

/** Ogni scheda ha descrizione e note; il veicolo ha il prezzo d'acquisto. */
final class RecordDetailsTest extends ApiTestCase
{
    public function testMaintenanceHasNotes(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/maintenances', [
            'vehicleId' => $vehicle->getId(), 'performedAt' => '2026-05-01', 'km' => 1000, 'type' => 'brakes',
            'description' => 'Pastiglie anteriori', 'notes' => 'Rumore in frenata da una settimana',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Rumore in frenata da una settimana', $this->jsonBody()['notes']);
        $this->jsonRequest('PUT', '/api/maintenances/'.$this->jsonBody()['id'], [
            'vehicleId' => $vehicle->getId(), 'performedAt' => '2026-05-01', 'km' => 1000, 'type' => 'brakes',
            'description' => 'Pastiglie anteriori', 'notes' => null,
        ], accessToken: $token);
        self::assertNull($this->jsonBody()['notes']);
    }

    public function testRefuelingHasAnOptionalDescription(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $body = [
            'vehicleId' => $vehicle->getId(), 'refueledAt' => '2026-05-10', 'km' => 50500,
            'liters' => '40.000', 'pricePerLiter' => '1.8000', 'fuelType' => $vehicle->getFuelType()->value, 'fullTank' => true,
        ];

        $this->jsonRequest('POST', '/api/refuelings', [...$body, 'description' => ' Viaggio a Roma '], accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Viaggio a Roma', $this->jsonBody()['description']);
        $this->jsonRequest('GET', '/api/refuelings?vehicleId='.$vehicle->getId(), accessToken: $token);
        self::assertSame(['Viaggio a Roma'], array_column($this->jsonBody(), 'description'), 'anche nella lista, per la riga');

        $this->jsonRequest('POST', '/api/refuelings', [...$body, 'km' => 51000, 'description' => '  '], accessToken: $token);
        self::assertNull($this->jsonBody()['description']);
    }

    public function testReminderNotesFollowTheRecurrence(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/reminders', [
            'vehicleId' => $vehicle->getId(), 'type' => 'insurance', 'description' => 'Polizza RCA',
            'dueDate' => '2026-12-31', 'recurrenceMonths' => 12, 'notes' => 'Chiedere preventivo al broker',
        ], accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Chiedere preventivo al broker', $this->jsonBody()['notes']);

        $this->jsonRequest('POST', '/api/reminders/'.$this->jsonBody()['id'].'/complete', accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertSame('Chiedere preventivo al broker', $this->jsonBody()['next']['notes']);
    }

    public function testVehicleHasAPurchasePrice(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/vehicles', [
            'name' => 'La Panda', 'brand' => 'Fiat', 'model' => 'Panda', 'year' => 2019,
            'type' => 'car', 'fuelType' => 'gasoline', 'initialKm' => 30000, 'purchasePrice' => '8500.00',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('8500.00', $this->jsonBody()['purchasePrice']);

        $this->jsonRequest('POST', '/api/vehicles', [
            'name' => 'Errata', 'brand' => 'Fiat', 'model' => 'Panda', 'year' => 2019,
            'type' => 'car', 'fuelType' => 'gasoline', 'initialKm' => 0, 'purchasePrice' => 'tanti',
        ], accessToken: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('purchasePrice', $this->jsonBody()['errors'][0]['field']);
    }
}
