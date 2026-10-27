<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\OrgRole;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Support\ApiTestCase;

final class AuditLogControllerTest extends ApiTestCase
{
    public function testListReturnsLogsForActiveOrganization(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        // Trigger audit via vehicle creation
        VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('GET', '/api/audit-logs', session: $token);

        self::assertResponseIsSuccessful();
        /** @var list<array{entityClass: string}> $body */
        $body = $this->jsonBody();
        self::assertNotEmpty($body);
        self::assertSame('Vehicle', $body[0]['entityClass']);
    }

    public function testListRequiresOrgAdmin(): void
    {
        [, , $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);

        $this->jsonRequest('GET', '/api/audit-logs', session: $token);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListDoesNotLeakAcrossOrganizations(): void
    {
        [, $orgA, $tokenA] = $this->createAuthenticatedUser();
        [, $orgB, ] = $this->createAuthenticatedUser();

        VehicleFactory::createOne(['organization' => $orgB, 'name' => 'Other org vehicle']);

        $this->jsonRequest('GET', '/api/audit-logs', session: $tokenA);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        foreach ($body as $log) {
            self::assertNotSame('Vehicle', $log['entityClass'] ?? null, 'Should not include other org logs');
        }
    }
}
