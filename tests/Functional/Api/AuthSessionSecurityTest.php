<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\OrganizationInvitation;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Service\Gdpr\GdprDeleteService;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Autenticazione con la sessione di Symfony: login JSON (json_login), throttling, logout, remember-me, rigenerazione
 * dell'id di sessione, revoca delle altre sessioni ai cambi di credenziali e protezione CSRF same-origin.
 */
final class AuthSessionSecurityTest extends ApiTestCase
{
    // -------------------- login --------------------

    public function testLoginOpensASessionAndSetsTheRememberMeCookie(): void
    {
        $user = UserFactory::createOne(['email' => 'login@test.it']);
        $second = OrganizationFactory::createOne(['name' => 'Zeta']);
        $first = OrganizationFactory::createOne(['name' => 'Alfa']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $second, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $first, 'role' => OrgRole::MEMBER]);

        $this->login('login@test.it', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseIsSuccessful();
        self::assertSame(['twoFactorRequired' => false], $this->jsonBody());
        $session = $this->cookie(self::SESSION_COOKIE);
        self::assertNotNull($session);
        self::assertTrue($session->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $session->getSameSite());
        self::assertSame('/api', $session->getPath());
        self::assertSame(0, $session->getExpiresTime(), 'Cookie di sessione del browser: la durata la dà il remember-me');
        $remember = $this->cookie(self::REMEMBER_ME_COOKIE);
        self::assertNotNull($remember);
        self::assertTrue($remember->isHttpOnly());
        self::assertSame('/api', $remember->getPath());
        self::assertEqualsWithDelta(time() + 604800, $remember->getExpiresTime(), 60, '7 giorni');

        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        $me = $this->jsonBody();
        self::assertSame('login@test.it', $me['email']);
        self::assertSame($first->getId(), $me['activeOrganizationId'], 'Prima membership accettata in ordine di nome');
        self::assertFalse($me['twoFactorEnabled']);
        self::assertSame(0, $me['backupCodesRemaining']);
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameAnswer(): void
    {
        UserFactory::createOne(['email' => 'known@test.it']);

        $this->login('known@test.it', 'wrong-password');
        self::assertResponseStatusCodeSame(401);
        $wrongPassword = $this->jsonBody();

        $this->login('ghost@test.it', 'wrong-password');
        self::assertResponseStatusCodeSame(401);

        self::assertSame(['type' => 'about:blank', 'title' => 'auth.invalid_credentials', 'status' => 401], $wrongPassword);
        self::assertSame($wrongPassword, $this->jsonBody());
        self::assertNull($this->responseCookie(self::REMEMBER_ME_COOKIE));
    }

    /** Oltre il limite dell'hasher (4096 byte): nessun 500 e nessuna differenza tra email registrata e sconosciuta. */
    public function testOverlongPasswordAnswersTheSameForKnownAndUnknownEmail(): void
    {
        UserFactory::createOne(['email' => 'known@test.it']);
        $overlong = str_repeat('a', 5000);

        $this->login('known@test.it', $overlong);
        self::assertResponseStatusCodeSame(401);
        $known = $this->jsonBody();

        $this->login('ghost@test.it', $overlong);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($known, $this->jsonBody());
        self::assertSame('auth.invalid_credentials', $known['title']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedLoginBodies(): iterable
    {
        yield 'JSON malformato' => ['{"email": '];
        yield 'senza email' => ['{"password": "test1234"}'];
        yield 'senza password' => ['{"email": "a@test.it"}'];
        yield 'email non stringa' => ['{"email": ["a@test.it"], "password": "x"}'];
        yield 'corpo lista' => ['[1, 2]'];
    }

    #[DataProvider('malformedLoginBodies')]
    public function testMalformedLoginBodyIsABadRequest(string $raw): void
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: $raw);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['type' => 'about:blank', 'title' => 'http.400', 'status' => 400], $this->jsonBody());
    }

