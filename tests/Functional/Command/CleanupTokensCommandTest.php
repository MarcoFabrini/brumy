<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\EmailVerificationToken;
use App\Entity\OrganizationInvitation;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * `app:tokens:cleanup` gira da cron (Ofelia) e CANCELLA righe: l'errore da prevenire è portarsi via
 * un cookie remember-me ancora valido (o un reset/invito ancora utilizzabile). Il test fissa il
 * confine: sparisce solo ciò che è scaduto PRIMA della soglia, il resto resta.
 */
final class CleanupTokensCommandTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = UserFactory::createOne();
    }

    /** @param array<string, string> $input */
    private function runCleanup(array $input = [], string $command = 'app:tokens:cleanup'): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find($command));
        $tester->execute($input);

        return $tester;
    }

    private function rememberMeSeries(string $series, string $lastUsed): void
    {
        $this->em->getConnection()->insert('rememberme_token', [
            'series' => $series,
            'value' => 'value-'.$series,
            'lastUsed' => (new \DateTimeImmutable($lastUsed))->format('Y-m-d H:i:s'),
            'class' => User::class,
            'username' => $this->user->getEmail(),
        ]);
    }

    /** @return list<string> */
    private function remainingSeries(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT series FROM rememberme_token ORDER BY series'));
    }

    /** Il remember-me dura 7 giorni dall'ultimo uso: una serie più vecchia non autentica più, una più recente sì. */
    public function testDeletesOnlyRememberMeSeriesUnusedForMoreThanSevenDays(): void
    {
        $this->rememberMeSeries('used-today', '-1 hour');
        $this->rememberMeSeries('used-6-days-ago', '-6 days');
        $this->rememberMeSeries('unused-8-days', '-8 days');
        $this->rememberMeSeries('unused-40-days', '-40 days');

        $tester = $this->runCleanup();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['used-6-days-ago', 'used-today'], $this->remainingSeries());
        self::assertStringContainsString('Cancellate 2 serie remember-me', $tester->getDisplay());
    }

    /** I `compose.yml` scaricati prima delle sessioni lanciano ancora il vecchio nome: deve fare la stessa pulizia. */
    public function testTheOldCommandNameStillWorks(): void
    {
        $this->rememberMeSeries('unused-8-days', '-8 days');

        $tester = $this->runCleanup(command: 'app:refresh-tokens:cleanup');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $this->remainingSeries());
    }

    #[DataProvider('invalidDays')]
    public function testInvalidDaysFailsWithoutDeletingAnything(string $days): void
    {
        // Con --days=0 il cutoff sarebbe "adesso": porterebbe via anche i link appena scaduti.
        $this->rememberMeSeries('unused-40-days', '-40 days');

        $tester = $this->runCleanup(['--days' => $days]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(['unused-40-days'], $this->remainingSeries());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDays(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'not a number' => ['abc'];
    }

    public function testResetVerificationTokensAndInvitationsAreCleanedWithTheSameCutoff(): void
    {
        $org = OrganizationFactory::createOne();
        $old = new \DateTimeImmutable('-40 days');
        $recent = new \DateTimeImmutable('-2 days');
        $valid = new \DateTimeImmutable('+2 days');

        foreach (['old' => $old, 'recent' => $recent, 'valid' => $valid] as $label => $expires) {
            $this->em->persist(new PasswordResetToken($this->user, "reset-$label", $expires));
            $this->em->persist(new EmailVerificationToken($this->user, "verify-$label", $expires));
            $this->em->persist(new OrganizationInvitation($org, "$label@test.it", OrgRole::MEMBER, "invite-$label", $expires));
        }
        $this->em->flush();

        $tester = $this->runCleanup();

        $connection = $this->em->getConnection();
        self::assertSame(['reset-recent', 'reset-valid'], array_map('strval', $connection->fetchFirstColumn('SELECT token_hash FROM password_reset_tokens ORDER BY token_hash')));
        self::assertSame(['verify-recent', 'verify-valid'], array_map('strval', $connection->fetchFirstColumn('SELECT token_hash FROM email_verification_tokens ORDER BY token_hash')));
        self::assertSame(['invite-recent', 'invite-valid'], array_map('strval', $connection->fetchFirstColumn('SELECT token_hash FROM organization_invitations ORDER BY token_hash')));
        self::assertStringContainsString('Cancellati 3 token di reset/verifica', $tester->getDisplay());
    }

    public function testDaysOptionMovesTheCutoffOfResetTokens(): void
    {
        foreach (['10' => '-10 days', '3' => '-3 days'] as $label => $expires) {
            $this->em->persist(new PasswordResetToken($this->user, "reset-$label", new \DateTimeImmutable($expires)));
        }
        $this->em->flush();

        $this->runCleanup(['--days' => '7']);

        self::assertSame(['reset-3'], array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT token_hash FROM password_reset_tokens')));
    }

    public function testRunningTwiceIsIdempotent(): void
    {
        $this->rememberMeSeries('used-today', '-1 hour');
        $this->rememberMeSeries('unused-40-days', '-40 days');

        $this->runCleanup();
        $second = $this->runCleanup();

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertSame(['used-today'], $this->remainingSeries());
        self::assertStringContainsString('Cancellate 0 serie remember-me', $second->getDisplay());
    }
}
