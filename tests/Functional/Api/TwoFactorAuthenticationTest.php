<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Service\SecretCipher;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use OTPHP\TOTP;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Verifica in due passaggi (Scheb, TOTP + codici di recupero): attivazione dal profilo, login a due passaggi, limite
 * ai codici sbagliati, disattivazione e codici nuovi.
 */
final class TwoFactorAuthenticationTest extends ApiTestCase
{
    private const CODE_FORMAT = '/^[0-9a-hjkmnp-tv-z]{4}-[0-9a-hjkmnp-tv-z]{4}-[0-9a-hjkmnp-tv-z]{4}$/';

    // -------------------- attivazione --------------------

    public function testSetupReturnsSecretQrAndUriAndStoresTheSecretEncrypted(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/auth/2fa/setup', session: $session);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame(['secret', 'otpauthUri', 'qrCode'], array_keys($body));
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $body['secret']);
        self::assertStringStartsWith('otpauth://totp/', $body['otpauthUri']);
        self::assertStringContainsString('issuer=Brumy', $body['otpauthUri']);
        self::assertStringContainsString('secret='.$body['secret'], $body['otpauthUri']);
        self::assertStringStartsWith('data:image/svg+xml', $body['qrCode']);

        $row = $this->row($user);
        self::assertNotSame($body['secret'], $row['totp_secret']);
        self::assertSame($body['secret'], static::getContainer()->get(SecretCipher::class)->decrypt((string) $row['totp_secret']));
        self::assertNull($row['totp_enabled_at'], 'In attesa di conferma: il login non cambia');

