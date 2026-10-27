<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Il logout del firewall risponde 204 (senza sessione, a verifica in due passaggi in corso o a sessione valida) invece
 * del redirect di default. Gira prima del DefaultLogoutListener (64), che imposta il redirect solo se manca una risposta;
 * la chiusura della sessione e la cancellazione del cookie e della serie remember-me restano ai listener di Symfony.
 *
 * La strategia CSRF di questa richiesta non va ricordata nella sessione appena chiusa (il SameOriginCsrfListener la
 * scriverebbe alla risposta): la sessione resta vuota e Symfony cancella il cookie invece di aprirne una nuova.
 */
#[AsEventListener(event: LogoutEvent::class, dispatcher: 'security.event_dispatcher.api', priority: 128)]
final class LogoutResponseListener
{
    public function __invoke(LogoutEvent $event): void
    {
        $event->getRequest()->attributes->remove(ApiCsrfListener::COOKIE_NAME);
        $event->setResponse(new JsonResponse(null, 204));
    }
}
