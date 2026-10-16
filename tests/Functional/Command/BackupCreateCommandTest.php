<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\BackupCreateCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Copre la regressione di sicurezza del backup: con `mariadb-dump | gzip` l'exit
 * code della pipe era quello di gzip (quasi mai fallisce), quindi un dump fallito
 * (host irraggiungibile, credenziali sbagliate) scriveva un .sql.gz vuoto/troncato
 * e il comando riportava comunque successo — la retention avrebbe poi eliminato
 * anche gli ultimi backup buoni. Il dump ora gira senza shell (né pipefail, che
 * dash non supporta): il test esegue mariadb-dump per davvero, come in CI.
 */
final class BackupCreateCommandTest extends KernelTestCase
{
    private string $backupsDir;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->backupsDir = (string) static::getContainer()->getParameter('app.backups_dir');
        (new Filesystem())->remove(glob($this->backupsDir.'/*') ?: []);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(glob($this->backupsDir.'/*') ?: []);
        parent::tearDown();
    }

    public function testCreatesNonEmptyDumpOnSuccess(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:backup:create'));

        $tester->execute(['--skip-uploads' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $dumps = glob($this->backupsDir.'/db-*.sql.gz') ?: [];
        self::assertCount(1, $dumps, 'Deve creare esattamente un file di dump');
        self::assertSame(0600, fileperms($dumps[0]) & 0777, 'Il dump contiene dati personali: solo il proprietario');

        // gzip valido con SQL vero dentro (non un file vuoto/troncato/doppiamente
        // compresso): decomprimendolo si ritrova lo schema dell'app.
        $sql = gzdecode((string) file_get_contents($dumps[0]));
        self::assertIsString($sql, 'Il file deve essere un gzip valido');
        self::assertStringContainsString('CREATE TABLE `users`', $sql);
    }

    public function testFailsInsteadOfWritingEmptyDumpWhenMariadbDumpCannotConnect(): void
    {
        // Connection farlocca: host irraggiungibile → mariadb-dump fallisce subito.
        // Con la vecchia pipeline `mariadb-dump ... | gzip > file` si sarebbe
        // comunque scritto un .sql.gz quasi vuoto riportando successo.
        $brokenDb = $this->createMock(Connection::class);
        $brokenDb->method('getParams')->willReturn([
            'host' => '127.0.0.1',
            'port' => 1, // porta chiusa: connection refused immediato
            'user' => 'brumy',
            'password' => 'wrong',
            'dbname' => 'brumy',
        ]);

        $command = new BackupCreateCommand($brokenDb, '/tmp', $this->backupsDir);
        $tester = new CommandTester($command);

        $threw = false;
        try {
            $tester->execute(['--skip-uploads' => true]);
        } catch (ProcessFailedException) {
            $threw = true;
        }

        self::assertTrue($threw, 'Un mariadb-dump fallito deve far fallire rumorosamente il comando, non passare inosservato');

        // Il file parziale viene rimosso: un backup troncato non deve restare sul
        // disco a sembrare valido (né essere scambiato per l'ultimo backup buono).
        self::assertSame([], glob($this->backupsDir.'/db-*.sql.gz') ?: []);
    }

    public function testRetentionRemovesOnlyBackupsOlderThanTheWindow(): void
    {
        // Un backup recente non deve mai essere toccato: la retention cancella solo oltre la soglia.
        $old = $this->backupsDir.'/db-old.sql.gz';
        $recent = $this->backupsDir.'/db-recent.sql.gz';
        $unrelated = $this->backupsDir.'/notes.txt';
        foreach ([$old, $recent, $unrelated] as $file) {
            (new Filesystem())->dumpFile($file, 'x');
        }
        touch($old, strtotime('-40 days'));
        touch($recent, strtotime('-5 days'));
        touch($unrelated, strtotime('-400 days'));

        $tester = $this->backup(['--skip-uploads' => true, '--retention-days' => '30']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist($old);
        self::assertFileExists($recent);
        self::assertFileExists($unrelated, 'La retention tocca solo i *.gz');
        self::assertCount(1, glob($this->backupsDir.'/db-2*.sql.gz') ?: [], 'Il backup appena creato resta');
        self::assertStringContainsString('rimossi 1 file backup', $tester->getDisplay());
    }

    #[DataProvider('invalidRetention')]
    public function testInvalidRetentionFailsBeforeCreatingOrDeletingAnything(string $days): void
    {
        // retention 0 farebbe cancellare anche il dump appena scritto (e tutti i precedenti)
        $existing = $this->backupsDir.'/db-existing.sql.gz';
        (new Filesystem())->dumpFile($existing, 'x');
        touch($existing, strtotime('-400 days'));

        $tester = $this->backup(['--skip-uploads' => true, '--retention-days' => $days]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileExists($existing);
        self::assertSame([$existing], glob($this->backupsDir.'/*') ?: []);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRetention(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['abc'];
    }

    public function testUploadsAreArchivedAndTheTarballIsPrivate(): void
    {
        $uploads = sys_get_temp_dir().'/brumy_backup_uploads_'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($uploads.'/2025/01/file.pdf', 'contenuto allegato');

        try {
            $command = new BackupCreateCommand(static::getContainer()->get(Connection::class), $uploads, $this->backupsDir);
            $tester = new CommandTester($command);
            $tester->execute([]);
        } finally {
            (new Filesystem())->remove($uploads);
        }

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $tars = glob($this->backupsDir.'/uploads-*.tar.gz') ?: [];
        self::assertCount(1, $tars);
        $listing = (string) shell_exec('tar -tzf '.escapeshellarg($tars[0]));
        self::assertStringContainsString('2025/01/file.pdf', $listing);

        // dump e tarball contengono dati personali: solo il proprietario li legge
        foreach (array_merge($tars, glob($this->backupsDir.'/db-*.sql.gz') ?: []) as $file) {
            self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4), basename($file));
        }
    }

    public function testEmptyUploadsDirectorySkipsTheTarball(): void
    {
        $uploads = sys_get_temp_dir().'/brumy_backup_empty_'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($uploads);

        try {
            $tester = new CommandTester(new BackupCreateCommand(static::getContainer()->get(Connection::class), $uploads, $this->backupsDir));
            $tester->execute([]);
        } finally {
            (new Filesystem())->remove($uploads);
        }

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], glob($this->backupsDir.'/uploads-*') ?: []);
        self::assertCount(1, glob($this->backupsDir.'/db-*.sql.gz') ?: []);
    }

    /** @param array<string, mixed> $input */
    private function backup(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:backup:create'));
        $tester->execute($input);

        return $tester;
    }
}
