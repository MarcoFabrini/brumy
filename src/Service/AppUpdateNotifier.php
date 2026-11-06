<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use App\Service\Push\PushDispatcher;
use App\Service\Push\PushPayload;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Notifica push all'amministratore dell'istanza quando su GitHub esce una versione più nuova di quella installata
 * ({@see AppUpdates}): una sola per versione, ricordata sull'utente, così il controllo può girare ogni ora senza
 * ripeterla. Gli altri utenti non la ricevono: aggiornare si può solo sul server.
 */
final class AppUpdateNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PushDispatcher $push,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Avvisa l'amministratore della versione `$version`, se non l'ha già fatto. Se nessun suo dispositivo riceve la
     * notifica (notifiche spente, push non configurato) non la segna: arriva al primo controllo dopo che le accende.
     *
     * @return bool true se la notifica è partita
     */
    public function notifyAdmin(string $version): bool
    {
        $admin = $this->users->findInstanceAdmin();
        if ($admin === null || $admin->getUpdateNotifiedVersion() === $version) {
            return false;
        }

        $english = $admin->getLocale() === 'en';
        try {
            $sent = $this->push->notifyUser($admin, new PushPayload(
                title: $english ? sprintf('Brumy %s is available', $version) : sprintf('Disponibile Brumy %s', $version),
                body: $english
                    ? 'A new version has been published on GitHub: update the server to install it.'
                    : 'È uscita una nuova versione su GitHub: aggiorna il server per installarla.',
                data: ['type' => 'app_update', 'version' => $version],
                // Il service worker apre solo indirizzi dell'app: il link alle note della versione è nel Profilo
                url: '/settings',
            ));
        } catch (\Throwable $e) {
            $this->logger->error('App update push failed', ['error' => LogRedactor::scrub($e->getMessage())]);

            return false;
        }
        if ($sent === 0) {
            return false;
        }

        $admin->setUpdateNotifiedVersion($version);
        $this->em->flush();

        return true;
    }
}
