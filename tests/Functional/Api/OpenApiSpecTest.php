<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Smoke contract test su OpenAPI spec (closes #2.7).
 *
 * Verifica che `/api/doc.json` esponga uno spec OpenAPI 3.0 valido con:
 * - tutti gli endpoint /api/* annotati (no regressioni quando si aggiunge route senza OA\*)
 * - security scheme Bearer presente
 * - schemas generati per entity principali
 *
 * Test minimo deliberato — full validation richiede `nelmio/openapi-validator`
 * o `league/openapi-psr7-validator`, fuori scope MVP.
 */
final class OpenApiSpecTest extends ApiTestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonRequest('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();
        $this->spec = $this->jsonBody();
    }

    public function testSpecHasOpenApiVersion(): void
    {
        self::assertArrayHasKey('openapi', $this->spec);
        self::assertStringStartsWith('3.', $this->spec['openapi']);
    }

    public function testSpecHasTitleAndVersion(): void
    {
        self::assertSame('Brumy API', $this->spec['info']['title']);
        self::assertSame('1.0.0', $this->spec['info']['version']);
    }

    public function testSpecHasBearerSecurityScheme(): void
    {
        $schemes = $this->spec['components']['securitySchemes'] ?? [];
        self::assertArrayHasKey('Bearer', $schemes);
        self::assertSame('http', $schemes['Bearer']['type']);
        self::assertSame('bearer', $schemes['Bearer']['scheme']);
        self::assertSame('JWT', $schemes['Bearer']['bearerFormat']);
    }

    public function testSpecCoversAllExpectedPaths(): void
    {
        $expected = [
            '/api/health',
            '/api/auth/register',
            '/api/auth/registration',
            '/api/auth/login',
            '/api/auth/refresh',
            '/api/auth/logout',
            '/api/auth/switch-org',
            '/api/auth/me',
            '/api/vehicles',
            '/api/vehicles/{id}',
            '/api/vehicles/{id}/stats',
            '/api/vehicles/{id}/archive',
            '/api/vehicles/{id}/share-candidates',
            '/api/vehicles/{id}/shares',
            '/api/vehicles/{id}/shares/{shareId}',
            '/api/vehicles/{id}/transfer',
            '/api/vehicles/{id}/transfer-candidates',
            '/api/vehicles/{id}/charts',
            '/api/maintenances',
            '/api/maintenances/{id}',
            '/api/refuelings',
            '/api/refuelings/{id}',
            '/api/expenses',
            '/api/expenses/{id}',
            '/api/reminders',
            '/api/reminders/{id}',
            '/api/reminders/{id}/complete',
            '/api/dashboard/charts',
            '/api/attachments',
            '/api/attachments/{id}',
            '/api/push-subscriptions',
            '/api/push-subscriptions/{id}',
            '/api/organizations',
            '/api/organizations/{id}',
            '/api/organizations/{id}/members',
            '/api/organizations/{id}/members/{memberId}',
            '/api/values/{id}/fuel-station',
            '/api/fuel-prices/status',
            '/api/fuel-prices/nearby',
            '/api/fuel-prices/search',
            '/api/fuel-prices/stations/{id}',
        ];

        $actual = array_keys($this->spec['paths']);
        sort($expected);
        sort($actual);

        $missing = array_diff($expected, $actual);
        self::assertEmpty($missing, 'Missing paths: '.implode(', ', $missing));
    }

    public function testEntitySchemasGenerated(): void
    {
        $schemas = $this->spec['components']['schemas'] ?? [];
        $expectedSchemas = ['Vehicle', 'Maintenance', 'Refueling', 'Expense', 'Reminder'];

        foreach ($expectedSchemas as $name) {
            self::assertArrayHasKey(
                $name,
                $schemas,
                "Schema '$name' should be auto-generated via Model attribute",
            );
        }
    }

    public function testReminderUpdateSchemaDoesNotRequireAVehicleId(): void
    {
        $ref = $this->spec['paths']['/api/reminders/{id}']['put']['requestBody']['content']['application/json']['schema']['$ref'] ?? '';
        $schema = $this->spec['components']['schemas'][basename($ref)] ?? null;
        self::assertNotNull($schema, 'PUT /api/reminders/{id} should reference a request schema');
        self::assertArrayNotHasKey('vehicleId', $schema['properties']);

        $createRef = $this->spec['paths']['/api/reminders']['post']['requestBody']['content']['application/json']['schema']['$ref'] ?? '';
        self::assertArrayHasKey('vehicleId', $this->spec['components']['schemas'][basename($createRef)]['properties']);
    }

    public function testHealthEndpointNoAuthRequired(): void
    {
        $health = $this->spec['paths']['/api/health']['get'] ?? null;
        self::assertNotNull($health);
        // security: [] disabilita Bearer requirement
        self::assertArrayHasKey('security', $health);
        self::assertSame([], $health['security']);
    }

    public function testAuthLoginEndpointNoAuthRequired(): void
    {
        $login = $this->spec['paths']['/api/auth/login']['post'] ?? null;
        self::assertNotNull($login);
        self::assertSame([], $login['security']);
    }
}
