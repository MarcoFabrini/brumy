<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\OrgRole;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ogni errore HTTP sotto /api ha lo stesso corpo {type, title, status[, errors]}, qualunque sia
 * l'header Accept: il frontend legge `title` (chiave i18n o http.<status>) ed `errors[]`.
 * Prima i 404 di mustFind e i 422 di #[MapRequestPayload] uscivano nel formato di Symfony
 * (pagina HTML, "An error occurred", `violations[]`) e la UI mostrava un errore generico.
 */
final class ApiErrorFormatTest extends ApiTestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptHeaders(): iterable
    {
        yield 'tutto (fetch senza Accept)' => ['*/*'];
        yield 'html (navigazione del browser)' => ['text/html,application/xhtml+xml'];
        yield 'json' => ['application/json'];
    }

    #[DataProvider('acceptHeaders')]
    public function testNotFoundKeepsTheI18nKeyOfTheController(string $accept): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->request('GET', '/api/vehicles/999999', accept: $accept, token: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['type' => 'about:blank', 'title' => 'vehicle.not_found', 'status' => 404], $this->jsonBody());
    }

    #[DataProvider('acceptHeaders')]
    public function testValidationFailureUsesTheProjectShape(string $accept): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->request('POST', '/api/vehicles', ['name' => ''], accept: $accept, token: $token);

        self::assertResponseStatusCodeSame(422);
        $body = $this->jsonBody();
        self::assertSame('validation_failed', $body['title']);
        self::assertSame(422, $body['status']);
        self::assertArrayNotHasKey('violations', $body);
        self::assertNotSame([], $body['errors']);
        foreach ($body['errors'] as $error) {
            self::assertSame(['field', 'message'], array_keys($error));
            self::assertNotSame('', $error['message']);
        }
        self::assertContains('name', array_column($body['errors'], 'field'));
    }

    public function testDtoCallbackViolationKeepsItsI18nKeyOnTheRightField(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->request('POST', '/api/reminders', [
            'vehicleId' => $vehicle->getId(),
            'type' => 'custom',
            'description' => 'Senza scadenza',
        ], accept: '*/*', token: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertContains(
            ['field' => 'dueDate', 'message' => 'reminder.due_required'],
            $this->jsonBody()['errors'],
        );
    }

    public function testWrongTypeInThePayloadIsAFieldError(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->request('POST', '/api/vehicles', [
            'name' => 'Golf', 'brand' => 'VW', 'model' => 'Golf', 'year' => 'duemila',
            'type' => 'car', 'fuelType' => 'diesel', 'initialKm' => 0,
        ], accept: '*/*', token: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertContains('year', array_column($this->jsonBody()['errors'], 'field'));
    }

    public function testDefaultConstraintMessagesBecomeI18nKeys(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->request('POST', '/api/vehicles', [
            'name' => str_repeat('a', 101), 'brand' => '', 'model' => 'Golf', 'year' => 3000,
            'type' => 'car', 'fuelType' => 'diesel', 'initialKm' => -5,
        ], accept: '*/*', token: $token);

        self::assertResponseStatusCodeSame(422);
        $byField = array_column($this->jsonBody()['errors'], 'message', 'field');
        self::assertSame('common.too_long', $byField['name']);
        self::assertSame('common.required', $byField['brand']);
        self::assertSame('vehicle.year.out_of_range', $byField['year']);
        self::assertSame('common.too_small', $byField['initialKm']);
    }

    public function testInvalidEmailAndWrongTypeUseCommonKeys(): void
    {
        $this->request('POST', '/api/auth/forgot-password', ['email' => 'non-una-email'], accept: '*/*');

        self::assertResponseStatusCodeSame(422);
        self::assertContains(['field' => 'email', 'message' => 'account.email.invalid'], $this->jsonBody()['errors']);

        [, , $token] = $this->createAuthenticatedUser();
        $this->request('POST', '/api/vehicles', ['year' => 'duemila'], accept: '*/*', token: $token);
        self::assertContains(['field' => 'year', 'message' => 'common.invalid_value'], $this->jsonBody()['errors']);
    }

    #[DataProvider('unauthenticatedRequests')]
    public function testMissingOrUnknownSessionUsesTheProjectShape(?string $session, string $accept): void
    {
        if ($session !== null) {
            $this->useSession($session);
        }
        $this->client->request('GET', '/api/vehicles', server: ['HTTP_ACCEPT' => $accept]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['type' => 'about:blank', 'title' => 'http.401', 'status' => 401], $this->jsonBody());
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function unauthenticatedRequests(): iterable
    {
        foreach (['*/*', 'text/html,application/xhtml+xml', 'application/json'] as $accept) {
            yield "no session ($accept)" => [null, $accept];
            yield "unknown session id ($accept)" => ['sconosciuta0123456789abcdef', $accept];
        }
    }

    public function testForbiddenHasAStableTitle(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $stranger = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $stranger, 'role' => OrgRole::MEMBER]);

        $this->request('GET', '/api/vehicles/'.$vehicle->getId(), accept: '*/*', token: $this->sessionFor($stranger, $org));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['type' => 'about:blank', 'title' => 'http.403', 'status' => 403], $this->jsonBody());
    }

    public function testUnknownRouteDoesNotLeakTheRoutingMessage(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->request('GET', '/api/does-not-exist', accept: '*/*', token: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['type' => 'about:blank', 'title' => 'http.404', 'status' => 404], $this->jsonBody());
    }

    public function testMethodNotAllowedKeepsTheAllowHeader(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->request('DELETE', '/api/vehicles', accept: '*/*', token: $token);

        self::assertResponseStatusCodeSame(405);
        self::assertSame('http.405', $this->jsonBody()['title']);
        self::assertStringContainsString('GET', (string) $this->client->getResponse()->headers->get('Allow'));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->useSession($token);
        $this->client->request('POST', '/api/vehicles', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => '*/*',
        ], content: '{"name": ');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['type' => 'about:blank', 'title' => 'http.400', 'status' => 400], $this->jsonBody());
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?array $body = null, string $accept = '*/*', ?string $token = null): void
    {
        if ($token !== null) {
            $this->useSession($token);
        }
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => $accept], content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null);
    }

}