    public function testLoginThatIsNotJsonIsABadRequest(): void
    {
        $this->client->request('POST', '/api/auth/login', ['email' => 'a@test.it', 'password' => 'x']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('http.400', $this->jsonBody()['title']);
    }

    // -------------------- throttling --------------------

    public function testLoginIsBlockedAfterFiveFailuresForTheSameEmailAndTheCorrectPasswordDoesNotBypassIt(): void
    {
        $this->keepRateLimiterCountersForTheWholeTest();
        UserFactory::createOne(['email' => 'victim@test.it']);
        UserFactory::createOne(['email' => 'other@test.it']);

        for ($i = 1; $i <= 5; ++$i) {
            $this->login('victim@test.it', 'wrong-password');
            self::assertResponseStatusCodeSame(401, "tentativo $i");
        }

        // 6° tentativo: limite raggiunto, anche con la password giusta (altrimenti il limite non frenerebbe il brute force)
        $this->login('victim@test.it', UserFactory::DEFAULT_PASSWORD);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('auth.too_many_attempts', $this->jsonBody()['title']);
        self::assertNull($this->responseCookie(self::REMEMBER_ME_COOKIE));

        // Il contatore è per (email, IP): un altro account non è toccato
        $this->login('other@test.it', UserFactory::DEFAULT_PASSWORD);
        self::assertResponseIsSuccessful();
    }

    public function testThrottlingIsCaseInsensitiveOnTheEmail(): void
    {
        $this->keepRateLimiterCountersForTheWholeTest();
        UserFactory::createOne(['email' => 'case@test.it']);

        for ($i = 0; $i < 5; ++$i) {
            $this->login(0 === $i % 2 ? 'case@test.it' : 'CASE@test.it', 'wrong-password');
        }

        $this->login('Case@Test.it', UserFactory::DEFAULT_PASSWORD);
        self::assertResponseStatusCodeSame(429, 'Cambiare le maiuscole non deve azzerare il contatore');
    }

    // -------------------- logout --------------------

    public function testLogoutEndsTheSessionAndDeletesTheRememberMeSeries(): void
    {
        UserFactory::createOne(['email' => 'logout@test.it']);
        $this->login('logout@test.it', UserFactory::DEFAULT_PASSWORD);
        self::assertSame(1, $this->rememberMeRows());

        $this->jsonRequest('POST', '/api/auth/logout');

        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->cookie(self::SESSION_COOKIE)?->isCleared(), 'Cookie di sessione cancellato');
        self::assertTrue($this->cookie(self::REMEMBER_ME_COOKIE)?->isCleared(), 'Cookie remember-me cancellato');
        self::assertSame(0, $this->rememberMeRows(), 'La serie remember-me del dispositivo non vale più');

        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('http.401', $this->jsonBody()['title']);
    }

    public function testLogoutWithoutASessionAnswers204(): void
    {
        $this->client->request('POST', '/api/auth/logout', server: ['CONTENT_TYPE' => 'application/json'], content: '"qualsiasi corpo"');

        self::assertResponseStatusCodeSame(204);
    }

    // -------------------- id di sessione e remember-me --------------------

