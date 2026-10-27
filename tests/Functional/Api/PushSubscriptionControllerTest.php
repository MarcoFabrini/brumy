<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Tests\Support\ApiTestCase;

final class PushSubscriptionControllerTest extends ApiTestCase
{
    public function testCreateWebSubscriptionRequiresAllWebFields(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        // Missing p256dh + authSecret
        $this->jsonRequest('POST', '/api/push-subscriptions', [
            'platform' => 'web',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/123',
        ], session: $token);

        self::assertResponseStatusCodeSame(400);
    }

    #[DataProvider('disallowedEndpoints')]
    public function testRejectsEndpointsOutsideKnownPushServices(string $endpoint): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/push-subscriptions', [
            'platform' => 'web',
            'endpoint' => $endpoint,
            'p256dh' => 'p256dh-key',
            'authSecret' => 'auth-secret',
        ], session: $token);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disallowedEndpoints(): iterable
    {
        yield 'metadata service' => ['http://169.254.169.254/latest/meta-data'];
        yield 'internal host' => ['https://redis:6379/'];
        yield 'plain http on allowed host' => ['http://fcm.googleapis.com/fcm/send/1'];
        yield 'lookalike suffix' => ['https://evilgoogleapis.com/x'];
        yield 'userinfo trick' => ['https://fcm.googleapis.com@evil.test/x'];
        yield 'userinfo only user' => ['https://user@fcm.googleapis.com/fcm/send/1'];
        yield 'other googleapis subdomain' => ['https://storage.googleapis.com/bucket/obj'];
        yield 'suffix without dot boundary' => ['https://evilnotify.windows.com/x'];
    }

    public function testCreateWebSubscriptionSucceeds(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/push-subscriptions', [
            'platform' => 'web',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/123',
            'p256dh' => 'p256dh-key',
            'authSecret' => 'auth-secret',
            'deviceLabel' => 'Chrome on MacBook',
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('web', $this->jsonBody()['platform']);
    }

    public function testTooManySubscriptionsAreRejectedButExistingOnesStillUpsert(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $payload = static fn (int $i): array => [
            'platform' => 'web',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/dev-'.$i,
            'p256dh' => 'p256dh',
            'authSecret' => 'auth',
        ];

        for ($i = 0; $i < 20; ++$i) {
            $this->jsonRequest('POST', '/api/push-subscriptions', $payload($i), session: $token);
            self::assertResponseStatusCodeSame(201);
        }

        $this->jsonRequest('POST', '/api/push-subscriptions', $payload(20), session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('push.too_many_subscriptions', $this->jsonBody()['title']);

        // Un dispositivo già registrato si può sempre aggiornare (rotazione delle chiavi, etichetta).
        $this->jsonRequest('POST', '/api/push-subscriptions', $payload(3), session: $token);
        self::assertResponseStatusCodeSame(201);

        $this->jsonRequest('GET', '/api/push-subscriptions', session: $token);
        self::assertCount(20, $this->jsonBody());
    }

    public function testUpsertSameEndpointDoesNotDuplicate(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $payload = [
            'platform' => 'web',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/repeat',
            'p256dh' => 'p256dh',
            'authSecret' => 'auth',
        ];

        $this->jsonRequest('POST', '/api/push-subscriptions', $payload, session: $token);
        $this->jsonRequest('POST', '/api/push-subscriptions', $payload, session: $token);

        $this->jsonRequest('GET', '/api/push-subscriptions', session: $token);
        self::assertCount(1, $this->jsonBody(), 'Same endpoint should upsert, not duplicate');
    }

    public function testListReturnsOnlyOwnSubscriptions(): void
    {
        [, , $tokenA] = $this->createAuthenticatedUser();
        [, , $tokenB] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/push-subscriptions', [
            'platform' => 'web',
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/user-a',
            'p256dh' => 'p256dh',
            'authSecret' => 'auth',
        ], session: $tokenA);

        $this->jsonRequest('GET', '/api/push-subscriptions', session: $tokenB);
        self::assertCount(0, $this->jsonBody());
    }

    public function testUnsubscribeRemovesTheOwnSubscriptionByEndpoint(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $payload = $this->webPayload('https://fcm.googleapis.com/fcm/send/to-remove');
        $this->jsonRequest('POST', '/api/push-subscriptions', $payload, session: $token);
        self::assertResponseStatusCodeSame(201);

        $this->jsonRequest('POST', '/api/push-subscriptions/unsubscribe', $payload, session: $token);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/push-subscriptions', session: $token);
        self::assertSame([], $this->jsonBody(), 'La subscription del device è stata rimossa');
    }

    public function testUnsubscribeIsIdempotent(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/push-subscriptions/unsubscribe', $this->webPayload('https://fcm.googleapis.com/fcm/send/never-registered'), session: $token);

        self::assertResponseStatusCodeSame(204);
    }

    public function testUnsubscribeDoesNotRemoveAnotherUsersSubscription(): void
    {
        [, , $tokenA] = $this->createAuthenticatedUser();
        [, , $tokenB] = $this->createAuthenticatedUser();
        $payload = $this->webPayload('https://fcm.googleapis.com/fcm/send/owned-by-a');
        $this->jsonRequest('POST', '/api/push-subscriptions', $payload, session: $tokenA);
        self::assertResponseStatusCodeSame(201);

        // B conosce l'endpoint di A: la rimozione è per (utente, endpoint), quindi non trova nulla.
        $this->jsonRequest('POST', '/api/push-subscriptions/unsubscribe', $payload, session: $tokenB);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/push-subscriptions', session: $tokenA);
        self::assertCount(1, $this->jsonBody(), 'La subscription di A è intatta');
    }

    public function testUnsubscribeRequiresAuthentication(): void
    {
        $this->jsonRequest('POST', '/api/push-subscriptions/unsubscribe', $this->webPayload('https://fcm.googleapis.com/fcm/send/anon'));

        self::assertResponseStatusCodeSame(401);
    }

    public function testDeleteRemovesTheOwnSubscription(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $id = $this->subscribe($token, 'https://fcm.googleapis.com/fcm/send/delete-me');

        $this->jsonRequest('DELETE', '/api/push-subscriptions/'.$id, session: $token);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/push-subscriptions', session: $token);
        self::assertSame([], $this->jsonBody());
    }

    public function testDeleteOfAnotherUsersSubscriptionIsForbiddenAndLeavesItInPlace(): void
    {
        [, , $tokenA] = $this->createAuthenticatedUser();
        [, , $tokenB] = $this->createAuthenticatedUser();
        $id = $this->subscribe($tokenA, 'https://fcm.googleapis.com/fcm/send/not-yours');

        $this->jsonRequest('DELETE', '/api/push-subscriptions/'.$id, session: $tokenB);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('http.403', $this->jsonBody()['title']);
        $this->jsonRequest('GET', '/api/push-subscriptions', session: $tokenA);
        self::assertSame([$id], array_column($this->jsonBody(), 'id'), 'La subscription di A esiste ancora');
    }

    public function testDeleteOfUnknownSubscriptionIsNotFound(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('DELETE', '/api/push-subscriptions/999999', session: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('push.not_found', $this->jsonBody()['title']);
    }

    public function testDeleteRequiresAuthentication(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $id = $this->subscribe($token, 'https://fcm.googleapis.com/fcm/send/anon-delete');

        $this->client->getCookieJar()->clear(); // richiesta anonima
        $this->jsonRequest('DELETE', '/api/push-subscriptions/'.$id);
        self::assertResponseStatusCodeSame(401);

        $this->jsonRequest('GET', '/api/push-subscriptions', session: $token);
        self::assertCount(1, $this->jsonBody(), 'Senza token non si cancella nulla');
    }

    /**
     * @return array{platform: string, endpoint: string, p256dh: string, authSecret: string}
     */
    private function webPayload(string $endpoint): array
    {
        return ['platform' => 'web', 'endpoint' => $endpoint, 'p256dh' => 'p256dh-key', 'authSecret' => 'auth-secret'];
    }

    private function subscribe(string $token, string $endpoint): int
    {
        $this->jsonRequest('POST', '/api/push-subscriptions', $this->webPayload($endpoint), session: $token);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->jsonBody()['id'];
    }
}
