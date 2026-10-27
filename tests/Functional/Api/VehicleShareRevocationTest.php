<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;

/**
 * Revoca di una condivisione: dev'essere effettiva (chi la perde non vede più il veicolo) e legata al
 * veicolo dell'URL (lo shareId di un altro veicolo non si può cancellare passando dal proprio).
 */
final class VehicleShareRevocationTest extends ApiTestCase
{
    public function testRevokingAShareRemovesTheViewersAccessImmediately(): void
    {
        [$owner, $org, $ownerToken] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $owner]);
        $viewer = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $viewer, 'role' => OrgRole::MEMBER]);
        $share = VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $viewer]);
        $viewerToken = $this->sessionFor($viewer, $org);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId(), session: $viewerToken);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('DELETE', '/api/vehicles/'.$vehicle->getId().'/shares/'.$share->getId(), session: $ownerToken);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId(), session: $viewerToken);
        self::assertResponseStatusCodeSame(403, 'Con lo stesso token il veicolo non è più raggiungibile');
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', session: $viewerToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAShareOfAnotherVehicleCannotBeRevokedThroughTheOwnVehicleUrl(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $mine = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $mine, 'user' => $owner]);
        $someoneElsesVehicle = VehicleFactory::createOne(['organization' => $org]);
        $foreignShare = VehicleShareFactory::createOne(['vehicle' => $someoneElsesVehicle]);

        $this->jsonRequest('DELETE', '/api/vehicles/'.$mine->getId().'/shares/'.$foreignShare->getId(), session: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('share.not_found', $this->jsonBody()['title']);
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM vehicle_shares WHERE id = ?', [$foreignShare->getId()]));
    }

    public function testAViewerCannotRevokeShares(): void
    {
        [$viewer, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $viewer]);
        $other = VehicleShareFactory::createOne(['vehicle' => $vehicle]);

        $this->jsonRequest('DELETE', '/api/vehicles/'.$vehicle->getId().'/shares/'.$other->getId(), session: $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM vehicle_shares WHERE id = ?', [$other->getId()]));
    }

    public function testAVehicleOfAnotherOrganizationIsNotFoundWhenRevoking(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        [, $otherOrg] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $otherOrg]);
        $share = VehicleShareFactory::createOne(['vehicle' => $vehicle]);

        $this->jsonRequest('DELETE', '/api/vehicles/'.$vehicle->getId().'/shares/'.$share->getId(), session: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM vehicle_shares WHERE id = ?', [$share->getId()]));
    }

}
