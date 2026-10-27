<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\OrgRole;
use App\Entity\EmailVerificationToken;
use App\Entity\OrganizationInvitation;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

final class AuthControllerTest extends ApiTestCase
{
    use MailerAssertionsTrait;

    // -------------------- REGISTER --------------------

    public function testRegisterCreatesUserOrganizationAndOwnershipMembership(): void
    {
        $this->jsonRequest('POST', '/api/auth/register', [
            'email' => 'newuser@test.it',
            'password' => 'test1234',
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame('newuser@test.it', $body['user']['email']);
        self::assertSame(['user'], array_keys($body), 'Nessun token nel corpo: la sessione è nel cookie');
        self::assertNotNull($this->responseCookie(self::SESSION_COOKIE));
        self::assertNotNull($this->responseCookie(self::REMEMBER_ME_COOKIE));

        // La registrazione apre la sessione: /me dallo stesso client risponde.
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame('newuser@test.it', $this->jsonBody()['email']);

        $userRepo = static::getContainer()->get(UserRepository::class);
        $user = $userRepo->findOneByEmail('newuser@test.it');
        self::assertInstanceOf(User::class, $user);

        // Personal organization automaticamente creata + membership owner
        $memberRepo = static::getContainer()->get(OrganizationMemberRepository::class);
        $memberships = $memberRepo->findAllForUser($user);
        self::assertCount(1, $memberships);
        self::assertSame(OrgRole::OWNER, $memberships[0]->getRole());
    }

    public function testRegistrationIsOpenOnlyWhileTheInstanceIsEmpty(): void
    {
        // Istanza vuota: pubblico (senza token) e aperto.
        $this->jsonRequest('GET', '/api/auth/registration');
        self::assertResponseIsSuccessful();
        self::assertSame(['open' => true], $this->jsonBody());

        // Il primo utente può registrarsi (diventa admin di istanza)...
        $this->jsonRequest('POST', '/api/auth/register', [
            'email' => 'first@test.it',
            'password' => 'test1234',
            'firstName' => 'Primo',
            'lastName' => 'Admin',
        ]);
        self::assertResponseStatusCodeSame(201);

        // ...e da quel momento la registrazione libera è chiusa per chiunque.
        $this->jsonRequest('GET', '/api/auth/registration');
        self::assertSame(['open' => false], $this->jsonBody());

        $this->jsonRequest('POST', '/api/auth/register', [
            'email' => 'second@test.it',
            'password' => 'test1234',
            'firstName' => 'Secondo',
            'lastName' => 'Estraneo',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('auth.registration_closed', $this->jsonBody()['title']);
        self::assertNull(
            static::getContainer()->get(UserRepository::class)->findOneByEmail('second@test.it'),
            'Nessun utente deve essere creato a registrazione chiusa',
        );
    }

    public function testClosedRegistrationDoesNotRevealWhichEmailsExist(): void
    {
        // Prima il 409 "email già registrata" permetteva di enumerare gli account:
        // a registrazione chiusa la risposta è identica (403) per email nuove ed esistenti.
        UserFactory::createOne(['email' => 'taken@test.it']);

        foreach (['taken@test.it', 'never-seen@test.it'] as $email) {
            $this->jsonRequest('POST', '/api/auth/register', [
                'email' => $email,
                'password' => 'test1234',
                'firstName' => 'X',
                'lastName' => 'Y',
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('auth.registration_closed', $this->jsonBody()['title']);
        }
    }

    public function testRegisterValidatesInput(): void
    {
        $this->jsonRequest('POST', '/api/auth/register', [
            'email' => 'not-an-email',
            'password' => 'short',
            'firstName' => '',
            'lastName' => '',
        ]);

        // MapRequestPayload risponde 422 con dettagli di validazione
        self::assertResponseStatusCodeSame(422);
    }

    // Login, logout, throttling, CSRF e remember-me: SessionAuthenticationTest.

    public function testAvatarDownloadCarriesNosniff(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/auth/avatar',
            files: ['file' => $this->makeUploadedFile('me.png', $this->pngBytes(), 'image/png')],
        );
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/auth/avatar');

        self::assertResponseIsSuccessful();
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        // Stesso URL per ogni utente: niente cache, né condivisa né del browser.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function testChangePasswordRejectsAnOverlongCurrentPassword(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/auth/password', [
            'currentPassword' => str_repeat('a', 5000),
            'newPassword' => 'nuovapw12345',
        ], session: $token);

        self::assertResponseStatusCodeSame(422);
    }

    // -------------------- ME --------------------

    public function testMeReturnsUserWithMemberships(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/auth/me', session: $session);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame($user->getId(), $body['id']);
        self::assertSame($org->getId(), $body['activeOrganizationId']);
        self::assertFalse($body['twoFactorEnabled']);
        self::assertSame(0, $body['backupCodesRemaining']);
        self::assertCount(1, $body['memberships']);
        self::assertSame($org->getId(), $body['memberships'][0]['organization']['id']);
        self::assertSame('owner', $body['memberships'][0]['role']);
    }

    public function testMeWithoutSessionReturns401(): void
    {
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('http.401', $this->jsonBody()['title']);
    }

    // -------------------- UPDATE PROFILE --------------------

    /**
     * Il nuovo indirizzo diventa l'email dell'account solo aprendo il link mandato lì: fino ad allora login e
     * identificatore restano quelli di prima. Audit run-2, finding `auth/profile-email-change-claims-unverified-address`.
     */
    public function testUpdateProfileChangesNameAtOnceAndEmailOnlyAfterConfirmation(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $oldEmail = $user->getEmail();

        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'Nuovo@test.it',
            'firstName' => 'Anna',
            'lastName' => 'Bianchi',
            'locale' => 'en',
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
        ], session: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame(['user'], array_keys($body));
        self::assertSame($oldEmail, $body['user']['email']);
        self::assertSame('nuovo@test.it', $body['user']['pendingEmail']);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'nuovo@test.it');
        $link = $this->lastVerificationToken();

        $this->jsonRequest('POST', '/api/auth/login', ['email' => 'nuovo@test.it', 'password' => UserFactory::DEFAULT_PASSWORD]);
        self::assertResponseStatusCodeSame(401, 'Un indirizzo non confermato non è un login');

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $link]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['emailChanged']);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $fresh = static::getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertSame('nuovo@test.it', $fresh->getEmail());
        self::assertNull($fresh->getPendingEmail());
        self::assertTrue($fresh->isEmailVerified());
        self::assertSame('Anna', $fresh->getFirstName());
        self::assertSame('Bianchi', $fresh->getLastName());
        self::assertSame('en', $fresh->getLocale());

