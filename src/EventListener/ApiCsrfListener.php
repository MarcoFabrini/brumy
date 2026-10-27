<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Protezione CSRF delle richieste non sicure (POST, PUT, PATCH, DELETE) sotto /api: la sessione viaggia in un cookie,
 * quindi una pagina di un altro sito non deve poter scrivere a nome dell'utente. La verifica è tutta del
 * `SameOriginCsrfTokenManager` di Symfony (`framework.csrf_protection.stateless_token_ids: [api]`): un token uguale al
 * nome del cookie (`csrf-token`) chiede il solo controllo di origine, cioè `Sec-Fetch-Site: same-origin` oppure
 * `Origin`/`Referer` uguali a schema e host della richiesta. La SPA non manda nulla di suo: lo fa il browser.
 *
 * Symfony non offre un'opzione che applichi il controllo a ogni richiesta (`json_login` non ha `enable_csrf`, il logout
 * e `#[IsCsrfTokenValid]` leggono un token dal corpo o valgono per un'azione): questo listener è solo l'aggancio. Gira
 * dopo il router (32) e prima del firewall (8), così copre anche login, codice della verifica in due passaggi e logout,
 * che il firewall gestisce senza arrivare a un controller.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class ApiCsrfListener
{
    public const TOKEN_ID = 'api';
    /**
     * `framework.csrf_protection.cookie_name` (default): come valore del token chiede al SameOriginCsrfTokenManager il
     * solo controllo di origine; è anche l'attributo di richiesta con cui Symfony ricorda in sessione la strategia usata.
     */
    public const COOKIE_NAME = 'csrf-token';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, self::COOKIE_NAME))) {
            throw new AccessDeniedHttpException('auth.csrf_invalid');
        }
    }
}
