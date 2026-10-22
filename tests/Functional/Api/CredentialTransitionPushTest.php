<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reset password, cambio password e cambio email cancellano le push subscription di tutti i dispositivi
 * dell'utente: chi aveva accesso prima smette di ricevere le notifiche. Il client corrente si ri-registra
 * (upsert idempotente). Audit run-2, finding `auth/credential-transitions-keep-push-subscriptions`.
 */
final class CredentialTransitionPushTest extends ApiTestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/this-device';

    public function testPasswordResetDeletesTheUsersSubscriptionsOnly(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        [$other, , $otherToken] = $this->createAuthenticatedUser();
        // Prima delle richieste HTTP: dopo, il kernel riparte e `$user` non è più gestito da questo EntityManager.
        $raw = bin2hex(random_bytes(32));
        $this->em()->persist(new PasswordResetToken($user, hash('sha256', $raw), new \DateTimeImmutable('+1 hour')));
        $this->em()->flush();
        $this->subscribe($token, self::ENDPOINT);
        $this->subscribe($token, 'https://fcm.googleapis.com/fcm/send/stolen-device');
        $this->subscribe($otherToken, 'https://fcm.googleapis.com/fcm/send/other-user');

        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $raw, 'password' => 'Reset-Pass-123']);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(0, $this->subscriptionsOf($user));
        self::assertSame(1, $this->subscriptionsOf($other), 'Le subscription di un altro utente non si toccano');
    }

    public function testPasswordChangeDeletesTheUsersSubscriptionsAndTheCurrentDeviceCanRegisterAgain(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        [$other, , $otherToken] = $this->createAuthenticatedUser();
        $this->subscribe($token, self::ENDPOINT);
        $this->subscribe($otherToken, 'https://fcm.googleapis.com/fcm/send/other-user');

        $this->jsonRequest('PUT', '/api/auth/password', [
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
            'newPassword' => 'Another-Pass-123',
        ], accessToken: $token);
        self::assertResponseIsSuccessful();
        $fresh = (string) $this->jsonBody()['access_token'];

        self::assertSame(0, $this->subscriptionsOf($user));
        self::assertSame(1, $this->subscriptionsOf($other));

        // Ri-registrazione del dispositivo corrente (lo stesso endpoint): una sola riga.
        $this->subscribe($fresh, self::ENDPOINT);
        $this->subscribe($fresh, self::ENDPOINT);
        self::assertSame(1, $this->subscriptionsOf($user));
    }

    public function testEmailChangeDeletesTheUsersSubscriptions(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        [$other, , $otherToken] = $this->createAuthenticatedUser();
        $this->subscribe($token, self::ENDPOINT);
        $this->subscribe($otherToken, 'https://fcm.googleapis.com/fcm/send/other-user');

        $this->jsonRequest('PUT', '/api/auth/profile', $this->profile('nuova-email@test.it', UserFactory::DEFAULT_PASSWORD), accessToken: $token);
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->subscriptionsOf($user));
        self::assertSame(1, $this->subscriptionsOf($other));
    }

    public function testProfileUpdateWithoutEmailChangeKeepsTheSubscriptions(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $this->subscribe($token, self::ENDPOINT);

        $this->jsonRequest('PUT', '/api/auth/profile', $this->profile($user->getEmail()), accessToken: $token);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->subscriptionsOf($user));
    }

    public function testLogoutKeepsTheSubscriptions(): void
    {
        $user = UserFactory::createOne(['email' => 'push-logout@test.it']);
        $this->jsonRequest('POST', '/api/auth/login', ['email' => 'push-logout@test.it', 'password' => UserFactory::DEFAULT_PASSWORD]);
        self::assertResponseIsSuccessful();
        $session = $this->jsonBody();
        $this->subscribe((string) $session['access_token'], self::ENDPOINT);

        $this->jsonRequest('POST', '/api/auth/logout', ['refresh_token' => $session['refresh_token']]);
        self::assertResponseStatusCodeSame(204);

        // Il logout lato client disiscrive già il proprio dispositivo; il server non cancella quelli degli altri.
        self::assertSame(1, $this->subscriptionsOf($user));
    }

    // -------------------- helpers --------------------

    private function subscribe(string $token, string $endpoint): void
    {
        $this->jsonRequest('POST', '/api/push-subscriptions', [
            'platform' => 'web',
            'endpoint' => $endpoint,
            'p256dh' => 'p256dh-key',
            'authSecret' => 'auth-secret',
        ], accessToken: $token);
        self::assertResponseStatusCodeSame(201);
    }

    private function subscriptionsOf(User $user): int
    {
        $this->em()->clear();

        return static::getContainer()->get(PushSubscriptionRepository::class)->countForUser($user);
    }

    /** @return array<string, string> */
    private function profile(string $email, ?string $currentPassword = null): array
    {
        return array_filter([
            'email' => $email,
            'firstName' => 'Anna',
            'lastName' => 'Rossi',
            'locale' => 'it',
            'currentPassword' => $currentPassword,
        ], static fn (?string $v): bool => $v !== null);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