        $this->jsonRequest('POST', '/api/auth/login', ['email' => 'nuovo@test.it', 'password' => UserFactory::DEFAULT_PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Un indirizzo già registrato riceve la stessa risposta di uno libero: il profilo non dice se un'email ha un
     * account sull'istanza. Nessuna mail parte verso l'account esistente. Audit run-2, finding
     * `src/Controller/Api/AuthController.php:updateProfile-email-taken-cross-tenant-account-oracle`.
     */
    public function testUpdateProfileWithEmailOfAnotherUserLooksLikeAFreeOne(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $oldEmail = $user->getEmail();
        UserFactory::createOne(['email' => 'altro@test.it']);
        $profile = ['firstName' => 'A', 'lastName' => 'B', 'locale' => 'it', 'currentPassword' => UserFactory::DEFAULT_PASSWORD];

        $this->jsonRequest('PUT', '/api/auth/profile', ['email' => 'altro@test.it'] + $profile, session: $token);
        self::assertResponseStatusCodeSame(200);
        $taken = $this->jsonBody();
        self::assertEmailCount(0);

        $this->jsonRequest('PUT', '/api/auth/profile', ['email' => 'libero@test.it'] + $profile, session: $token);
        self::assertResponseStatusCodeSame(200);
        $free = $this->jsonBody();

        self::assertSame(array_keys($free), array_keys($taken));
        self::assertSame(array_keys($free['user']), array_keys($taken['user']));
        self::assertSame($oldEmail, $taken['user']['email']);
        self::assertSame('altro@test.it', $taken['user']['pendingEmail']);
        self::assertSame('libero@test.it', $free['user']['pendingEmail']);
    }

    /** L'indirizzo è stato registrato da qualcun altro dopo la richiesta: la conferma fallisce e l'account resta com'era. */
    public function testConfirmingAnAddressTakenInTheMeantimeKeepsTheOldEmail(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $oldEmail = $user->getEmail();
        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'conteso@test.it', 'firstName' => 'A', 'lastName' => 'B', 'locale' => 'it',
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
        ], session: $token);
        self::assertResponseIsSuccessful();
        UserFactory::createOne(['email' => 'conteso@test.it']);

        $this->openLastVerificationLink();
        self::assertResponseStatusCodeSame(409);
        self::assertSame('auth.email_taken', $this->jsonBody()['title']);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $fresh = static::getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertSame($oldEmail, $fresh->getEmail());
        self::assertNull($fresh->getPendingEmail());
    }

