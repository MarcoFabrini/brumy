<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Service\RefreshTokenService;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Un access token non si rinnova partendo da sé stesso e smette di valere appena le sessioni dell'utente vengono
 * revocate (cambio/reset password, cambio email, logout, riuso del refresh token), invece che alla scadenza.
 * Audit run-2, finding `auth/access-token-self-renewal-outlives-session-revocation`.
 */
final class AccessTokenRevocationTest extends ApiTestCase
{
    // -------------------- nessun auto-rinnovo --------------------

    public function testSwitchOrgKeepsTheExpiryOfThePresentedToken(): void
    {
        [$user, $first] = $this->createAuthenticatedUser();
        $second = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $second, 'role' => OrgRole::MEMBER]);
        $exp = time() + 120; // diverso da now + 900: un exp nuovo si vedrebbe
        $token = $this->tokenExpiringAt($user, $first, $exp);

        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => $second->getId()], accessToken: $token);

        self::assertResponseIsSuccessful();
        $claims = $this->claims((string) $this->jsonBody()['access_token']);
        self::assertSame($exp, $claims['exp'], 'Lo switch non deve allungare la vita del token');
        self::assertSame($second->getId(), $claims['active_org_id']);
        self::assertSame($second->getId(), $this->jsonBody()['active_org_id']);
    }

    public function testProfileUpdateWithoutEmailChangeKeepsTheExpiryOfThePresentedToken(): void
    {
        [$user, $org] = $this->createAuthenticatedUser();
        $exp = time() + 120;
        $token = $this->tokenExpiringAt($user, $org, $exp);

        $this->jsonRequest('PUT', '/api/auth/profile', $this->profile($user->getEmail(), 'Anna'), accessToken: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame('Anna', $body['user']['firstName']);
        $claims = $this->claims((string) $body['access_token']);
        self::assertSame($exp, $claims['exp'], 'Un profile update non deve allungare la vita del token');
        self::assertSame($org->getId(), $claims['active_org_id']);

        // Il token presentato resta valido: nessuna revoca senza cambio di credenziali.
        $this->jsonRequest('GET', '/api/auth/me', accessToken: $token);
        self::assertResponseIsSuccessful();
    }

    // -------------------- revoca immediata --------------------

    public function testPasswordChangeInvalidatesThePresentedAccessTokenButNotTheReturnedOne(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/auth/password', [
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
            'newPassword' => 'Another-Pass-123',
        ], accessToken: $token);
        self::assertResponseIsSuccessful();
        $fresh = (string) $this->jsonBody()['access_token'];

        $this->assertRejected($token, 'GET', '/api/auth/me');
        $this->assertRejected($token, 'POST', '/api/auth/switch-org', ['organizationId' => $org->getId()]);
        $this->assertRejected($token, 'PUT', '/api/auth/profile', $this->profile($user->getEmail(), 'Rinnovo'));

        $this->jsonRequest('GET', '/api/auth/me', accessToken: $fresh);
        self::assertResponseIsSuccessful();
    }

    public function testPasswordResetInvalidatesEveryAccessTokenIssuedBefore(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $raw = bin2hex(random_bytes(32));
        $this->em()->persist(new PasswordResetToken($user, hash('sha256', $raw), new \DateTimeImmutable('+1 hour')));
        $this->em()->flush();

        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $raw, 'password' => 'Reset-Pass-123']);
        self::assertResponseStatusCodeSame(204);

        $this->assertRejected($token, 'GET', '/api/auth/me');
        $this->assertRejected($token, 'POST', '/api/auth/switch-org', ['organizationId' => $org->getId()]);
    }

    /**
     * Chiedere il cambio email non tocca le sessioni; confermarlo dal link le chiude tutte: ogni access token già
     * emessi, anche quello riemesso dal profilo, smette di valere. Si rientra con il nuovo indirizzo.
     */
    public function testConfirmedEmailChangeInvalidatesEveryAccessToken(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/auth/profile', $this->profile('cambiata@test.it', 'Anna', UserFactory::DEFAULT_PASSWORD), accessToken: $token);
        self::assertResponseIsSuccessful();
        $reissued = (string) $this->jsonBody()['access_token'];
        $link = $this->lastVerificationToken();
        $this->jsonRequest('GET', '/api/auth/me', accessToken: $token);
        self::assertResponseIsSuccessful('La richiesta da sola non chiude le sessioni');

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $link]);
        self::assertResponseIsSuccessful();

        $this->assertRejected($token, 'GET', '/api/auth/me');
        $this->assertRejected($reissued, 'GET', '/api/auth/me');
        $this->jsonRequest('POST', '/api/auth/login', ['email' => 'cambiata@test.it', 'password' => UserFactory::DEFAULT_PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    public function testLogoutInvalidatesAccessTokensWhileOtherDevicesRecoverWithTheirRefreshToken(): void
    {
        UserFactory::createOne(['email' => 'logout-all@test.it']);
        $thisDevice = $this->login('logout-all@test.it');
        $otherDevice = $this->login('logout-all@test.it');

        $this->jsonRequest('POST', '/api/auth/logout', ['refresh_token' => $thisDevice['refresh_token']]);
        self::assertResponseStatusCodeSame(204);

        $this->assertRejected($thisDevice['access_token'], 'GET', '/api/auth/me');
        $this->assertRejected($otherDevice['access_token'], 'GET', '/api/auth/me');

        // L'altro dispositivo (un'altra famiglia) ha ancora la sessione: un refresh trasparente lo rimette in piedi.
        $this->jsonRequest('POST', '/api/auth/refresh', ['refreshToken' => $otherDevice['refresh_token']]);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', '/api/auth/me', accessToken: (string) $this->jsonBody()['access_token']);
        self::assertResponseIsSuccessful();
    }

    public function testRefreshTokenReuseInvalidatesTheAccessTokensIssuedBefore(): void
    {
        UserFactory::createOne(['email' => 'reuse-access@test.it']);
        $session = $this->login('reuse-access@test.it');
        $this->jsonRequest('POST', '/api/auth/refresh', ['refreshToken' => $session['refresh_token']]);
        self::assertResponseIsSuccessful();
        $rotated = ['access_token' => (string) $this->jsonBody()['access_token'], 'refresh_token' => (string) $this->jsonBody()['refresh_token']];
        $this->ageRotation($session['refresh_token'], RefreshTokenService::REUSE_GRACE_SECONDS + 5);

        $this->jsonRequest('POST', '/api/auth/refresh', ['refreshToken' => $session['refresh_token']]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['type' => 'about:blank', 'title' => 'auth.invalid_refresh_token', 'status' => 401], $this->jsonBody());
        $this->assertRejected($session['access_token'], 'GET', '/api/auth/me');
        $this->assertRejected($rotated['access_token'], 'GET', '/api/auth/me');
        $this->jsonRequest('POST', '/api/auth/refresh', ['refreshToken' => $rotated['refresh_token']]);
        self::assertResponseStatusCodeSame(401, 'La famiglia è revocata');
    }

    // -------------------- helpers --------------------

    /**
     * @param array<string, mixed>|null $body
     */
    private function assertRejected(string $token, string $method, string $uri, ?array $body = null): void
    {
        $this->jsonRequest($method, $uri, $body, accessToken: $token);

        self::assertResponseStatusCodeSame(401, "$method $uri deve rifiutare il token revocato");
        self::assertSame('http.401', $this->jsonBody()['title']);
    }

    /** @return array<string, mixed> */
    private function profile(string $email, string $firstName, ?string $currentPassword = null): array
    {
        return array_filter([
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => 'Rossi',
            'locale' => 'it',
            'currentPassword' => $currentPassword,
        ], static fn (?string $v): bool => $v !== null);
    }

    private function tokenExpiringAt(User $user, Organization $org, int $exp): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($user, [
            'active_org_id' => $org->getId(),
            'exp' => $exp,
        ]);
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        $payload = json_decode(base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function login(string $email): array
    {
        $this->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => UserFactory::DEFAULT_PASSWORD]);
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();

        return ['access_token' => (string) $body['access_token'], 'refresh_token' => (string) $body['refresh_token']];
    }

    /** Sposta indietro la data di rotazione: il test non aspetta davvero la finestra di tolleranza. */
    private function ageRotation(string $plain, int $seconds): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE refresh_tokens SET rotated_at = ? WHERE token = ?',
            [(new \DateTimeImmutable("-$seconds seconds"))->format('Y-m-d H:i:s'), RefreshTokenService::hash($plain)],
        );
        $this->em()->clear();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
