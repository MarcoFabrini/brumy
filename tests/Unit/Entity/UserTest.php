<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Security\BackupCode;
use PHPUnit\Framework\TestCase;

/**
 * Uguaglianza per la sessione (cosa la fa cadere e cosa no), dati salvati in sessione e regole della verifica in due
 * passaggi sull'entity.
 */
final class UserTest extends TestCase
{
    public function testTheSessionKeepsOnlyIdEmailAndAPasswordFingerprint(): void
    {
        $user = $this->user(7, 'anna@test.it', '$argon2id$v=19$m=10,t=3,p=1$hash-vero');
        $user->beginTotpSetup('cifrato', 'SEGRETO');

        $data = $user->__serialize();

        self::assertSame(['id' => 7, 'email' => 'anna@test.it', 'password' => hash('crc32c', '$argon2id$v=19$m=10,t=3,p=1$hash-vero')], $data);
        $restored = unserialize(serialize($user));
        self::assertInstanceOf(User::class, $restored);
        self::assertSame(['ROLE_USER'], $restored->getRoles());
        self::assertTrue($restored->isEqualTo($user), 'Il dato in sessione corrisponde all\'utente ricaricato');
        self::assertTrue($user->isEqualTo($restored));
    }

    public function testAChangedPasswordOrEmailEndsTheSession(): void
    {
        $user = $this->user(7, 'anna@test.it', 'hash-1');
        $inSession = unserialize(serialize($user));
        self::assertInstanceOf(User::class, $inSession);

        self::assertFalse($inSession->isEqualTo($this->user(7, 'anna@test.it', 'hash-2')), 'Password cambiata');
        self::assertFalse($inSession->isEqualTo($this->user(7, 'nuova@test.it', 'hash-1')), 'Email cambiata');
        self::assertFalse($inSession->isEqualTo($this->user(8, 'anna@test.it', 'hash-1')), 'Un altro utente');
    }

    public function testRolesDoNotTakePartInTheEquality(): void
    {
        $user = $this->user(7, 'anna@test.it', 'hash-1');
        $promoted = $this->user(7, 'anna@test.it', 'hash-1');
        $promoted->addMembership((new \App\Entity\OrganizationMember())->setRole(\App\Enum\OrgRole::OWNER));

        self::assertNotSame($user->getRoles(), $promoted->getRoles());
        self::assertTrue($user->isEqualTo($promoted));
    }

    public function testTwoFactorStates(): void
    {
        $user = $this->user(1, 'a@test.it', 'h');
        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpAuthenticationConfiguration());

        $user->beginTotpSetup('cifrato', 'JBSWY3DPEHPK3PXP');
        self::assertTrue($user->hasPendingTotpSetup());
        self::assertFalse($user->isTotpAuthenticationEnabled(), 'Il login non cambia finché non si conferma');
        $config = $user->getTotpAuthenticationConfiguration();
        self::assertNotNull($config);
        self::assertSame(['JBSWY3DPEHPK3PXP', 'sha1', 30, 6], [$config->getSecret(), $config->getAlgorithm(), $config->getPeriod(), $config->getDigits()]);

        $user->enableTotp(new \DateTimeImmutable(), [BackupCode::hash('aaaa-bbbb-cccc')]);
        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertFalse($user->hasPendingTotpSetup());
        self::assertSame('a@test.it', $user->getTotpAuthenticationUsername());

        $user->disableTwoFactor();
        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getEncryptedTotpSecret());
        self::assertSame(0, $user->backupCodesRemaining());
    }

    public function testEnableWithoutAPendingSetupIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->user(1, 'a@test.it', 'h')->enableTotp(new \DateTimeImmutable(), []);
    }

    public function testARecoveryCodeWorksOnceAndIgnoresCaseSpacesAndDashes(): void
    {
        $user = $this->user(1, 'a@test.it', 'h');
        $user->beginTotpSetup('cifrato', 'JBSWY3DPEHPK3PXP');
        $user->enableTotp(new \DateTimeImmutable(), [BackupCode::hash('abcd-efgh-jkmn'), BackupCode::hash('0000-1111-2222')]);

        self::assertTrue($user->isBackupCode('ABCD EFGH JKMN'));
        self::assertTrue($user->isBackupCode('oooo-iiii-2222'), 'Sostituzioni di Crockford: o → 0, i/l → 1');
        self::assertFalse($user->isBackupCode('abcd-efgh-jkmp'));

        $user->invalidateBackupCode('abcdefghjkmn');
        self::assertFalse($user->isBackupCode('abcd-efgh-jkmn'));
        self::assertSame(1, $user->backupCodesRemaining());
    }

    public function testGeneratedRecoveryCodesHaveTheShownFormat(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            self::assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{4}-[0-9a-hjkmnp-tv-z]{4}-[0-9a-hjkmnp-tv-z]{4}$/', BackupCode::generate());
        }
    }

    private function user(int $id, string $email, string $password): User
    {
        $user = (new User())->setEmail($email)->setPassword($password)->setFirstName('A')->setLastName('B');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
