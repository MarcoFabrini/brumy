<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Service\LogRedactor;
use App\Entity\PushSubscription;
use App\Repository\PushSettingsRepository;
use App\Service\SecretCipher;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

/**
 * Invio Web Push a basso livello. VAPID si configura solo da UI (Impostazioni
 * → Notifiche, owner) — nessun fallback via env. Riletta a ogni invio: nessuna
 * cache statica, così anche il worker long-running vede subito le modifiche.
 *
 * Usato da {@see WebPushNotifier} (dispatcher, solo prod) e dall'endpoint
 * "invia notifica di prova" (tutti gli env).
 */
final class WebPushSender
{
    /**
     * Per quanto il push service tiene il messaggio se il dispositivo è irraggiungibile (spento,
     * senza rete): 24 ore. Con pochi minuti la scadenza delle 07:00 andava persa per chi ha il
     * telefono spento o in modalità aereo a quell'ora; dopo un giorno la notizia è superata.
     */
    public const TTL_SECONDS = 86400;

    /**
     * Opzioni del client HTTP (Guzzle): niente redirect. Un push service legittimo risponde 201/4xx, mai 3xx;
     * seguirli permetterebbe a un endpoint "ammesso" di rimbalzare la POST verso un host interno.
     */
    private const CLIENT_OPTIONS = ['allow_redirects' => false, 'connect_timeout' => 5];

    /** Tetto per singola richiesta: il default della libreria (30 s) bloccherebbe a lungo l'unico worker. */
    private const REQUEST_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PushSettingsRepository $settingsRepo,
        private readonly SecretCipher $cipher,
    ) {
    }

    /** Public key attiva da esporre al frontend (DB se enabled+completa). '' se non configurata. */
    public function activePublicKey(): string
    {
        return $this->resolveVapid()['publicKey'] ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->resolveVapid() !== null;
    }

    /** Da dove viene la config VAPID davvero usata per l'invio: 'db' | 'none'. */
    public function activeSource(): string
    {
        return $this->resolveVapid()['source'] ?? 'none';
    }

    public function send(PushSubscription $subscription, PushPayload $payload): PushDeliveryResult
    {
        $webPush = $this->buildClient();
        if ($webPush === null) {
            return PushDeliveryResult::failed('vapid_not_configured');
        }

        $endpoint = $subscription->getEndpoint();
        $p256dh = $subscription->getP256dh();
        $auth = $subscription->getAuthSecret();
        if ($endpoint === null || $p256dh === null || $auth === null) {
            return PushDeliveryResult::failed('missing_web_push_fields');
        }

        // Difesa in profondità: subscription salvate prima della lista chiusa (o inserite direttamente
        // nel DB) non devono far partire richieste verso host arbitrari. Non valida per sempre: da cancellare.
        if (!PushEndpointPolicy::isAllowed($endpoint)) {
            return PushDeliveryResult::failed('endpoint_not_allowed', gone: true);
        }

        try {
            $subObj = Subscription::create([
                'endpoint' => $endpoint,
                'publicKey' => $p256dh,
                'authToken' => $auth,
            ]);
        } catch (\Throwable $e) {
            return PushDeliveryResult::failed('invalid_subscription: '.$e->getMessage());
        }

        $body = json_encode(array_filter([
            'title' => $payload->title,
            'body' => $payload->body,
            'data' => $payload->data,
            'url' => $payload->url,
        ], static fn ($v) => $v !== null), JSON_THROW_ON_ERROR);

        try {
            $report = $webPush->sendOneNotification($subObj, $body);
        } catch (\Throwable $e) {
            $this->logger->error('WebPush transport error', ['error' => LogRedactor::scrub($e->getMessage())]);
            return PushDeliveryResult::failed('transport: '.LogRedactor::scrub($e->getMessage()));
        }

        if ($report->isSuccess()) {
            return PushDeliveryResult::ok();
        }

        $gone = $report->isSubscriptionExpired();
        // Il motivo del rifiuto (messaggio di Guzzle) contiene l'URL intero dell'endpoint: va ripulito come gli altri.
        $reason = LogRedactor::scrub($report->getReason() ?: 'unknown');
        $this->logger->warning('WebPush delivery failed', [
            // Solo l'host: l'URL intero è il capability token del dispositivo.
            'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
            'gone' => $gone,
            'reason' => $reason,
        ]);

        return PushDeliveryResult::failed($reason, gone: $gone);
    }

    /** Client già configurato con VAPID e TTL (null se VAPID non è configurato). Pubblico per poterlo verificare nei test. */
    public function buildClient(): ?WebPush
    {
        $vapid = $this->resolveVapid();
        if ($vapid === null) {
            return null;
        }

        try {
            return new WebPush(['VAPID' => $vapid], ['TTL' => self::TTL_SECONDS], timeout: self::REQUEST_TIMEOUT_SECONDS, clientOptions: self::CLIENT_OPTIONS);
        } catch (\Throwable $e) {
            $this->logger->error('WebPush init failed', ['error' => LogRedactor::scrub($e->getMessage())]);
            return null;
        }
    }

    /**
     * @return array{source: string, subject: string, publicKey: string, privateKey: string}|null
     */
    private function resolveVapid(): ?array
    {
        $settings = $this->settingsRepo->get();
        if ($settings === null || !$settings->isEnabled() || !$settings->isComplete()) {
            return null;
        }

        try {
            $privateKey = $this->cipher->decrypt((string) $settings->getPrivateKeyCipher());
        } catch (\RuntimeException $e) {
            $this->logger->error('PushSettings private key decrypt failed', ['error' => $e->getMessage()]);

            return null;
        }

        return [
            'source' => 'db',
            'subject' => $settings->getSubject() !== '' ? $settings->getSubject() : 'mailto:admin@example.com',
            'publicKey' => $settings->getPublicKey(),
            'privateKey' => $privateKey,
        ];
    }
}
