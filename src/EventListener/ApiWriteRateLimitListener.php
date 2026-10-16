<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Tetto alle scritture autenticate (POST, PUT, PATCH, DELETE sotto /api): il limiter `api` era definito ma
 * nessuno lo usava, quindi un account poteva martellare qualunque endpoint di scrittura (creazione di
 * record, iscrizioni push, upload) senza freni. Le letture non si limitano: l'app le fa a raffica per
 * disegnare una pagina. Le rotte pubbliche (login, registrazione...) hanno i propri limiter nei controller.
 *
 * Gira dopo il firewall (priorità 8), quando il token JWT è già stato letto, e chiave per utente (non per IP:
 * chi sta dietro lo stesso NAT non si blocca a vicenda).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class ApiWriteRateLimitListener
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly RateLimiterFactoryInterface $apiLimiter,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()
            || !in_array($request->getMethod(), self::WRITE_METHODS, true)
            || !str_starts_with($request->getPathInfo(), '/api/')
        ) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return; // anonimo: lo gestiscono i limiter delle singole rotte pubbliche
        }

        $limit = $this->apiLimiter->create('user_'.$user->getId())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        $event->setResponse(new JsonResponse(
            ['type' => 'about:blank', 'title' => 'http.429', 'status' => 429],
            429,
            ['Retry-After' => (string) $retryAfter],
        ));
    }
}
