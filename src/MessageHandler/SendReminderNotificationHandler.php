<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Service\LogRedactor;
use App\Entity\Reminder;
use App\Entity\User;
use App\Enum\ReminderUrgency;
use App\Message\SendReminderNotificationMessage;
use App\Repository\OrganizationMemberRepository;
use App\Repository\ReminderRepository;
use App\Service\AppClock;
use App\Service\AppMailer;
use App\Service\MailBuilder;
use App\Service\Push\PushDispatcher;
use App\Service\Push\PushPayload;
use App\Service\VehicleStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler delle notifiche di scadenza.
 *
 * Ricontrolla l'urgenza al momento dell'invio (il promemoria può essere stato completato,
 * modificato o già notificato da un doppione in coda) e manda push (a tutti i device) + email
 * al PROPRIETARIO del veicolo. Owner/admin dell'org (che vedono i veicoli altrui) e chi ha il
 * veicolo in condivisione (sola lettura) non ricevono nulla: ognuno solo i propri veicoli.
 */
#[AsMessageHandler]
final class SendReminderNotificationHandler
{
    public function __construct(
        private readonly ReminderRepository $reminderRepo,
        private readonly OrganizationMemberRepository $memberRepo,
        private readonly VehicleStatsService $vehicleStats,
        private readonly PushDispatcher $pushDispatcher,
        private readonly AppMailer $mailer,
        private readonly MailBuilder $mailBuilder,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly AppClock $clock,
    ) {
    }

    public function __invoke(SendReminderNotificationMessage $message): void
    {
        $reminder = $this->reminderRepo->find($message->reminderId);
        if (!$reminder) {
            $this->logger->warning('SendReminderNotification: reminder gone', ['id' => $message->reminderId]);
            return;
        }
        if ($reminder->isCompleted()) {
            return; // l'utente l'ha completato nel frattempo
        }

        $vehicle = $reminder->getVehicle();
        $currentKm = $reminder->getDueKm() !== null ? $this->vehicleStats->currentKm($vehicle) : null;
        $urgency = $reminder->urgency($currentKm, $this->clock->today());
        if (!$reminder->needsNotification($urgency)) {
            return; // niente in scadenza, o questo livello è già stato notificato
        }

        // Claim atomico PRIMA dell'invio: worker concorrenti o retry dopo un crash non duplicano.
        $previous = $reminder->getNotifiedUrgency();
        $previousNotifiedAt = $reminder->getLastNotifiedAt();
        if (!$this->reminderRepo->claimNotification((int) $reminder->getId(), $urgency, $previous)) {
            return; // un altro worker l'ha già presa in carico
        }
        $this->em->refresh($reminder);

        $payload = $this->buildPushPayload($reminder, $urgency, $currentKm);

        $recipients = 0;
        $delivered = 0;
        try {
            // Una query sola per i destinatari (il proprietario, esclusi gli account anonimizzati).
            foreach ($this->memberRepo->findNotificationRecipients($reminder->getOrganization(), $vehicle) as $member) {
                $user = $member->getUser();
                ++$recipients;
                $delivered += $this->sendPush($user, $payload);
                if ($this->sendEmail($user, $reminder, $urgency, $currentKm)) {
                    ++$delivered;
                }
            }
        } catch (\Throwable $e) {
            // Errore imprevisto a metà invio (query, flush di PushDispatcher...): senza rilascio il
            // retry troverebbe il livello già "notificato" e uscirebbe in silenzio.
            $this->release($reminder, $urgency, $previous, $previousNotifiedAt);

            throw $e;
        }

        // Veicolo senza nessun destinatario idoneo (proprietario anonimizzato, membership non accettata,
        // niente share admin): non si è notificato nessuno, quindi il livello NON deve restare
        // "notificato". Si rilascia il claim senza eccezione (un retry di Messenger non cambierebbe
        // nulla finché manca il proprietario) e si segnala nel log. Il comando giornaliero la
        // ripropone al giro dopo: costa un messaggio e una query per promemoria, accettabile, e
        // appena il veicolo ha di nuovo un proprietario la notifica parte da sola.
        if ($recipients === 0) {
            $this->release($reminder, $urgency, $previous, $previousNotifiedAt);
            $this->logger->warning('Reminder without eligible owner: not notified, will be retried by the next dispatch', [
                'reminder_id' => $reminder->getId(),
                'vehicle_id' => $vehicle->getId(),
            ]);

            return;
        }

        // Nulla è partito (né email né push): rilascia il claim e lascia riprovare a Messenger,
        // altrimenti il livello risulterebbe notificato senza che nessuno l'abbia ricevuto.
        // Se almeno un canale ha consegnato non si ritenta, per non duplicare push ed email.
        if ($delivered === 0) {
            $this->release($reminder, $urgency, $previous, $previousNotifiedAt);

            throw new \RuntimeException(sprintf('Reminder %d: notification delivery failed for all recipients', (int) $reminder->getId()));
        }
    }

    private function release(Reminder $reminder, ReminderUrgency $claimed, ?ReminderUrgency $previous, ?\DateTimeImmutable $previousNotifiedAt): void
    {
        try {
            $this->reminderRepo->releaseNotification((int) $reminder->getId(), $claimed, $previous, $previousNotifiedAt);
            $this->em->refresh($reminder);
        } catch (\Throwable $e) {
            // EntityManager chiuso o DB irraggiungibile: non si può fare di più, l'errore originale resta.
            $this->logger->error('Reminder claim release failed', ['id' => $reminder->getId(), 'error' => $e->getMessage()]);
        }
    }

    private function buildPushPayload(Reminder $r, ReminderUrgency $urgency, ?int $currentKm): PushPayload
    {
        $deadline = [];
        if ($r->getDueDate() !== null) {
            $deadline[] = 'entro il '.$r->getDueDate()->format('d/m/Y');
        }
        if ($r->getDueKm() !== null) {
            $deadline[] = 'entro '.self::km($r->getDueKm()).($currentKm !== null ? ' (ora '.self::km($currentKm).')' : '');
        }

        return new PushPayload(
            title: sprintf($urgency === ReminderUrgency::OVERDUE ? 'Scaduto %s' : 'Scadenza %s', $r->getType()->value),
            body: sprintf('%s — %s (%s)', $r->getVehicle()->getName(), $r->getDescription(), implode(', ', $deadline)),
            data: [
                'reminderId' => (string) $r->getId(),
                'vehicleId' => (string) $r->getVehicle()->getId(),
                'type' => 'reminder_due',
            ],
            url: '/reminders/'.$r->getId(),
        );
    }

    private static function km(int $km): string
    {
        return number_format($km, 0, ',', '.').' km';
    }

    /** @return int consegne push riuscite */
    private function sendPush(User $user, PushPayload $payload): int
    {
        try {
            return $this->pushDispatcher->notifyUser($user, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Reminder push failed', [
                'user_id' => $user->getId(),
                'error' => LogRedactor::scrub($e->getMessage()),
            ]);

            return 0;
        }
    }

    private function sendEmail(User $user, Reminder $r, ReminderUrgency $urgency, ?int $currentKm): bool
    {
        try {
            $email = $this->mailBuilder->reminder($user, $r, $urgency, $currentKm)->to($user->getEmail());
            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Reminder email failed', [
                'user_id' => $user->getId(),
                'error' => LogRedactor::scrub($e->getMessage()),
            ]);

            return false;
        }
    }
}
