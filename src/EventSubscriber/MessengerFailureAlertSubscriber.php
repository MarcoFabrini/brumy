<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\LogRedactor;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * DLQ alerting (#4.3) — logga in ERROR ogni fallimento di un message handler
 * dopo che Messenger ha esaurito i retry (will_retry=false).
 *
 * In prod il Monolog handler stdout JSON manda l'errore al log aggregator,
 * che notifica via integrazione esterna (Loki/Promtail → Alertmanager, ecc).
 *
 * Sospeso per scelta: notifica email diretta da qui (rischio loop se SendEmail
 * fallisce anche lui). Meglio gestire alerting all'edge (log aggregator).
 */
final class MessengerFailureAlertSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => ['onMessageFailed', -100],
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            // Sarà ritentato dal retry strategy — non DLQ ancora
            return;
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $throwable = $event->getThrowable();

        $this->logger->error('messenger.dlq.alert: handler permanently failed', [
            'message_class' => $message::class,
            'exception' => $throwable::class,
            'reason' => LogRedactor::scrub($throwable->getMessage()),
            'receiver' => $event->getReceiverName(),
            // Trace troncato per evitare spam log aggregator
            'trace_head' => LogRedactor::scrub(substr($throwable->getTraceAsString(), 0, 1500)),
        ]);
    }
}
