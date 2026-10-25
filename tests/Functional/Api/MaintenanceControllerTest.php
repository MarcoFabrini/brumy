<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Component;
use App\Entity\UserValue;
use App\Enum\UserValueKind;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class MaintenanceControllerTest extends ApiTestCase
{
    public function testListRequiresVehicleIdQueryParam(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->jsonRequest('GET', '/api/maintenances', accessToken: $token);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('query.vehicle_id_required', $this->jsonBody()['title']);
    }

    public function testListReturnsMaintenancesForVehicleScopedToOrg(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        MaintenanceFactory::createMany(3, ['organization' => $org, 'vehicle' => $vehicle]);

        $this->jsonRequest('GET', '/api/maintenances?vehicleId='.$vehicle->getId(), accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->jsonBody());
    }

    public function testCreateMaintenancePersists(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/maintenances', [
            'vehicleId' => $vehicle->getId(),
            'performedAt' => '2026-05-01',
            'km' => 50000,
            'type' => 'oil_change',
            'category' => 'scheduled',
            'description' => 'Cambio olio 5W30',
            'cost' => '120.50',
            'workshop' => 'Officina Rossi',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame('120.50', $body['cost']);
        self::assertSame($vehicle->getId(), $body['vehicle']['id']);
    }

    public function testCreateValidatesAmountFormat(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/maintenances', [
            'vehicleId' => $vehicle->getId(),
            'performedAt' => '2026-05-01',
            'km' => 50000,
            'type' => 'oil_change',
            'description' => 'test',
            'cost' => 'not-a-number',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateMaintenance(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $maintenance = MaintenanceFactory::createOne([
            'organization' => $org,
            'vehicle' => $vehicle,
            'description' => 'old',
        ]);

        $this->jsonRequest('PUT', '/api/maintenances/'.$maintenance->getId(), [
            'vehicleId' => $vehicle->getId(),
            'performedAt' => '2026-06-01',
            'km' => 51000,
            'type' => 'filters',
            'category' => 'scheduled',
            'description' => 'updated description',
        ], accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('updated description', $this->jsonBody()['description']);
    }

    public function testDeleteMaintenance(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $maintenance = MaintenanceFactory::createOne([
            'organization' => $org,
            'vehicle' => $vehicle,
        ]);

        $this->jsonRequest('DELETE', '/api/maintenances/'.$maintenance->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(204);
    }

    public function testListPaginationRespectsLimit(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        MaintenanceFactory::createMany(25, ['organization' => $org, 'vehicle' => $vehicle]);

        $this->jsonRequest('GET', '/api/maintenances?vehicleId='.$vehicle->getId().'&page=1&limit=10', accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertCount(10, $this->jsonBody());
    }

    public function testCreateStoresWorkshopAsOwnerValue(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);

        $this->jsonRequest('POST', '/api/maintenances', $this->maintenanceBody($vehicle->getId(), ' Officina  Rossi '), accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        $workshop = $this->jsonBody()['workshop'];
        self::assertSame('Officina Rossi', $workshop['name']);

        $this->jsonRequest('POST', '/api/maintenances', $this->maintenanceBody($vehicle->getId(), 'officina rossi'), accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($workshop['id'], $this->jsonBody()['workshop']['id']);

        $value = static::getContainer()->get('doctrine')->getRepository(UserValue::class)->find($workshop['id']);
        self::assertNotNull($value);
        self::assertSame($user->getId(), $value->getUser()->getId());
        self::assertSame(UserValueKind::WORKSHOP, $value->getKind());
    }

    public function testBlankWorkshopIsNull(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/maintenances', $this->maintenanceBody($vehicle->getId(), '  '), accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->jsonBody()['workshop']);
        self::assertSame([], static::getContainer()->get('doctrine')->getRepository(UserValue::class)->findAll());
    }

    public function testShopIsAnOptionalValueOfItsOwnListIndependentFromTheWorkshop(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);

        // Fai da te: negozio senza officina
        $this->jsonRequest('POST', '/api/maintenances', [...$this->maintenanceBody($vehicle->getId(), null), 'shop' => ' Ricambi  Bianchi '], accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->jsonBody()['workshop']);
        $shop = $this->jsonBody()['shop'];
        self::assertSame('Ricambi Bianchi', $shop['name']);
        $value = static::getContainer()->get('doctrine')->getRepository(UserValue::class)->find($shop['id']);
        self::assertSame(UserValueKind::SHOP, $value?->getKind());

        // Stesso nome come officina: è un'altra lista, quindi un'altra voce
        $this->jsonRequest('POST', '/api/maintenances', [...$this->maintenanceBody($vehicle->getId(), 'Ricambi Bianchi'), 'shop' => 'ricambi bianchi'], accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($shop['id'], $this->jsonBody()['shop']['id']);
        self::assertNotSame($shop['id'], $this->jsonBody()['workshop']['id']);
        $id = $this->jsonBody()['id'];

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/shop', accessToken: $token);
        self::assertSame(['Ricambi Bianchi'], array_column($this->jsonBody(), 'name'));

        $this->jsonRequest('PUT', '/api/maintenances/'.$id, [...$this->maintenanceBody($vehicle->getId(), 'Ricambi Bianchi'), 'shop' => ''], accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody()['shop']);
    }

    public function testShopIsLimitedTo200Characters(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/maintenances', [...$this->maintenanceBody($vehicle->getId(), null), 'shop' => str_repeat('a', 201)], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('shop', $this->jsonBody()['errors'][0]['field']);
    }

    public function testInspectionTypeAndSectionGoTogether(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        // Client vecchio: tipo revisione con la sezione di default
        $this->jsonRequest('POST', '/api/maintenances', [...$this->maintenanceBody($vehicle->getId(), null), 'type' => 'inspection'], accessToken: $token);
        self::assertSame(['inspection', 'inspection'], [$this->jsonBody()['type'], $this->jsonBody()['category']]);

        // Sezione revisione con un altro tipo
        $this->jsonRequest('POST', '/api/maintenances', [...$this->maintenanceBody($vehicle->getId(), null), 'type' => 'other', 'category' => 'inspection'], accessToken: $token);
        self::assertSame(['inspection', 'inspection'], [$this->jsonBody()['type'], $this->jsonBody()['category']]);

        $this->jsonRequest('POST', '/api/maintenances', [...$this->maintenanceBody($vehicle->getId(), null), 'category' => 'unscheduled'], accessToken: $token);
        self::assertSame(['oil_change', 'unscheduled'], [$this->jsonBody()['type'], $this->jsonBody()['category']]);

        foreach (['inspection' => 2, 'unscheduled' => 1, 'scheduled' => 0] as $category => $count) {
            $this->jsonRequest('GET', '/api/maintenances?vehicleId='.$vehicle->getId().'&category='.$category, accessToken: $token);
            self::assertCount($count, $this->jsonBody(), $category);
        }
    }

    public function testComponentsAreFoundOrCreatedByNameInTheVehicle(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $other = VehicleFactory::createOne(['organization' => $org]);
        $existing = new Component($vehicle, 'Filtro olio Mann');
        $foreign = new Component($other, 'Olio 5W30');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($existing);
        $em->persist($foreign);
        $em->flush();

        $this->jsonRequest('POST', '/api/maintenances', [
            ...$this->maintenanceBody($vehicle->getId(), null),
            'components' => [' filtro  olio mann ', 'Olio 5W30', 'olio 5w30', '  '],
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        $components = $this->jsonBody()['components'];
        self::assertSame(['Filtro olio Mann', 'Olio 5W30'], array_column($components, 'name'));
        self::assertSame($existing->getId(), $components[0]['id']);
        self::assertNotSame($foreign->getId(), $components[1]['id'], 'il componente di un altro veicolo non si usa');
        $id = $this->jsonBody()['id'];

        $this->jsonRequest('PUT', '/api/maintenances/'.$id, [...$this->maintenanceBody($vehicle->getId(), null), 'components' => []], accessToken: $token);
        self::assertSame([], $this->jsonBody()['components']);
        $this->jsonRequest('GET', '/api/components?vehicleId='.$vehicle->getId(), accessToken: $token);
        self::assertSame(['Filtro olio Mann', 'Olio 5W30'], array_column($this->jsonBody(), 'name'), 'togliere un componente dal lavoro non lo elimina');
    }

    /**
     * @return array<string, mixed>
     */
    private function maintenanceBody(?int $vehicleId, ?string $workshop): array
    {
        return [
            'vehicleId' => $vehicleId,
            'performedAt' => '2026-05-01',
            'km' => 50000,
            'type' => 'oil_change',
            'category' => 'scheduled',
            'description' => 'Cambio olio',
            'workshop' => $workshop,
        ];
    }
}
