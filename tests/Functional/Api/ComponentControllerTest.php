<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\UserValue;
use App\Enum\OrgRole;
use App\Enum\ShareRole;
use App\Enum\UserValueKind;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;

final class ComponentControllerTest extends ApiTestCase
{
    public function testCreateWithAllFieldsAndShopFromTheOwnerList(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $user, 'role' => ShareRole::ADMIN]);

        $this->jsonRequest('POST', '/api/components', $this->body($vehicle->getId(), ' Pastiglie  Brembo ', [
            'code' => 'P 85 020', 'serialNumber' => 'SN-1', 'shop' => 'Ricambi Bianchi',
            'purchasedAt' => '2026-04-10', 'price' => '45.90', 'notes' => 'anteriori',
        ]), session: $token);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame('Pastiglie Brembo', $body['name']);
        self::assertSame(['P 85 020', 'SN-1', '45.90', 'anteriori'], [$body['code'], $body['serialNumber'], $body['price'], $body['notes']]);
        self::assertSame('Ricambi Bianchi', $body['shop']['name']);
        self::assertStringStartsWith('2026-04-10', $body['purchasedAt']);
        self::assertSame([], $body['maintenances']);
        $shop = static::getContainer()->get('doctrine')->getRepository(UserValue::class)->find($body['shop']['id']);
        self::assertSame([UserValueKind::SHOP, $user->getId()], [$shop?->getKind(), $shop?->getUser()->getId()]);

        $this->jsonRequest('GET', '/api/values/shop', session: $token);
        self::assertSame([1], array_column($this->jsonBody(), 'usageCount'), 'il negozio di un componente conta come uso');
    }

    public function testNameIsUniqueInTheVehicleOnly(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $other = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/components', $this->body($vehicle->getId(), 'Batteria'), session: $token);
        $first = $this->jsonBody()['id'];
        $this->jsonRequest('POST', '/api/components', $this->body($vehicle->getId(), ' BATTERIA '), session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('component.name_taken', $this->jsonBody()['errors'][0]['message']);

        $this->jsonRequest('POST', '/api/components', $this->body($other->getId(), 'Batteria'), session: $token);
        self::assertResponseStatusCodeSame(201);

        $this->jsonRequest('POST', '/api/components', $this->body($vehicle->getId(), 'Tergicristalli'), session: $token);
        $this->jsonRequest('PUT', '/api/components/'.$this->jsonBody()['id'], $this->body($vehicle->getId(), 'batteria'), session: $token);
        self::assertResponseStatusCodeSame(422);
        $this->jsonRequest('PUT', '/api/components/'.$first, $this->body($vehicle->getId(), 'BATTERIA Varta'), session: $token);
        self::assertResponseIsSuccessful();
        self::assertSame('BATTERIA Varta', $this->jsonBody()['name']);
    }

    public function testBlankNameIs422(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/components', $this->body($vehicle->getId(), "\u{00A0} "), session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('component.name.required', $this->jsonBody()['errors'][0]['message']);
    }

    public function testDetailListsTheMaintenancesAndDeleteIsSoft(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        foreach (['2026-01-10' => 'Tagliando', '2026-06-10' => 'Tagliando estivo'] as $date => $description) {
            $this->jsonRequest('POST', '/api/maintenances', [
                'vehicleId' => $vehicle->getId(), 'performedAt' => $date, 'km' => 1000, 'type' => 'oil_change',
                'description' => $description, 'components' => ['Filtro olio'],
            ], session: $token);
            $maintenanceId = $this->jsonBody()['id'];
        }
        $componentId = $this->jsonBody()['components'][0]['id'];

        $this->jsonRequest('GET', '/api/components/'.$componentId, session: $token);
        self::assertSame(['Tagliando estivo', 'Tagliando'], array_column($this->jsonBody()['maintenances'], 'description'));
        self::assertSame(['2026-06-10', 'scheduled'], [$this->jsonBody()['maintenances'][0]['performedAt'], $this->jsonBody()['maintenances'][0]['category']]);

        $this->jsonRequest('DELETE', '/api/components/'.$componentId, session: $token);
        self::assertResponseStatusCodeSame(204);
        $this->jsonRequest('GET', '/api/maintenances/'.$maintenanceId, session: $token);
        self::assertSame([['id' => $componentId, 'name' => 'Filtro olio', 'deleted' => true]], $this->jsonBody()['components'], 'il lavoro lo mostra ancora');
        $vehicleId = $vehicle->getId();
        $this->jsonRequest('GET', '/api/components?vehicleId='.$vehicleId, session: $token);
        self::assertSame([], $this->jsonBody(), 'non è più nell\'elenco');
        $this->jsonRequest('GET', '/api/components/'.$componentId, session: $token);
        self::assertResponseStatusCodeSame(404);

        // Modificare il lavoro senza toccare il componente lo tiene; un lavoro nuovo con lo stesso nome ne crea uno nuovo
        $body = ['vehicleId' => $vehicleId, 'performedAt' => '2026-06-10', 'km' => 1000, 'type' => 'oil_change', 'description' => 'Tagliando estivo'];
        $this->jsonRequest('PUT', '/api/maintenances/'.$maintenanceId, [...$body, 'components' => ['filtro olio']], session: $token);
        self::assertSame($componentId, $this->jsonBody()['components'][0]['id']);
        $this->jsonRequest('POST', '/api/maintenances', [...$body, 'components' => ['Filtro olio']], session: $token);
        self::assertNotSame($componentId, $this->jsonBody()['components'][0]['id']);
        self::assertFalse($this->jsonBody()['components'][0]['deleted']);
    }

    public function testAccessFollowsTheVehicle(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $owner = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $owner, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $shared = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::createOne(['vehicle' => $shared, 'user' => $owner, 'role' => ShareRole::ADMIN]);
        VehicleShareFactory::createOne(['vehicle' => $shared, 'user' => $user, 'role' => ShareRole::VIEWER]);
        $foreign = VehicleFactory::createOne(['organization' => OrganizationFactory::createOne()]);

        $this->jsonRequest('GET', '/api/components?vehicleId='.$shared->getId(), session: $token);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', '/api/components', $this->body($shared->getId(), 'Batteria'), session: $token);
        self::assertResponseStatusCodeSame(403);
        $this->jsonRequest('GET', '/api/components?vehicleId='.$foreign->getId(), session: $token);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function body(?int $vehicleId, string $name, array $extra = []): array
    {
        return ['vehicleId' => $vehicleId, 'name' => $name, ...$extra];
    }
}