        $this->jsonRequest('GET', '/api/auth/me', session: $session);
        self::assertFalse($this->jsonBody()['twoFactorEnabled']);
    }

    public function testEnableNeedsThePasswordAndACodeFromTheApp(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        $secret = $this->beginSetup($session);

        $this->jsonRequest('POST', '/api/auth/2fa/enable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $this->wrongCode($secret)], session: $session);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'code', 'message' => 'two_factor.invalid_code']], $this->jsonBody()['errors']);

        $this->jsonRequest('POST', '/api/auth/2fa/enable', ['currentPassword' => 'sbagliata', 'code' => $this->code($secret)], session: $session);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('auth.invalid_current_password', $this->jsonBody()['title']);

        $this->jsonRequest('POST', '/api/auth/2fa/enable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $this->code($secret)], session: $session);
        self::assertResponseIsSuccessful();
        $codes = $this->jsonBody()['backupCodes'];
        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression(self::CODE_FORMAT, $code);
        }

        $stored = (string) $this->row($user)['backup_codes'];
        foreach ($codes as $code) {
            self::assertStringNotContainsString($code, $stored, 'Nessun codice in chiaro nel DB');
            self::assertStringNotContainsString(str_replace('-', '', $code), $stored);
        }
        self::assertNotNull($this->row($user)['totp_enabled_at']);

        $this->jsonRequest('GET', '/api/auth/me', session: $session);
        self::assertTrue($this->jsonBody()['twoFactorEnabled']);
        self::assertSame(10, $this->jsonBody()['backupCodesRemaining']);
    }

    public function testSetupWhileEnabledAndEnableWithoutSetupAreConflicts(): void
    {
        [, , $session] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/auth/2fa/enable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => '123456'], session: $session);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('two_factor.setup_required', $this->jsonBody()['title']);

        $this->enable($session);

        $this->jsonRequest('POST', '/api/auth/2fa/setup', session: $session);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('two_factor.already_enabled', $this->jsonBody()['title']);
        $this->jsonRequest('POST', '/api/auth/2fa/enable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => '123456'], session: $session);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('two_factor.already_enabled', $this->jsonBody()['title']);
    }

    public function testManagementEndpointsNeedAFullSession(): void
    {
        foreach (['setup', 'enable', 'disable', 'backup-codes'] as $action) {
            $this->jsonRequest('POST', '/api/auth/2fa/'.$action, ['currentPassword' => 'x', 'code' => '123456']);
            self::assertResponseStatusCodeSame(401, $action);
        }
    }

    // -------------------- login --------------------

    public function testLoginWithTwoFactorNeedsTheCodeBeforeAnyProtectedRoute(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [$secret] = $this->enable($session);
        $this->client->getCookieJar()->clear();

        $this->login($user);
        self::assertResponseIsSuccessful();
        self::assertSame(['twoFactorRequired' => true], $this->jsonBody());
        self::assertNull($this->responseCookie(self::REMEMBER_ME_COOKIE), 'Il remember-me aspetta il codice');
        $pending = $this->responseCookie(self::SESSION_COOKIE);
        self::assertNotNull($pending);

        foreach (['/api/auth/me', '/api/vehicles'] as $protected) {
            $this->jsonRequest('GET', $protected);
            self::assertResponseStatusCodeSame(401, $protected);
            self::assertSame(['type' => 'about:blank', 'title' => 'auth.two_factor_required', 'status' => 401], $this->jsonBody());
        }

        $this->check($this->wrongCode($secret));
        self::assertResponseStatusCodeSame(401);
        self::assertSame('auth.invalid_two_factor_code', $this->jsonBody()['title']);

        $this->check($this->code($secret));
        self::assertResponseIsSuccessful();
        self::assertSame(['twoFactorRequired' => false], $this->jsonBody());
        self::assertNotNull($this->responseCookie(self::REMEMBER_ME_COOKIE));
        $complete = $this->responseCookie(self::SESSION_COOKIE);
        self::assertNotNull($complete);
        self::assertNotSame($pending, $complete, 'Id di sessione rigenerato a verifica completata');

        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), $this->jsonBody()['id']);
    }

    public function testARecoveryCodeSignsInOnlyOnce(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [, $codes] = $this->enable($session);
        $this->client->getCookieJar()->clear();

        $this->login($user);
        $this->check(strtoupper(str_replace('-', ' ', $codes[3]))); // maiuscole e spazi non contano
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertSame(9, $this->jsonBody()['backupCodesRemaining']);

        $this->jsonRequest('POST', '/api/auth/logout');
        $this->client->getCookieJar()->clear();
        $this->login($user);
        $this->check($codes[3]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('auth.invalid_two_factor_code', $this->jsonBody()['title']);
    }

    public function testWrongCodesAreLimitedAndTheRightOneDoesNotBypassTheLimit(): void
    {
        $this->keepRateLimiterCountersForTheWholeTest();
        [$user, , $session] = $this->createAuthenticatedUser();
        [$secret] = $this->enable($session);
        $this->client->getCookieJar()->clear();
        $this->login($user);

        for ($i = 1; $i <= 5; ++$i) {
            $this->check($this->wrongCode($secret));
            self::assertResponseStatusCodeSame(401, "tentativo $i");
        }

        $this->check($this->code($secret));
        self::assertResponseStatusCodeSame(429);
        self::assertSame('auth.too_many_attempts', $this->jsonBody()['title']);
    }

    /** Un codice dell'app accettato non vale una seconda volta finché resta nella sua finestra (cache di Scheb). */
    public function testTheSameAppCodeCannotBeUsedTwice(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set('cache.app', new class extends ArrayAdapter {
            public function reset(): void
            {
            }
        });
        [$user, , $session] = $this->createAuthenticatedUser();
        [$secret] = $this->enable($session);
        $code = $this->code($secret);

        $this->client->getCookieJar()->clear();
        $this->login($user);
        $this->check($code);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('POST', '/api/auth/logout');
        $this->client->getCookieJar()->clear();
        $this->login($user);
        $this->check($code);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('auth.invalid_two_factor_code', $this->jsonBody()['title']);
    }

    public function testCancellingTheVerificationWithLogout(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [$secret] = $this->enable($session);
        $this->client->getCookieJar()->clear();
        $this->login($user);

        $this->jsonRequest('POST', '/api/auth/logout');
        self::assertResponseStatusCodeSame(204);

        $this->check($this->code($secret));
        self::assertResponseStatusCodeSame(401);
        self::assertSame('http.401', $this->jsonBody()['title']);
    }

    public function testTheCheckPathWithoutAVerificationInProgressIsUnauthorized(): void
    {
        $this->check('123456');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('http.401', $this->jsonBody()['title']);
    }

    /** Il cookie remember-me nasce solo a verifica completata: con quello si rientra senza codice. */
    public function testRememberMeSignsBackInWithoutTheCode(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [$secret] = $this->enable($session);
        $this->client->getCookieJar()->clear();
        $this->login($user);
        $this->check($this->code($secret));
        $remember = (string) $this->responseCookie(self::REMEMBER_ME_COOKIE);

        $jar = $this->client->getCookieJar();
        $jar->clear();
        $jar->set(new BrowserCookie(self::REMEMBER_ME_COOKIE, $remember, null, '/api', 'localhost'));
        $this->jsonRequest('GET', '/api/auth/me');

        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), $this->jsonBody()['id']);
    }

    /** APP_SECRET cambiato: il segreto non si decifra, la verifica resta richiesta (nessun codice dell'app vale, niente 500). */
    public function testAnUndecryptableSecretKeepsTheVerificationRequired(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [$secret, $codes] = $this->enable($session);
        $this->db()->executeStatement('UPDATE users SET totp_secret = ? WHERE id = ?', [base64_encode(random_bytes(64)), $user->getId()]);
        $this->client->getCookieJar()->clear();

        $this->login($user);
        self::assertSame(['twoFactorRequired' => true], $this->jsonBody());
        $this->check($this->code($secret));
        self::assertResponseStatusCodeSame(401);

        $this->check($codes[0]);
        self::assertResponseIsSuccessful('Un codice di recupero sblocca comunque');
    }

    // -------------------- disattivazione e codici nuovi --------------------

    public function testDisableNeedsThePasswordAndACode(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [$secret] = $this->enable($session);

        $this->jsonRequest('POST', '/api/auth/2fa/disable', ['currentPassword' => 'sbagliata', 'code' => $this->code($secret)], session: $session);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('auth.invalid_current_password', $this->jsonBody()['title']);

        $this->jsonRequest('POST', '/api/auth/2fa/disable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $this->wrongCode($secret)], session: $session);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'code', 'message' => 'two_factor.invalid_code']], $this->jsonBody()['errors']);

        $this->jsonRequest('POST', '/api/auth/2fa/disable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $this->code($secret)], session: $session);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['totp_secret' => null, 'totp_enabled_at' => null, 'backup_codes' => '[]'], array_intersect_key($this->row($user), array_flip(['totp_secret', 'totp_enabled_at', 'backup_codes'])));

        $this->jsonRequest('POST', '/api/auth/2fa/disable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $this->code($secret)], session: $session);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('two_factor.not_enabled', $this->jsonBody()['title']);

        $this->client->getCookieJar()->clear();
        $this->login($user);
        self::assertSame(['twoFactorRequired' => false], $this->jsonBody(), 'Di nuovo login in un passaggio');
    }

    public function testDisableWithARecoveryCode(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [, $codes] = $this->enable($session);

        $this->jsonRequest('POST', '/api/auth/2fa/disable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $codes[0]], session: $session);

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->row($user)['totp_enabled_at']);
    }

    public function testNewRecoveryCodesReplaceTheOldOnes(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [$secret, $old] = $this->enable($session);

        $this->jsonRequest('POST', '/api/auth/2fa/backup-codes', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $this->code($secret)], session: $session);
        self::assertResponseIsSuccessful();
        $new = $this->jsonBody()['backupCodes'];
        self::assertCount(10, $new);
        self::assertSame([], array_intersect($old, $new));

        $this->client->getCookieJar()->clear();
        $this->login($user);
        $this->check($old[0]);
        self::assertResponseStatusCodeSame(401, 'I vecchi codici non valgono più');
        $this->check($new[0]);
        self::assertResponseIsSuccessful();
    }

    public function testManagementAttemptsShareTheLoginLimit(): void
    {
        $this->keepRateLimiterCountersForTheWholeTest();
        [, , $session] = $this->createAuthenticatedUser();
        [$secret] = $this->enable($session);

        for ($i = 1; $i <= 5; ++$i) {
            $this->jsonRequest('POST', '/api/auth/2fa/backup-codes', ['currentPassword' => 'sbagliata', 'code' => $this->code($secret)], session: $session);
            self::assertResponseStatusCodeSame(400, "tentativo $i");
        }

        $this->jsonRequest('POST', '/api/auth/2fa/disable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $this->code($secret)], session: $session);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('auth.too_many_attempts', $this->jsonBody()['title']);
    }

    // -------------------- helpers --------------------

    private function beginSetup(string $session): string
    {
        $this->jsonRequest('POST', '/api/auth/2fa/setup', session: $session);
        self::assertResponseIsSuccessful();

        return (string) $this->jsonBody()['secret'];
    }

    /** @return array{0: string, 1: list<string>} segreto e codici di recupero */
    private function enable(string $session): array
    {
        $secret = $this->beginSetup($session);
        $this->jsonRequest('POST', '/api/auth/2fa/enable', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'code' => $this->code($secret)], session: $session);
        self::assertResponseIsSuccessful();

        return [$secret, $this->jsonBody()['backupCodes']];
    }

    private function code(string $secret): string
    {
        return $this->totp($secret)->now();
    }

    private function totp(string $secret): TOTP
    {
        if ($secret === '') {
            self::fail('Segreto TOTP vuoto');
        }

        return TOTP::createFromSecret($secret);
    }

    /** Un codice di 6 cifre diverso da quelli validi ora (finestra corrente e tolleranza). */
    private function wrongCode(string $secret): string
    {
        $totp = $this->totp($secret);
        $valid = [$totp->at(max(0, time() - 30)), $totp->now(), $totp->at(time() + 30)];
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        } while (\in_array($code, $valid, true));

        return $code;
    }

    private function login(User $user): void
    {
        $this->jsonRequest('POST', '/api/auth/login', ['email' => $user->getEmail(), 'password' => UserFactory::DEFAULT_PASSWORD]);
    }

    private function check(string $code): void
    {
        $this->jsonRequest('POST', '/api/auth/2fa/check', ['authCode' => $code]);
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        $row = $this->db()->fetchAssociative('SELECT totp_secret, totp_enabled_at, backup_codes FROM users WHERE id = ?', [$user->getId()]);
        self::assertIsArray($row);

        return $row;
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
