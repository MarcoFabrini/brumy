<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Entity\UserValue;
use App\Enum\FuelType;
use App\Enum\OrgRole;
use App\Enum\UserValueKind;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;

final class RefuelingControllerTest extends ApiTestCase
{
    public function testListRequiresVehicleId(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->jsonRequest('GET', '/api/refuelings', session: $token);
        self::assertResponseStatusCodeSame(400);
    }

    public function testCreateRefuelingComputesTotalCost(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/refuelings', [
            'vehicleId' => $vehicle->getId(),
            'refueledAt' => '2026-05-01',
            'km' => 50000,
            'liters' => '40.000',
            'pricePerLiter' => '1.8000',
            'fuelType' => 'diesel',
            'fullTank' => true,
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        // total_cost è una GENERATED column del DB: 40 × 1.8 = 72.00
        self::assertSame('72.00', $this->jsonBody()['totalCost']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidAmounts')]
    public function testCreateRefuelingRejectsInvalidAmounts(array $overrides): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/refuelings', [
            'vehicleId' => $vehicle->getId(),
            'refueledAt' => '2026-05-01',
            'km' => 50000,
            'liters' => '40.000',
            'pricePerLiter' => '1.8000',
            ...$overrides,
        ], session: $token);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'zero liters' => [['liters' => '0']];
        yield 'empty liters' => [['liters' => '']];
        yield 'liters over the column' => [['liters' => '999999.000']];
        yield 'zero price' => [['pricePerLiter' => '0']];
        yield 'price over the column' => [['pricePerLiter' => '999.0000']];
        yield 'absurd km' => [['km' => 2_000_000_000]];
    }

    public function testListByVehiclePaginated(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        RefuelingFactory::createMany(15, ['organization' => $org, 'vehicle' => $vehicle]);

        $this->jsonRequest('GET', '/api/refuelings?vehicleId='.$vehicle->getId().'&limit=5', session: $token);
        self::assertResponseIsSuccessful();
        self::assertCount(5, $this->jsonBody());
    }

    public function testUpdateRefuelingRecomputesTotalCost(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'liters' => '30.000', 'pricePerLiter' => '1.5000',
        ]);

        $this->jsonRequest('PUT', '/api/refuelings/'.$r->getId(), [
            'vehicleId' => $vehicle->getId(),
            'refueledAt' => '2026-05-01', 'km' => 60000,
            'liters' => '50.000', 'pricePerLiter' => '2.0000',
            'fuelType' => 'diesel', 'fullTank' => true,
        ], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('100.00', $this->jsonBody()['totalCost']);
    }

    public function testDeleteRefueling(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        $id = $r->getId();

        $this->jsonRequest('DELETE', '/api/refuelings/'.$id, session: $token);
        self::assertResponseStatusCodeSame(204);
    }

    public function testCannotCreateRefuelingOnCrossTenantVehicle(): void
    {
        [, , $tokenA] = $this->createAuthenticatedUser();
        [, $orgB] = $this->createAuthenticatedUser();
        $vehicleB = VehicleFactory::createOne(['organization' => $orgB]);

        $this->jsonRequest('POST', '/api/refuelings', [
            'vehicleId' => $vehicleB->getId(),
            'refueledAt' => '2026-05-01', 'km' => 100,
            'liters' => '20.000', 'pricePerLiter' => '1.5000',
            'fuelType' => 'gasoline', 'fullTank' => true,
        ], session: $tokenA);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateStoresStationAsOwnerValue(): void
    {
        // L'admin dell'org salva sul veicolo di un membro: la voce va nella lista del proprietario
        [, $org, $token] = $this->createAuthenticatedUser(OrgRole::OWNER);
        $owner = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $owner, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $owner]);

        $this->jsonRequest('POST', '/api/refuelings', $this->refuelingBody($vehicle->getId(), ' Q8  Via Roma '), session: $token);
        self::assertResponseStatusCodeSame(201);
        $station = $this->jsonBody()['station'];
        self::assertSame('Q8 Via Roma', $station['name']);

        $this->jsonRequest('POST', '/api/refuelings', $this->refuelingBody($vehicle->getId(), 'q8 via roma'), session: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($station['id'], $this->jsonBody()['station']['id']);

        $value = static::getContainer()->get('doctrine')->getRepository(UserValue::class)->find($station['id']);
        self::assertNotNull($value);
        self::assertSame($owner->getId(), $value->getUser()->getId());
        self::assertSame(UserValueKind::STATION, $value->getKind());
    }

    public function testBlankStationIsNull(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/refuelings', $this->refuelingBody($vehicle->getId(), '  '), session: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->jsonBody()['station']);
        self::assertSame([], static::getContainer()->get('doctrine')->getRepository(UserValue::class)->findAll());
    }

    /**
     * @return array<string, mixed>
     */
    private function refuelingBody(?int $vehicleId, ?string $station): array
    {
        return [
            'vehicleId' => $vehicleId,
            'refueledAt' => '2026-05-01', 'km' => 100,
            'liters' => '20.000', 'pricePerLiter' => '1.5000',
            'fuelType' => 'diesel', 'fullTank' => true,
            'station' => $station,
        ];
    }
}