    /**
     * Email di verifica e di conferma: al massimo 3 ogni 15 minuti per utente, tra cambio email e reinvio. Audit run-2,
     * finding `auth/verification-mail-unthrottled-per-user-mail-budget-bypass`.
     */
    public function testVerificationEmailsAreLimitedPerUser(): void
    {
        $this->keepRateLimiterCountersForTheWholeTest();
        [$user, , $token] = $this->createAuthenticatedUser();
        $email = $user->getEmail();
        $profile = ['firstName' => 'A', 'lastName' => 'B', 'locale' => 'it', 'currentPassword' => UserFactory::DEFAULT_PASSWORD];

        $this->jsonRequest('PUT', '/api/auth/profile', ['email' => 'uno@test.it'] + $profile, session: $token);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('PUT', '/api/auth/profile', ['email' => 'due@test.it'] + $profile, session: $token);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', '/api/auth/resend-verification', session: $token);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('POST', '/api/auth/resend-verification', session: $token);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('auth.too_many_attempts', $this->jsonBody()['title']);
        $this->jsonRequest('PUT', '/api/auth/profile', ['email' => 'tre@test.it'] + $profile, session: $token);
        self::assertResponseStatusCodeSame(429);

        // Cambiare solo il nome non consuma il budget delle email.
        $this->jsonRequest('PUT', '/api/auth/profile', ['email' => $email, 'firstName' => 'Nuovo'] + $profile, session: $token);
        self::assertResponseIsSuccessful();
    }

