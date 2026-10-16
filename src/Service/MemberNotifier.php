<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Service\Push\PushDispatcher;
use App\Service\Push\PushPayload;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;

/**
 * Avvisi a un membro per azioni di altri sulla sua posizione (cambio di ruolo, in seguito anche il
 * passaggio di proprietà di un veicolo, vedi {@see self::vehicleTransferred()}): email + push, nella lingua del destinatario.
 *
 * Sincrono e best-effort, da chiamare DOPO il commit: un SMTP o un servizio push giù non deve far
 * fallire un'azione già persistita, quindi ogni canale ha il suo try/catch e l'errore va nel log
 * (come l'invio dell'invito). Niente messaggio Messenger: azione autenticata, un solo destinatario.
 */
final class MemberNotifier
{
    public function __construct(
        private readonly MailBuilder $mailBuilder,
        private readonly AppMailer $mailer,
        private readonly PushDispatcher $pushDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Il ruolo di $member (già salvato) è stato cambiato da $actor. Nessun avviso a chi si cambia da solo
     * né agli account anonimizzati; il chiamante non invoca questo metodo per un no-op.
     */
    public function roleChanged(OrganizationMember $member, User $actor): void
    {
        $recipient = $member->getUser();
        if ($recipient->getId() === $actor->getId() || $recipient->isAnonymized()) {
            return;
        }

        $locale = $recipient->getLocale() === 'en' ? 'en' : 'it';
        $orgName = $member->getOrganization()->getName();
        $role = MailBuilder::roleLabel($member->getRole(), $locale);
        $actorName = trim($actor->getFirstName().' '.$actor->getLastName());

        $payload = $locale === 'en'
            ? new PushPayload(
                title: 'Your role has changed',
                body: sprintf('%s changed your role in "%s": you are now %s.', $actorName, $orgName, $role),
                data: ['type' => 'org_role_changed', 'organizationId' => (string) $member->getOrganization()->getId()],
                url: '/settings',
            )
            : new PushPayload(
                title: 'Il tuo ruolo è cambiato',
                body: sprintf('%s ha cambiato il tuo ruolo in "%s": ora sei %s.', $actorName, $orgName, $role),
                data: ['type' => 'org_role_changed', 'organizationId' => (string) $member->getOrganization()->getId()],
                url: '/settings',
            );

        $this->deliver($recipient, fn (): Email => $this->mailBuilder->roleChanged($member, $actor), $payload, 'org_role_changed');
    }

    /**
     * Il trasferimento di $outcome è stato committato da $actor: avvisa il nuovo proprietario (sempre, anche
     * se è lui ad aver agito) e il precedente (salvo che sia $actor o un account anonimizzato).
     */
    public function vehicleTransferred(VehicleTransferOutcome $outcome, User $actor): void
    {
        $vehicle = $outcome->vehicle;
        $newOwner = $outcome->newOwner;
        $previous = $outcome->previousOwner;

        $this->deliver(
            $newOwner,
            fn (): Email => $this->mailBuilder->vehicleReceived($newOwner, $vehicle, $actor, $previous),
            $this->vehiclePayload($newOwner, $vehicle, 'vehicle_transfer_received', $actor, $newOwner, $outcome->keepAccess),
            'vehicle_transfer_received',
        );

        if ($previous === null || $previous->getId() === $actor->getId() || $previous->isAnonymized()) {
            return;
        }

        $this->deliver(
            $previous,
            fn (): Email => $this->mailBuilder->vehicleHandedOver($previous, $vehicle, $newOwner, $actor, $outcome->keepAccess),
            $this->vehiclePayload($previous, $vehicle, 'vehicle_transfer_handed_over', $actor, $newOwner, $outcome->keepAccess),
            'vehicle_transfer_handed_over',
        );
    }

    /** Push localizzata per il $recipient: stessi contenuti dell'email, in due righe. */
    private function vehiclePayload(User $recipient, Vehicle $vehicle, string $type, User $actor, User $newOwner, bool $keepAccess): PushPayload
    {
        $en = $recipient->getLocale() === 'en';
        $name = $vehicle->getName();
        $actorName = trim($actor->getFirstName().' '.$actor->getLastName());
        $newName = trim($newOwner->getFirstName().' '.$newOwner->getLastName());
        $received = $type === 'vehicle_transfer_received';

        if ($received) {
            $title = $en ? 'You now own a vehicle' : 'Hai un nuovo veicolo';
            $self = $actor->getId() === $recipient->getId();
            $body = match (true) {
                $en && $self => sprintf('You took over "%s". Its reminders, totals and charts, history included, are now yours.', $name),
                $en => sprintf('%s transferred "%s" to you. Its reminders, totals and charts, history included, are now yours.', $actorName, $name),
                $self => sprintf('Hai preso in carico "%s". Promemoria, totali e grafici, storico incluso, ora sono tuoi.', $name),
                default => sprintf('%s ti ha trasferito "%s". Promemoria, totali e grafici, storico incluso, ora sono tuoi.', $actorName, $name),
            };
        } else {
            $title = $en ? 'Vehicle ownership transferred' : 'Proprietà del veicolo trasferita';
            $body = $en
                ? sprintf('"%s" now belongs to %s. %s', $name, $newName, $keepAccess ? 'You keep read-only access.' : 'You no longer have direct access.')
                : sprintf('"%s" ora appartiene a %s. %s', $name, $newName, $keepAccess ? 'Mantieni l\'accesso in sola lettura.' : 'Non hai più un accesso diretto.');
        }

        return new PushPayload(
            title: $title,
            body: $body,
            data: ['type' => $type, 'vehicleId' => (string) $vehicle->getId()],
            url: '/vehicles/'.$vehicle->getId(),
        );
    }

    /**
     * Un canale che fallisce non ferma l'altro né l'azione chiamante: si registra e si prosegue.
     *
     * @param \Closure(): Email $buildEmail costruita dentro il try: anche il rendering del template non deve far fallire la richiesta
     */
    private function deliver(User $recipient, \Closure $buildEmail, PushPayload $payload, string $event): void
    {
        try {
            $this->mailer->send($buildEmail()->to($recipient->getEmail()));
        } catch (\Throwable $e) {
            $this->logger->error('Member notification email failed', [
                'event' => $event,
                'user_id' => $recipient->getId(),
                'error' => LogRedactor::scrub($e->getMessage()),
            ]);
        }

        try {
            $this->pushDispatcher->notifyUser($recipient, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Member notification push failed', [
                'event' => $event,
                'user_id' => $recipient->getId(),
                'error' => LogRedactor::scrub($e->getMessage()),
            ]);
        }
    }
}