    public function testASecondLoginOnTheSameClientGetsANewSessionWithoutTheFirstUsersState(): void
    {
        [$alice, , $aliceSession] = $this->createAuthenticatedUser();
        [$bob, $bobOrg] = $this->createAuthenticatedUser();

        $this->useSession($aliceSession);
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertSame($alice->getId(), $this->jsonBody()['id']);

        $this->login($bob->getEmail(), UserFactory::DEFAULT_PASSWORD);
        self::assertResponseIsSuccessful();
        $bobSession = $this->responseCookie(self::SESSION_COOKIE);
        self::assertNotNull($bobSession);
        self::assertNotSame($aliceSession, $bobSession, 'Il login rigenera l\'id di sessione');

        $this->jsonRequest('GET', '/api/auth/me');
        self::assertSame($bob->getId(), $this->jsonBody()['id']);
        self::assertSame($bobOrg->getId(), $this->jsonBody()['activeOrganizationId'], 'L\'org attiva di chi c\'era prima non passa');

        // Senza il cookie remember-me di Bob, che rientrerebbe da solo
        $this->onlyCookies([self::SESSION_COOKIE => $aliceSession]);
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401, 'Il vecchio id non autentica più');
    }

    public function testTheRememberMeCookieAloneOpensANewSession(): void
    {
        [$user] = $this->createAuthenticatedUser();
        $this->login($user->getEmail(), UserFactory::DEFAULT_PASSWORD);
        $remember = (string) $this->responseCookie(self::REMEMBER_ME_COOKIE);

        // Browser riavviato: il cookie di sessione è sparito, quello remember-me no.
        $this->onlyCookies([self::REMEMBER_ME_COOKIE => $remember]);
        $this->jsonRequest('GET', '/api/auth/me');

        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), $this->jsonBody()['id']);
        self::assertNotNull($this->responseCookie(self::SESSION_COOKIE), 'Nuova sessione');
    }

    public function testANewSessionFromRememberMeStartsFromTheLastChosenOrganization(): void
    {
        [$user, $first] = $this->createAuthenticatedUser();
        $first->setName('Alfa');
        $this->em()->flush();
        $second = OrganizationFactory::createOne(['name' => 'Zeta']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $second, 'role' => OrgRole::MEMBER]);
        $this->login($user->getEmail(), UserFactory::DEFAULT_PASSWORD);
        $remember = (string) $this->responseCookie(self::REMEMBER_ME_COOKIE);
        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => $second->getId()]);
        self::assertResponseIsSuccessful();

        $this->onlyCookies([self::REMEMBER_ME_COOKIE => $remember]);
        $this->jsonRequest('GET', '/api/auth/me');

        self::assertResponseIsSuccessful();
        self::assertSame($second->getId(), $this->jsonBody()['activeOrganizationId']);
    }

    // -------------------- revoca --------------------

    public function testPasswordChangeEndsTheOtherSessionsAndTheirRememberMeCookies(): void
    {
        $user = UserFactory::createOne(['email' => 'two-devices@test.it']);
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);

        // Secondo dispositivo: login vero, con il suo cookie remember-me
        $this->login('two-devices@test.it', UserFactory::DEFAULT_PASSWORD);
        $otherSession = (string) $this->responseCookie(self::SESSION_COOKIE);
        $otherRemember = (string) $this->responseCookie(self::REMEMBER_ME_COOKIE);

        // Primo dispositivo: cambia la password
        $this->onlyCookies([self::SESSION_COOKIE => $this->sessionFor($user, $org)]);
        $this->jsonRequest('PUT', '/api/auth/password', ['currentPassword' => UserFactory::DEFAULT_PASSWORD, 'newPassword' => 'Nuova-Password-1']);
        self::assertResponseStatusCodeSame(204);
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseIsSuccessful('Il dispositivo che cambia la password resta dentro');

        $this->onlyCookies([self::SESSION_COOKIE => $otherSession]);
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401, 'L\'altra sessione cade alla richiesta successiva');

        $this->onlyCookies([self::REMEMBER_ME_COOKIE => $otherRemember]);
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401, 'Il remember-me dell\'altro dispositivo era firmato con la vecchia password');
    }

    public function testPasswordResetEndsEverySession(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        $raw = bin2hex(random_bytes(32));
        $this->em()->persist(new PasswordResetToken($user, hash('sha256', $raw), new \DateTimeImmutable('+1 hour')));
        $this->em()->flush();

        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $raw, 'password' => 'Reset-Pass-123']);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/auth/me', session: $session);
        self::assertResponseStatusCodeSame(401);
    }

    public function testConfirmingAnEmailChangeEndsEverySession(): void
    {
        [, , $session] = $this->createAuthenticatedUser();
        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'nuova@test.it', 'firstName' => 'A', 'lastName' => 'B', 'locale' => 'it',
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
        ], session: $session);
        self::assertResponseIsSuccessful();
        $link = $this->lastVerificationToken();
        $this->jsonRequest('GET', '/api/auth/me', session: $session);
        self::assertResponseIsSuccessful('La sola richiesta di cambio non chiude la sessione');

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $link]);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('GET', '/api/auth/me', session: $session);
        self::assertResponseStatusCodeSame(401);
    }

    /** Anche il browser che apre il link, se ha una sessione dell'utente, esce: si rientra con il nuovo indirizzo. */
    public function testConfirmingAnEmailChangeAlsoEndsTheSessionThatConfirmsIt(): void
    {
        $this->login($this->createAuthenticatedUser()[0]->getEmail(), UserFactory::DEFAULT_PASSWORD);
        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'confermata@test.it', 'firstName' => 'A', 'lastName' => 'B', 'locale' => 'it',
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
        ]);
        $link = $this->lastVerificationToken();

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $link]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['emailChanged']);
        self::assertTrue($this->cookie(self::REMEMBER_ME_COOKIE)?->isCleared());
        self::assertSame(0, $this->rememberMeRows());

        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testGdprDeletionEndsEverySession(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        $this->jsonRequest('GET', '/api/auth/me', session: $session);
        self::assertResponseIsSuccessful();

        $managed = $this->em()->find(User::class, $user->getId());
        self::assertNotNull($managed);
        static::getContainer()->get(GdprDeleteService::class)->anonymize($managed);

        $this->jsonRequest('GET', '/api/auth/me', session: $session);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * I ruoli di Symfony derivano dalle membership, ma non fanno parte dell'uguaglianza della sessione: una promozione,
     * una nuova membership o un invito accettato non devono buttare fuori l'utente.
     */
    public function testRoleChangesAndNewMembershipsDoNotEndTheSession(): void
    {
        [$owner, $org, $ownerSession] = $this->createAuthenticatedUser();
        $member = UserFactory::createOne(['email' => 'member@test.it']);
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $memberSession = $this->sessionFor($member, $org);
        $memberId = $this->em()->getConnection()->fetchOne('SELECT id FROM organization_members WHERE user_id = ?', [$member->getId()]);

        $this->jsonRequest('PATCH', '/api/organizations/'.$org->getId().'/members/'.$memberId, ['role' => 'admin'], session: $ownerSession);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', '/api/auth/me', session: $memberSession);
        self::assertResponseIsSuccessful('Promozione');

        $this->jsonRequest('PATCH', '/api/organizations/'.$org->getId().'/members/'.$memberId, ['role' => 'member'], session: $ownerSession);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', '/api/auth/me', session: $memberSession);
        self::assertResponseIsSuccessful('Retrocessione');

        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => OrganizationFactory::createOne(), 'role' => OrgRole::OWNER]);
        $this->jsonRequest('GET', '/api/auth/me', session: $memberSession);
        self::assertResponseIsSuccessful('Nuova membership');

        $invitingOrg = OrganizationFactory::createOne();
        $raw = bin2hex(random_bytes(32));
        $this->em()->persist(new OrganizationInvitation($invitingOrg, 'member@test.it', OrgRole::ADMIN, hash('sha256', $raw), new \DateTimeImmutable('+7 days')));
        $this->em()->flush();
        $this->jsonRequest('POST', '/api/auth/invitation/accept', ['token' => $raw], session: $memberSession);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', '/api/auth/me', session: $memberSession);
        self::assertResponseIsSuccessful('Invito accettato');
        self::assertCount(3, $this->jsonBody()['memberships']);
        self::assertNotSame($owner->getId(), $this->jsonBody()['id']);
    }

    // -------------------- registrazione --------------------

    public function testRegistrationFromAnInvitationSignsTheUserIn(): void
    {
        UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $raw = bin2hex(random_bytes(32));
        $this->em()->persist(new OrganizationInvitation($org, 'invited@test.it', OrgRole::MEMBER, hash('sha256', $raw), new \DateTimeImmutable('+7 days')));
        $this->em()->flush();

        $this->jsonRequest('POST', '/api/auth/invitation/register', [
            'token' => $raw, 'firstName' => 'In', 'lastName' => 'Vitato', 'password' => 'password123',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['user'], array_keys($this->jsonBody()));
        self::assertNotNull($this->responseCookie(self::REMEMBER_ME_COOKIE));
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame('invited@test.it', $this->jsonBody()['email']);
        self::assertSame($org->getId(), $this->jsonBody()['activeOrganizationId']);
    }

    public function testTheRefreshEndpointIsGone(): void
    {
        $this->jsonRequest('POST', '/api/auth/refresh', ['token' => 'x']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('http.404', $this->jsonBody()['title']);
    }

    // -------------------- CSRF --------------------

    /** @return iterable<string, array{string, string, array<string, mixed>|null, bool}> metodo, path, corpo, serve una sessione */
    public static function unsafeRequests(): iterable
    {
        yield 'login' => ['POST', '/api/auth/login', ['email' => 'csrf@test.it', 'password' => 'test1234'], false];
        yield 'codice della verifica in due passaggi' => ['POST', '/api/auth/2fa/check', ['authCode' => '123456'], false];
        yield 'logout' => ['POST', '/api/auth/logout', null, true];
        yield 'rotta pubblica' => ['POST', '/api/auth/forgot-password', ['email' => 'csrf@test.it'], false];
        yield 'rotta autenticata' => ['POST', '/api/vehicles', ['name' => 'Panda', 'brand' => 'Fiat', 'model' => 'Panda', 'year' => 2020, 'type' => 'car', 'fuelType' => 'diesel'], true];
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function foreignOrigins(): iterable
    {
        yield 'nessuna informazione di origine' => [[]];
        yield 'Sec-Fetch-Site cross-site' => [['HTTP_SEC_FETCH_SITE' => 'cross-site']];
        yield 'Sec-Fetch-Site same-site (un altro sottodominio)' => [['HTTP_SEC_FETCH_SITE' => 'same-site']];
        yield 'Origin di un altro host' => [['HTTP_ORIGIN' => 'https://evil.example']];
        yield 'Referer di un altro host' => [['HTTP_REFERER' => 'https://evil.example/pagina']];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $origin
     */
    #[DataProvider('unsafeRequestsFromForeignOrigins')]
    public function testUnsafeRequestsFromAnotherOriginAreRefused(string $method, string $path, ?array $body, bool $withSession, array $origin): void
    {
        UserFactory::createOne(['email' => 'csrf@test.it']);
        $session = $withSession ? $this->createAuthenticatedUser()[2] : null;
        $this->client->setServerParameters([]); // niente Sec-Fetch-Site di default

        $this->jsonRequest($method, $path, $body, session: $session, server: $origin);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['type' => 'about:blank', 'title' => 'auth.csrf_invalid', 'status' => 403], $this->jsonBody());
    }

    /** @return iterable<string, array{string, string, array<string, mixed>|null, bool, array<string, string>}> */
    public static function unsafeRequestsFromForeignOrigins(): iterable
    {
        foreach (self::unsafeRequests() as $request => $args) {
            foreach (self::foreignOrigins() as $origin => [$headers]) {
                yield "$request, $origin" => [...$args, $headers];
            }
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function sameOrigins(): iterable
    {
        yield 'Sec-Fetch-Site same-origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin']];
        yield 'Origin uguale all\'host' => [['HTTP_ORIGIN' => 'http://localhost']];
        yield 'Referer dello stesso host' => [['HTTP_REFERER' => 'http://localhost/login']];
    }

    /** @param array<string, string> $origin */
    #[DataProvider('sameOrigins')]
    public function testSameOriginRequestsPass(array $origin): void
    {
        UserFactory::createOne(['email' => 'csrf@test.it']);
        [, , $session] = $this->createAuthenticatedUser();
        $this->client->setServerParameters([]);

        $this->jsonRequest('POST', '/api/auth/login', ['email' => 'csrf@test.it', 'password' => UserFactory::DEFAULT_PASSWORD], server: $origin);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('POST', '/api/vehicles', ['name' => 'Panda', 'brand' => 'Fiat', 'model' => 'Panda', 'year' => 2020, 'type' => 'car', 'fuelType' => 'diesel'], session: $session, server: $origin);
        self::assertResponseStatusCodeSame(201);
    }

    public function testSafeRequestsAreNotChecked(): void
    {
        [, , $session] = $this->createAuthenticatedUser();
        $this->client->setServerParameters([]);

        $this->jsonRequest('GET', '/api/auth/me', session: $session, server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseIsSuccessful();
    }

    // -------------------- helpers --------------------

    private function login(string $email, string $password): void
    {
        $this->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function cookie(string $name): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * Il client riparte con soli questi cookie (es. browser riavviato: resta il remember-me, la sessione no).
     *
     * @param array<string, string> $cookies
     */
    private function onlyCookies(array $cookies): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($cookies as $name => $value) {
            $jar->set(new BrowserCookie($name, $value, null, '/api', 'localhost'));
        }
    }

    private function rememberMeRows(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM rememberme_token');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