    public function testUpdateProfileEmailChangeRequiresCurrentPassword(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'takeover@test.it',
            'firstName' => 'A',
            'lastName' => 'B',
            'locale' => 'it',
        ], session: $token);
        self::assertResponseStatusCodeSame(400);

        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'takeover@test.it',
            'firstName' => 'A',
            'lastName' => 'B',
            'locale' => 'it',
            'currentPassword' => 'sbagliata',
        ], session: $token);
        self::assertResponseStatusCodeSame(400);

        $fresh = static::getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotSame('takeover@test.it', $fresh->getEmail());
    }

    public function testUpdateProfileEmailChangeInvalidatesPendingResetTokens(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = bin2hex(random_bytes(32));
        $em->persist(new PasswordResetToken($user, hash('sha256', $raw), (new \DateTimeImmutable())->modify('+1 hour')));
        $em->flush();

        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'nuovo-indirizzo@test.it',
            'firstName' => 'A',
            'lastName' => 'B',
            'locale' => 'it',
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
        ], session: $token);
        self::assertResponseIsSuccessful();
        $this->openLastVerificationLink();
        self::assertResponseIsSuccessful();

        // Il link spedito al vecchio indirizzo non deve più permettere di cambiare la password.
        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $raw, 'password' => 'presa-di-possesso1']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('auth.invalid_reset_token', $this->jsonBody()['title']);

        $this->jsonRequest('POST', '/api/auth/login', [
            'email' => 'nuovo-indirizzo@test.it',
            'password' => UserFactory::DEFAULT_PASSWORD,
        ]);
        self::assertResponseIsSuccessful();
    }

    /** Il cambio in attesa non tocca l'indirizzo attuale, che resta verificato; quello nuovo nasce verificato. */
    public function testPendingEmailChangeKeepsTheCurrentAddressVerified(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $user->markEmailVerified();
        static::getContainer()->get('doctrine')->getManager()->flush();

        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'verificami@test.it',
            'firstName' => 'A',
            'lastName' => 'B',
            'locale' => 'it',
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
        ], session: $token);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['user']['emailVerified']);
        self::assertSame('verificami@test.it', $this->jsonBody()['user']['pendingEmail']);
    }

    public function testUpdateProfileKeepingOwnEmailSucceeds(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => $user->getEmail(),
            'firstName' => 'Stesso',
            'lastName' => 'Utente',
            'locale' => 'it',
        ], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('Stesso', $this->jsonBody()['user']['firstName']);
    }

    public function testUpdateProfileValidationFails(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'non-email',
            'firstName' => '',
            'lastName' => '',
            'locale' => 'it',
        ], session: $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateProfileRequiresAuth(): void
    {
        $this->jsonRequest('PUT', '/api/auth/profile', [
            'email' => 'x@test.it',
            'firstName' => 'X',
            'lastName' => 'Y',
            'locale' => 'it',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    // -------------------- CHANGE PASSWORD --------------------

    public function testChangePasswordWithCorrectCurrent(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $email = $user->getEmail();

        $this->jsonRequest('PUT', '/api/auth/password', [
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
            'newPassword' => 'nuovapw123',
        ], session: $token);

        self::assertResponseStatusCodeSame(204);

        // La nuova password funziona
        $this->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => 'nuovapw123']);
        self::assertResponseIsSuccessful();

        // La vecchia non più
        $this->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => UserFactory::DEFAULT_PASSWORD]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testChangePasswordKeepsTheSessionAndItsActiveOrganization(): void
    {
        [$user, $orgA] = $this->createAuthenticatedUser();
        $orgA->setName('Alpha');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $orgB = OrganizationFactory::createOne(['name' => 'Zeta']);
        OrganizationMemberFactory::createOne(['organization' => $orgB, 'user' => $user, 'role' => OrgRole::OWNER]);
        $inB = VehicleFactory::createOne(['organization' => $orgB, 'name' => 'Auto di Zeta']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $inB, 'user' => $user]);

        // Sessione reale: il login cade sulla prima org (Alpha), poi si passa a Zeta
        $this->jsonRequest('POST', '/api/auth/login', ['email' => $user->getEmail(), 'password' => UserFactory::DEFAULT_PASSWORD]);
        $sessionAfterLogin = $this->responseCookie(self::SESSION_COOKIE);
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertSame($orgA->getId(), $this->jsonBody()['activeOrganizationId']);
        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => $orgB->getId()]);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('PUT', '/api/auth/password', [
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
            'newPassword' => 'nuovapw123',
        ]);

        self::assertResponseStatusCodeSame(204);
        $newSession = $this->responseCookie(self::SESSION_COOKIE);
        self::assertNotNull($newSession, 'Id di sessione rigenerato');
        self::assertNotSame($sessionAfterLogin, $newSession);
        self::assertNotNull($this->responseCookie(self::REMEMBER_ME_COOKIE), 'Cookie remember-me nuovo, firmato con la password nuova');

        // L'app resta operativa nell'org scelta, con la stessa sessione
        $this->jsonRequest('GET', '/api/auth/me');
        self::assertSame($orgB->getId(), $this->jsonBody()['activeOrganizationId'], 'Il cambio password non deve riportare alla prima org');
        $this->jsonRequest('GET', '/api/vehicles');
        self::assertSame(['Auto di Zeta'], array_column($this->jsonBody(), 'name'));
    }

    public function testEmailChangeRequestKeepsTheSessionAndItsActiveOrganization(): void
    {
        [$user, $orgA] = $this->createAuthenticatedUser();
        $orgB = OrganizationFactory::createOne(['name' => 'Zeta']);
        OrganizationMemberFactory::createOne(['organization' => $orgB, 'user' => $user, 'role' => OrgRole::OWNER]);
        $session = $this->sessionFor($user, $orgB);

        $this->jsonRequest('PUT', '/api/auth/profile', [
            'firstName' => 'Mario',
            'lastName' => 'Rossi',
            'email' => 'nuova-mail@test.it',
            'locale' => 'it',
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
        ], session: $session);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('GET', '/api/auth/me', session: $session);
        self::assertResponseIsSuccessful();
        self::assertSame($orgB->getId(), $this->jsonBody()['activeOrganizationId']);
    }

    public function testChangePasswordWithWrongCurrentReturns400(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/auth/password', [
            'currentPassword' => 'sbagliata',
            'newPassword' => 'nuovapw123',
        ], session: $token);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('auth.invalid_current_password', $this->jsonBody()['title']);
    }

    public function testChangePasswordValidatesNewPassword(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/auth/password', [
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
            'newPassword' => 'short',
        ], session: $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testChangePasswordRequiresAuth(): void
    {
        $this->jsonRequest('PUT', '/api/auth/password', [
            'currentPassword' => 'whatever',
            'newPassword' => 'nuovapw123',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    // -------------------- AVATAR --------------------

    public function testUploadAvatarSetsHasAvatar(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/auth/avatar',
            files: ['file' => $this->makeUploadedFile('me.png', $this->pngBytes(), 'image/png')],
        );

        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['hasAvatar']);

        $this->jsonRequest('GET', '/api/auth/me', session: $token);
        self::assertTrue($this->jsonBody()['hasAvatar']);
    }

    public function testUploadAvatarRejectsWrongMime(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/auth/avatar',
            files: ['file' => $this->makeUploadedFile('doc.pdf', '%PDF-1.4 fake', 'application/pdf')],
        );

        self::assertResponseStatusCodeSame(415);
        self::assertSame('upload.mime_not_allowed', $this->jsonBody()['title']);
    }

    public function testUploadAvatarRejectsOversizedFile(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        // PNG valido gonfiato oltre 2MB con padding
        $bytes = $this->pngBytes().str_repeat("\0", 2 * 1024 * 1024 + 1);
        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/auth/avatar',
            files: ['file' => $this->makeUploadedFile('big.png', $bytes, 'image/png')],
        );

        self::assertResponseStatusCodeSame(413);
        self::assertSame('upload.file_too_large', $this->jsonBody()['title']);
    }

    public function testDownloadAvatarReturnsBinary(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/auth/avatar',
            files: ['file' => $this->makeUploadedFile('me.png', $this->pngBytes(), 'image/png')],
        );
        self::assertResponseIsSuccessful();

        $this->jsonRequest('GET', '/api/auth/avatar', session: $token);
        self::assertResponseIsSuccessful();
        // I file avatar sono salvati senza estensione: il Content-Type deve essere
        // dedotto dal contenuto (regressione: prima usciva text/html di default).
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        $content = $this->client->getResponse()->getContent();
        // BinaryFileResponse in test: il body può essere streamed — verifichiamo file o contenuto
        if (is_string($content) && $content !== '') {
            self::assertSame($this->pngBytes(), $content);
        }
    }

    public function testDownloadAvatarWithoutAvatarReturns404(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/auth/avatar', session: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('auth.no_avatar', $this->jsonBody()['title']);
    }

    public function testReuploadAvatarReplacesPreviousFile(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $this->useSession($token);

        $this->client->request('POST', '/api/auth/avatar', files: ['file' => $this->makeUploadedFile('a.png', $this->pngBytes(), 'image/png')]);
        self::assertResponseIsSuccessful();

        $userRepo = static::getContainer()->get(UserRepository::class);
        $storage = static::getContainer()->get(\App\Service\Storage\AttachmentStorageInterface::class);
        $firstPath = $userRepo->find($user->getId())?->getAvatarPath();
        self::assertNotNull($firstPath);
        self::assertTrue($storage->exists($firstPath));

        $this->client->request('POST', '/api/auth/avatar', files: ['file' => $this->makeUploadedFile('b.jpg', $this->jpegBytes(), 'image/jpeg')]);
        self::assertResponseIsSuccessful();

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $secondPath = $userRepo->find($user->getId())?->getAvatarPath();
        self::assertNotNull($secondPath);
        self::assertNotSame($firstPath, $secondPath);
        self::assertFalse($storage->exists($firstPath), 'Il vecchio file deve essere rimosso');
        self::assertTrue($storage->exists($secondPath));
    }

    public function testDeleteAvatarRemovesFileAndFlag(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $this->useSession($token);

        $this->client->request('POST', '/api/auth/avatar', files: ['file' => $this->makeUploadedFile('a.png', $this->pngBytes(), 'image/png')]);
        self::assertResponseIsSuccessful();

        $userRepo = static::getContainer()->get(UserRepository::class);
        $storage = static::getContainer()->get(\App\Service\Storage\AttachmentStorageInterface::class);
        $path = $userRepo->find($user->getId())?->getAvatarPath();
        self::assertNotNull($path);

        $this->jsonRequest('DELETE', '/api/auth/avatar', session: $token);
        self::assertResponseStatusCodeSame(204);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull($userRepo->find($user->getId())?->getAvatarPath());
        self::assertFalse($storage->exists($path));

        $this->jsonRequest('GET', '/api/auth/me', session: $token);
        self::assertFalse($this->jsonBody()['hasAvatar']);
    }

    public function testAvatarEndpointsRequireAuth(): void
    {
        $this->jsonRequest('GET', '/api/auth/avatar');
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('DELETE', '/api/auth/avatar');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/auth/avatar');
        self::assertResponseStatusCodeSame(401);
    }

    // -------------------- FORGOT PASSWORD --------------------

    public function testForgotPasswordForExistingUserSendsEmailAndCreatesToken(): void
    {
        UserFactory::createOne(['email' => 'reset@test.it']);

        $this->jsonRequest('POST', '/api/auth/forgot-password', ['email' => 'reset@test.it']);

        self::assertResponseStatusCodeSame(200);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'reset@test.it');

        $repo = static::getContainer()->get(PasswordResetTokenRepository::class);
        self::assertCount(1, $repo->findAll());
    }

    public function testForgotPasswordForUnknownEmailReturns200NoEmail(): void
    {
        $this->jsonRequest('POST', '/api/auth/forgot-password', ['email' => 'ghost@test.it']);

        // Nessuna user enumeration: stesso 200, ma niente email né token
        self::assertResponseStatusCodeSame(200);
        self::assertEmailCount(0);

        $repo = static::getContainer()->get(PasswordResetTokenRepository::class);
        self::assertCount(0, $repo->findAll());
    }

    public function testForgotPasswordInvalidatesPreviousTokens(): void
    {
        UserFactory::createOne(['email' => 'twice@test.it']);

        $this->jsonRequest('POST', '/api/auth/forgot-password', ['email' => 'twice@test.it']);
        self::assertResponseStatusCodeSame(200);
        $this->jsonRequest('POST', '/api/auth/forgot-password', ['email' => 'twice@test.it']);
        self::assertResponseStatusCodeSame(200);

        // invalidateAllForUser usa una DQL UPDATE: svuotiamo l'identity map per leggere lo stato reale
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $repo = static::getContainer()->get(PasswordResetTokenRepository::class);
        $all = $repo->findAll();
        self::assertCount(2, $all, 'Entrambi i token persistono, ma uno solo resta valido');
        $valid = array_filter($all, static fn (PasswordResetToken $t) => $t->isValid());
        self::assertCount(1, $valid);
    }

    // -------------------- RESET PASSWORD --------------------

    public function testResetPasswordWithValidToken(): void
    {
        $raw = $this->seedResetToken('rv@test.it', '+1 hour');

        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $raw, 'password' => 'nuovapw123']);
        self::assertResponseStatusCodeSame(204);

        // La nuova password funziona
        $this->jsonRequest('POST', '/api/auth/login', ['email' => 'rv@test.it', 'password' => 'nuovapw123']);
        self::assertResponseIsSuccessful();

        // Il token è marcato usato
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $repo = static::getContainer()->get(PasswordResetTokenRepository::class);
        $token = $repo->findOneBy(['tokenHash' => hash('sha256', $raw)]);
        self::assertInstanceOf(PasswordResetToken::class, $token);
        self::assertNotNull($token->getUsedAt());
    }

    public function testResetPasswordReusedTokenReturns400(): void
    {
        $raw = $this->seedResetToken('reuse@test.it', '+1 hour');

        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $raw, 'password' => 'nuovapw123']);
        self::assertResponseStatusCodeSame(204);

        // Riuso dello stesso token monouso
        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $raw, 'password' => 'altrapw123']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('auth.invalid_reset_token', $this->jsonBody()['title']);
    }

    public function testResetPasswordWithExpiredToken(): void
    {
        $raw = $this->seedResetToken('exp@test.it', '-1 hour');

        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $raw, 'password' => 'nuovapw123']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('auth.invalid_reset_token', $this->jsonBody()['title']);
    }

    public function testResetPasswordWithUnknownTokenReturns400(): void
    {
        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token' => 'deadbeefdeadbeefdeadbeefdeadbeef',
            'password' => 'nuovapw123',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testResetPasswordValidatesPassword(): void
    {
        $this->jsonRequest('POST', '/api/auth/reset-password', [
            'token' => 'sometoken',
            'password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testForgotThenResetFlow(): void
    {
        UserFactory::createOne(['email' => 'flow@test.it']);

        $this->jsonRequest('POST', '/api/auth/forgot-password', ['email' => 'flow@test.it']);
        self::assertResponseStatusCodeSame(200);
        self::assertEmailCount(1);

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        $body = (string) $message->getTextBody();
        self::assertSame(1, preg_match('/reset-password\?token=([a-f0-9]+)/', $body, $m));
        $rawToken = $m[1] ?? '';
        self::assertNotSame('', $rawToken, 'Il token deve comparire nel corpo dell\'email');

        $this->jsonRequest('POST', '/api/auth/reset-password', ['token' => $rawToken, 'password' => 'flowpw123']);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('POST', '/api/auth/login', ['email' => 'flow@test.it', 'password' => 'flowpw123']);
        self::assertResponseIsSuccessful();
    }

    // -------------------- EMAIL VERIFICATION --------------------

    public function testRegisterStartsUnverifiedAndSendsVerificationEmail(): void
    {
        $this->jsonRequest('POST', '/api/auth/register', [
            'email' => 'newbie@test.it',
            'password' => 'password123',
            'firstName' => 'New',
            'lastName' => 'Bie',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->jsonBody()['user']['emailVerified'], 'Nuovo account non verificato');

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        $body = $message->toString();
        self::assertSame(1, preg_match('/verify-email\?token=[a-f0-9]+/', $body), 'Email con link di verifica');

        $repo = static::getContainer()->get(EmailVerificationTokenRepository::class);
        self::assertCount(1, $repo->findAll());
    }

    public function testVerifyEmailWithValidToken(): void
    {
        $raw = $this->seedVerificationToken('verify@test.it', '+24 hours');

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $raw]);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->jsonBody()['emailChanged']);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('verify@test.it');
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isEmailVerified());
    }

    public function testVerifyEmailReusedTokenReturns400(): void
    {
        $raw = $this->seedVerificationToken('reuse-verify@test.it', '+24 hours');

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $raw]);
        self::assertResponseStatusCodeSame(200);

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $raw]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('auth.invalid_verification_token', $this->jsonBody()['title']);
    }

    public function testVerifyEmailWithExpiredTokenReturns400(): void
    {
        $raw = $this->seedVerificationToken('exp-verify@test.it', '-1 hour');

        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $raw]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('auth.invalid_verification_token', $this->jsonBody()['title']);
    }

    public function testVerifyEmailWithUnknownTokenReturns400(): void
    {
        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => bin2hex(random_bytes(32))]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testResendVerificationSendsNewEmail(): void
    {
        // Registra (1 email), poi richiede il resend col token di sessione (2ª email).
        $this->jsonRequest('POST', '/api/auth/register', [
            'email' => 'resend@test.it',
            'password' => 'password123',
            'firstName' => 'Re',
            'lastName' => 'Send',
        ]);

        $this->jsonRequest('POST', '/api/auth/resend-verification', []);
        self::assertResponseStatusCodeSame(204);
        self::assertEmailCount(1); // l'email del resend (collector per-richiesta)

        // Un solo token valido (il precedente è invalidato).
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $all = static::getContainer()->get(EmailVerificationTokenRepository::class)->findAll();
        $valid = array_filter($all, static fn (EmailVerificationToken $t) => $t->isValid());
        self::assertCount(1, $valid);
    }

    // -------------------- INVITATIONS --------------------

    public function testPreviewInvitation(): void
    {
        [, $raw] = $this->seedInvitation('preview@test.it');

        $this->jsonRequest('GET', '/api/auth/invitation/'.$raw);

        self::assertResponseIsSuccessful();
        $b = $this->jsonBody();
        self::assertSame('preview@test.it', $b['email']);
        self::assertSame('member', $b['role']);
        self::assertFalse($b['accountExists']);
        self::assertArrayHasKey('organizationName', $b);
    }

    public function testPreviewInvitationInvalidTokenReturns400(): void
    {
        $this->jsonRequest('GET', '/api/auth/invitation/'.bin2hex(random_bytes(32)));
        self::assertResponseStatusCodeSame(400);
    }

    public function testAcceptInvitationExistingUser(): void
    {
        $email = 'joiner@test.it';
        [$org, $raw] = $this->seedInvitation($email);

        $this->jsonRequest('POST', '/api/auth/register', [
            'email' => $email,
            'password' => 'password123',
            'firstName' => 'Joi',
            'lastName' => 'Ner',
        ]);

        $this->jsonRequest('POST', '/api/auth/invitation/accept', ['token' => $raw]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($org->getId(), $this->jsonBody()['organizationId']);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        $member = static::getContainer()->get(OrganizationMemberRepository::class)->findMembership($user, $org);
        self::assertNotNull($member);
        self::assertTrue($member->isAccepted());
    }

    public function testAcceptInvitationWrongEmailReturns403(): void
    {
        [, $raw] = $this->seedInvitation('invitee@test.it');

        $this->jsonRequest('POST', '/api/auth/register', [
            'email' => 'someone-else@test.it',
            'password' => 'password123',
            'firstName' => 'Other',
            'lastName' => 'User',
        ]);

        $this->jsonRequest('POST', '/api/auth/invitation/accept', ['token' => $raw]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAcceptInvitationInvalidTokenReturns400(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/auth/invitation/accept', [
            'token' => bin2hex(random_bytes(32)),
        ], session: $token);
        self::assertResponseStatusCodeSame(400);
    }

    public function testRegisterInvitedCreatesAccountAndJoins(): void
    {
        $email = 'brandnew@test.it';
        [$org, $raw] = $this->seedInvitation($email, OrgRole::ADMIN);

        $this->jsonRequest('POST', '/api/auth/invitation/register', [
            'token' => $raw,
            'firstName' => 'Bran',
            'lastName' => 'New',
            'password' => 'password123',
        ]);

        self::assertResponseStatusCodeSame(201);
        $b = $this->jsonBody();
        self::assertSame($email, $b['user']['email']);
        self::assertTrue($b['user']['emailVerified'], 'Email verificata: l\'invito prova il possesso dell\'indirizzo');

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        $memberRepo = static::getContainer()->get(OrganizationMemberRepository::class);
        $member = $memberRepo->findMembership($user, $org);
        self::assertNotNull($member);
        self::assertTrue($member->isAccepted());
        self::assertSame(OrgRole::ADMIN, $member->getRole());

        // L'invitato NON deve avere un'org personale: solo la membership dell'invito.
        // (modello family-sharing: si entra nell'org, non se ne crea una propria)
        $all = $memberRepo->findAllForUser($user);
        self::assertCount(1, $all, 'L\'invitato ha una sola membership: l\'org che lo ha invitato');
        self::assertSame($org->getId(), $all[0]->getOrganization()->getId());
    }

    public function testInvitedRegistrationWorksWhileFreeRegistrationIsClosed(): void
    {
        // Istanza con utenti → /register è chiusa. L'invito (token) è l'unica via
        // per entrare e deve continuare a funzionare.
        UserFactory::createOne();
        $this->jsonRequest('GET', '/api/auth/registration');
        self::assertSame(['open' => false], $this->jsonBody());

        [, $raw] = $this->seedInvitation('guest@test.it', OrgRole::MEMBER);
        $this->jsonRequest('POST', '/api/auth/invitation/register', [
            'token' => $raw,
            'firstName' => 'Ospite',
            'lastName' => 'Invitato',
            'password' => 'password123',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('guest@test.it', $this->jsonBody()['user']['email']);
    }

    public function testRegisterInvitedWhenEmailAlreadyExistsReturns409(): void
    {
        $email = 'exists@test.it';
        UserFactory::createOne(['email' => $email]);
        [, $raw] = $this->seedInvitation($email);

        $this->jsonRequest('POST', '/api/auth/invitation/register', [
            'token' => $raw,
            'firstName' => 'X',
            'lastName' => 'Y',
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    // -------------------- inviter must still be able to invite --------------------

    /**
     * Invito spedito da $inviter (membro con $inviterRole, o nessuna membership se null) per $email.
     *
     * @return array{0: \App\Entity\Organization, 1: string, 2: User}
     */
    private function seedInvitationBy(?OrgRole $inviterRole, string $email, OrgRole $role = OrgRole::MEMBER, bool $accepted = true): array
    {
        $org = OrganizationFactory::createOne();
        $inviter = UserFactory::createOne();
        if ($inviterRole !== null) {
            OrganizationMemberFactory::createOne([
                'user' => $inviter, 'organization' => $org, 'role' => $inviterRole,
                'acceptedAt' => $accepted ? new \DateTimeImmutable() : null,
            ]);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = bin2hex(random_bytes(32));
        $em->persist(new OrganizationInvitation($org, mb_strtolower($email), $role, hash('sha256', $raw), (new \DateTimeImmutable())->modify('+7 days'), $inviter));
        $em->flush();

        return [$org, $raw, $inviter];
    }

    /** @return iterable<string, array{0: OrgRole|null, 1: bool}> inviter non più abilitato: ruolo (null = non è più membro) e accettazione */
    public static function disabledInviters(): iterable
    {
        yield 'removed from the org' => [null, true];
        yield 'demoted to member' => [OrgRole::MEMBER, true];
        yield 'membership not accepted' => [OrgRole::ADMIN, false];
    }

    #[DataProvider('disabledInviters')]
    public function testInvitationIsInvalidWhenInviterCannotInviteAnymore(?OrgRole $inviterRole, bool $accepted): void
    {
        [, $raw] = $this->seedInvitationBy($inviterRole, 'late@test.it', OrgRole::ADMIN, $accepted);

        // Preview
        $this->jsonRequest('GET', '/api/auth/invitation/'.$raw);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invitation.invalid', $this->jsonBody()['title']);

        // Registrazione dell'invitato
        $this->jsonRequest('POST', '/api/auth/invitation/register', [
            'token' => $raw, 'firstName' => 'Late', 'lastName' => 'Guest', 'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invitation.invalid', $this->jsonBody()['title']);
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('late@test.it'), 'Nessun account creato');

        // Accettazione da utente già registrato
        $late = UserFactory::createOne(['email' => 'late@test.it']);
        $this->jsonRequest('POST', '/api/auth/invitation/accept', ['token' => $raw], session: $this->sessionFor($late));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invitation.invalid', $this->jsonBody()['title']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame([], static::getContainer()->get(OrganizationMemberRepository::class)->findAllForUser($late));
    }

    public function testInvitationFromOwnerOrAdminInviterStillWorksEverywhere(): void
    {
        foreach ([OrgRole::OWNER, OrgRole::ADMIN] as $i => $inviterRole) {
            [$org, $raw] = $this->seedInvitationBy($inviterRole, "fine$i@test.it");
            $this->jsonRequest('GET', '/api/auth/invitation/'.$raw);
            self::assertResponseIsSuccessful();
            self::assertSame("fine$i@test.it", $this->jsonBody()['email']);

            $existing = UserFactory::createOne(['email' => "fine$i@test.it"]);
            $this->jsonRequest('POST', '/api/auth/invitation/accept', ['token' => $raw], session: $this->sessionFor($existing));
            self::assertResponseStatusCodeSame(200);
            self::assertSame($org->getId(), $this->jsonBody()['organizationId']);
        }

        [, $raw] = $this->seedInvitationBy(OrgRole::ADMIN, 'newcomer@test.it');
        $this->jsonRequest('POST', '/api/auth/invitation/register', [
            'token' => $raw, 'firstName' => 'New', 'lastName' => 'Comer', 'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(201);
    }


    // -------------------- helpers --------------------

    /**
     * Crea un'organizzazione + un OrganizationInvitation per $email; ritorna [org, rawToken].
     *
     * @return array{0: \App\Entity\Organization, 1: string}
     */
    private function seedInvitation(string $email, OrgRole $role = OrgRole::MEMBER): array
    {
        $org = OrganizationFactory::createOne();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = bin2hex(random_bytes(32));
        $em->persist(new OrganizationInvitation(
            $org,
            mb_strtolower($email),
            $role,
            hash('sha256', $raw),
            (new \DateTimeImmutable())->modify('+7 days'),
        ));
        $em->flush();

        return [$org, $raw];
    }

    /**
     * Crea un utente + un EmailVerificationToken persistito; ritorna il token RAW.
     */
    private function seedVerificationToken(string $email, string $expiresModifier): string
    {
        $user = UserFactory::createOne(['email' => $email]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = bin2hex(random_bytes(32));
        $em->persist(new EmailVerificationToken(
            $user,
            hash('sha256', $raw),
            (new \DateTimeImmutable())->modify($expiresModifier),
        ));
        $em->flush();

        return $raw;
    }

    /**
     * Crea un utente + un PasswordResetToken persistito; ritorna il token RAW
     * (nel DB c'è solo l'hash SHA-256, il raw esiste solo qui e nell'email).
     */
    private function seedResetToken(string $email, string $expiresModifier): string
    {
        $user = UserFactory::createOne(['email' => $email]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = bin2hex(random_bytes(32));
        $em->persist(new PasswordResetToken(
            $user,
            hash('sha256', $raw),
            (new \DateTimeImmutable())->modify($expiresModifier),
        ));
        $em->flush();

        return $raw;
    }
}
