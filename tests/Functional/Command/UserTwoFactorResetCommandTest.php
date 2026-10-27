<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\User;
use App\Security\BackupCode;
use App\Service\TwoFactor\TwoFactorSetup;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * `app:user:2fa-reset` (utente che ha perso telefono e codici) e i record sul canale `security` di attivazione,
 * disattivazione, codici nuovi e reset: con `user_id`, mai segreti o codici.
 */
final class UserTwoFactorResetCommandTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private TestHandler $securityLog;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $logger = static::getContainer()->get('monolog.logger.security');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->securityLog = new TestHandler());
    }

    public function testWithoutConfirmNothingChanges(): void
    {
        $user = $this->userWithTwoFactor('lost-phone@test.it');

        $tester = $this->runReset(['email' => 'lost-phone@test.it']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('DRY-RUN', $tester->getDisplay());
        self::assertTrue($this->fresh($user)->isTotpAuthenticationEnabled());
    }

    public function testWithConfirmTheVerificationIsTurnedOff(): void
    {
        $user = $this->userWithTwoFactor('lost-phone@test.it');

        $tester = $this->runReset(['email' => 'lost-phone@test.it', '--confirm' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $fresh = $this->fresh($user);
        self::assertFalse($fresh->isTotpAuthenticationEnabled());
        self::assertNull($fresh->getEncryptedTotpSecret());
        self::assertSame(0, $fresh->backupCodesRemaining());
        self::assertTrue($this->securityLog->hasWarning(['message' => 'Two-step verification reset by the instance administrator', 'context' => ['user_id' => $user->getId()]]));
    }

    public function testUnknownEmailFails(): void
    {
        self::assertSame(Command::FAILURE, $this->runReset(['email' => 'nessuno@test.it', '--confirm' => true])->getStatusCode());
    }

    public function testAUserWithoutVerificationIsNothingToDo(): void
    {
        UserFactory::createOne(['email' => 'plain@test.it']);

        $tester = $this->runReset(['email' => 'plain@test.it', '--confirm' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('nothing to do', $tester->getDisplay());
    }

    public function testEnableDisableAndNewCodesAreLoggedWithoutSecrets(): void
    {
        $user = UserFactory::createOne();
        $setup = static::getContainer()->get(TwoFactorSetup::class);
        $secret = $setup->begin($user)['secret'];
        if ($secret === '') {
            self::fail('Segreto TOTP vuoto');
        }
        $codes = $setup->enable($user, UserFactory::DEFAULT_PASSWORD, TOTP::createFromSecret($secret)->now());
        $newCodes = $setup->regenerateBackupCodes($user, UserFactory::DEFAULT_PASSWORD, $codes[0]);
        $setup->disable($user, UserFactory::DEFAULT_PASSWORD, $newCodes[0]);

        $messages = array_map(static fn ($r): string => $r->message, $this->securityLog->getRecords());
        self::assertSame([
            'Two-step verification enabled',
            'Two-step verification: recovery codes regenerated',
            'Two-step verification disabled',
        ], $messages);
        foreach ($this->securityLog->getRecords() as $record) {
            self::assertSame(['user_id' => $user->getId()], $record->context);
            $dump = json_encode($record->toArray(), \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($secret, $dump);
            foreach ([...$codes, ...$newCodes] as $code) {
                self::assertStringNotContainsString($code, $dump);
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function runReset(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:user:2fa-reset'));
        $tester->execute($input);

        return $tester;
    }

    private function userWithTwoFactor(string $email): User
    {
        $user = UserFactory::createOne(['email' => $email]);
        $user->beginTotpSetup('cifrato', 'JBSWY3DPEHPK3PXP');
        $user->enableTotp(new \DateTimeImmutable(), [BackupCode::hash('aaaa-bbbb-cccc')]);
        $this->em()->flush();

        return $user;
    }

    private function fresh(User $user): User
    {
        $this->em()->clear();
        $fresh = $this->em()->find(User::class, $user->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
