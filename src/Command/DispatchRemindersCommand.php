<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Reminder;
use App\Enum\ReminderUrgency;
use App\Message\SendReminderNotificationMessage;
use App\Repository\ReminderRepository;
use App\Service\AppClock;
use App\Service\VehicleStatsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Accoda `SendReminderNotificationMessage` per i promemoria che hanno raggiunto un nuovo
 * livello di urgenza: "in scadenza" (entro `notifyDaysBefore` giorni, o entro
 * `notifyKmBefore` km dal chilometraggio attuale del veicolo, {@see Reminder::DEFAULT_NOTIFY_KM_BEFORE} di default)
 * e poi "scaduto".
 *
 * Pensato per un cron giornaliero (`0 7 * * *  php bin/console app:reminders:dispatch`).
 *
 * Idempotenza: una notifica per livello, non una al giorno. Il livello notificato sta sul
 * promemoria (`notifiedUrgency`) ed è preso dall'handler con un claim atomico prima dell'invio; rieseguire
 * il job prima che l'handler giri accoda messaggi doppi, ma l'handler ne scarta i doppioni.
 *
 * Riarmo: se i km del veicolo scendono (un refuso corretto) e un promemoria a km risulta a un livello
 * più basso di quello già notificato, il livello notificato viene abbassato; così il superamento
 * reale della soglia notificherà di nuovo. È l'unico punto che lo fa (e l'unico che scrive), quindi
 * `--dry-run` non riarma nulla.
 */
#[AsCommand(name: 'app:reminders:dispatch', description: 'Dispatch reminder notifications (date and km based)')]
final class DispatchRemindersCommand extends Command
{
    public function __construct(
        private readonly ReminderRepository $reminderRepo,
        private readonly VehicleStatsService $vehicleStats,
        private readonly MessageBusInterface $bus,
        private readonly AppClock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List reminders without dispatching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $today = $this->clock->today();

        /** @var array<int, int> $kmByVehicle km attuali per veicolo: un solo calcolo anche con più promemoria */
        $kmByVehicle = [];
        $due = [];
        $rearmed = 0;

        foreach ($this->reminderRepo->findNotificationCandidates() as $r) {
            $currentKm = null;
            if ($r->getDueKm() !== null) {
                $vehicleId = (int) $r->getVehicle()->getId();
                $currentKm = $kmByVehicle[$vehicleId] ??= $this->vehicleStats->currentKm($r->getVehicle());
            }

            $urgency = $r->urgency($currentKm, $today);

            // I km possono scendere (refuso corretto): se il livello calcolato è sotto quello già
            // notificato lo si abbassa, altrimenti il vero superamento della soglia non notificherebbe
            // mai. Si abbassa al livello calcolato (non a null) per non rimandare un "in scadenza" già dato.
            $notified = $r->getNotifiedUrgency();
            if ($currentKm !== null && $notified !== null && $urgency->rank() < $notified->rank()) {
                if (!$dryRun && $this->reminderRepo->rearmNotification((int) $r->getId(), $notified, $urgency === ReminderUrgency::OK ? null : $urgency)) {
                    ++$rearmed;
                }
                continue; // a un livello più basso di quello già notificato non c'è nulla da inviare
            }

            if ($r->needsNotification($urgency)) {
                $due[] = [$r, $urgency];
            }
        }

        if ($rearmed > 0) {
            $io->writeln(sprintf('%d promemoria a km riarmati (km scesi sotto il livello già notificato).', $rearmed));
        }

        if ($due === []) {
            $io->info('Nessun promemoria da notificare.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('%d promemoria da notificare', count($due)));

        foreach ($due as [$r, $urgency]) {
            $io->writeln(sprintf(
                '  [%d] %s — %s (%s, scadenza: %s)',
                $r->getId(),
                $r->getVehicle()->getName(),
                $r->getDescription(),
                $urgency->value,
                implode(' / ', array_filter([
                    $r->getDueDate()?->format('Y-m-d'),
                    $r->getDueKm() !== null ? $r->getDueKm().' km' : null,
                ])),
            ));

            if (!$dryRun) {
                $this->bus->dispatch(new SendReminderNotificationMessage((int) $r->getId()));
            }
        }

        if ($dryRun) {
            $io->warning('Dry-run: nessun messaggio accodato.');
        } else {
            $io->success(sprintf('%d messaggi accodati su transport async', count($due)));
        }

        return Command::SUCCESS;
    }
}
