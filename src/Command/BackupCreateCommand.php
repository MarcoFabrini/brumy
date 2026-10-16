<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Backup snapshot: mariadb-dump del DB + tar gzip degli upload allegati.
 * Output in /var/backups/. Retention configurabile (default 30 giorni).
 *
 * Cron suggerito (giornaliero alle 03:00):
 *   0 3 * * *  php bin/console app:backup:create
 *
 * Per offsite: usare rsync/rclone su /var/backups/ esternamente.
 */
#[AsCommand(name: 'app:backup:create', description: 'Create DB dump + uploads tarball with retention cleanup')]
final class BackupCreateCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire(param: 'app.attachments_root')] private readonly string $attachmentsRoot,
        #[Autowire(param: 'app.backups_dir')] private readonly string $backupsDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Delete backups older than N days', '30')
            ->addOption('skip-uploads', null, InputOption::VALUE_NONE, 'Solo DB dump, skip tar uploads');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Validato prima di creare i file: 0 (o un valore non numerico) farebbe cancellare anche il backup appena fatto.
        $retentionDays = (int) $input->getOption('retention-days');
        if ($retentionDays < 1) {
            $io->error('--retention-days deve essere un intero >= 1.');

            return Command::INVALID;
        }
        $fs = new Filesystem();

        if (!$fs->exists($this->backupsDir)) {
            $fs->mkdir($this->backupsDir, 0750);
        }

        $today = (new \DateTimeImmutable())->format('Y-m-d_His');
        $dbDumpPath = sprintf('%s/db-%s.sql.gz', $this->backupsDir, $today);
        $uploadsTarPath = sprintf('%s/uploads-%s.tar.gz', $this->backupsDir, $today);

        // 1. DB dump
        $io->section('Database dump');
        $params = $this->db->getParams();
        // Niente shell né pipeline `mariadb-dump | gzip`: l'exit code di una pipe è
        // quello dell'ultimo comando (gzip, che quasi mai fallisce), quindi un dump
        // fallito (DB giù, credenziali sbagliate) lasciava un .sql.gz vuoto/troncato
        // con esito "successo" — e la retention poteva poi eliminare anche gli
        // ultimi backup buoni. `set -o pipefail` non è una soluzione portabile:
        // dash (sh su Debian/Ubuntu) non lo supporta, busybox ash sì. Qui il dump
        // gira come processo diretto (argv, niente escaping) e PHP comprime lo
        // stdout in streaming: l'exit code di mariadb-dump si controlla direttamente.
        // --single-transaction: snapshot consistente (una transazione InnoDB) invece
        // di un dump table-by-table che può catturare stati incoerenti se l'app scrive.
        $dumpProc = new Process(
            [
                'mariadb-dump',
                '--single-transaction',
                '--host='.(string) ($params['host'] ?? 'db'),
                '--port='.(int) ($params['port'] ?? 3306),
                '--user='.(string) ($params['user'] ?? 'brumy'),
                (string) ($params['dbname'] ?? 'brumy'),
            ],
            env: ['MYSQL_PWD' => $params['password'] ?? ''],
            timeout: 600,
        );
        $this->streamGzipped($dumpProc, $dbDumpPath);
        // Contiene dati personali: leggibile solo dal proprietario. Il file nasce già 0600 (umask in streamGzipped);
        // il chmod resta come cintura e, se fallisce, il backup non passa per buono.
        $fs->chmod($dbDumpPath, 0600);
        $io->success("DB dump → $dbDumpPath (".$this->humanSize(filesize($dbDumpPath) ?: 0).')');

        // 2. Uploads tarball
        if (!$input->getOption('skip-uploads')) {
            $io->section('Uploads tarball');
            if ($fs->exists($this->attachmentsRoot) && !$this->isDirEmpty($this->attachmentsRoot)) {
                $tarCmd = sprintf(
                    'tar -czf %s -C %s .',
                    escapeshellarg($uploadsTarPath),
                    escapeshellarg($this->attachmentsRoot),
                );
                // umask restrittiva anche per il processo figlio: il tar nasce 0600, non 0644 fino al chmod.
                $previousUmask = umask(0o077);
                try {
                    Process::fromShellCommandline($tarCmd, timeout: 600)->mustRun();
                } catch (\Throwable $e) {
                    // Un tar troncato non deve restare (la retention lo conserverebbe come se fosse valido).
                    @unlink($uploadsTarPath);
                    throw $e;
                } finally {
                    umask($previousUmask);
                }
                $fs->chmod($uploadsTarPath, 0600);
                $io->success("Uploads tar → $uploadsTarPath (".$this->humanSize(filesize($uploadsTarPath) ?: 0).')');
            } else {
                $io->info('Uploads dir vuota o inesistente, skip tar.');
            }
        }

        // 3. Retention cleanup
        $deleted = $this->pruneOldBackups($retentionDays);
        if ($deleted > 0) {
            $io->info("Retention: rimossi $deleted file backup più vecchi di $retentionDays giorni.");
        }

        return Command::SUCCESS;
    }

    /**
     * Esegue il processo e ne scrive lo stdout compresso (gzip) in $destination.
     * Se il processo fallisce il file parziale viene rimosso e l'errore rilanciato:
     * un backup troncato non deve restare sul disco a sembrare valido.
     *
     * @throws ProcessFailedException se il processo esce con codice != 0
     */
    private function streamGzipped(Process $process, string $destination): void
    {
        // Il file viene creato qui: con umask 077 non è mai leggibile da altri, nemmeno mentre il dump è in corso.
        $previousUmask = umask(0o077);
        try {
            $gz = gzopen($destination, 'wb');
        } finally {
            umask($previousUmask);
        }
        if ($gz === false) {
            throw new \RuntimeException("Impossibile scrivere $destination");
        }

        try {
            $process->start();
            // ITER_SKIP_ERR: lo stderr resta nel buffer del processo (serve al
            // messaggio dell'eccezione); lo stdout viene svuotato a ogni chunk,
            // quindi un dump grande non si accumula in memoria.
            foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
                gzwrite($gz, $chunk);
            }
            $process->wait();
        } finally {
            gzclose($gz);
        }

        if (!$process->isSuccessful()) {
            @unlink($destination);
            throw new ProcessFailedException($process);
        }
    }

    private function pruneOldBackups(int $days): int
    {
        $cutoff = (new \DateTimeImmutable("-{$days} days"))->getTimestamp();
        $deleted = 0;
        foreach (glob($this->backupsDir.'/*.gz') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }
        return $deleted;
    }

    private function isDirEmpty(string $dir): bool
    {
        $h = opendir($dir);
        if ($h === false) {
            return true;
        }
        while (($entry = readdir($h)) !== false) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($h);
                return false;
            }
        }
        closedir($h);
        return true;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "$bytes B";
        }
        $units = ['KB', 'MB', 'GB'];
        $i = 0;
        $size = $bytes / 1024;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $size, $units[$i]);
    }
}
