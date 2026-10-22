<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\OrganizationMemberRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Aggiunge al JWT i claim necessari per la business logic e per l'autenticazione:
 * - `active_org_id`: l'organizzazione corrente (la prima accettata dell'utente)
 * - `user_id`: l'identità del token, immutabile (il firewall carica l'utente da qui, non dall'email)
 * - `token_version`: la versione corrente delle sessioni dell'utente; una revoca la incrementa e il token
 *   smette di valere (vedi {@see \App\Security\JwtUserProvider})
 *
 * `user_id` e `token_version` vengono sempre dall'utente, anche se il chiamante li passa nel payload.
 *
 * Lexik dispatcha l'evento usando la stringa `Events::JWT_CREATED`, non il FQN della classe,
 * quindi va registrato il listener con la stringa esplicita.
 */
#[AsEventListener(event: Events::JWT_CREATED)]
final class JwtCreatedListener
{
    public function __construct(
        private readonly OrganizationMemberRepository $memberRepo,
    ) {
    }

    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        $payload['user_id'] = $user->getId();
        $payload['token_version'] = $user->getTokenVersion();

        // Rispetta `active_org_id` se già passato esplicitamente (es. da test o switch-org).
        // Altrimenti default alla prima membership accepted dell'utente.
        if (!isset($payload['active_org_id'])) {
            $memberships = $this->memberRepo->findAllForUser($user);
            if (!empty($memberships)) {
                $payload['active_org_id'] = $memberships[0]->getOrganization()->getId();
            }
        }

        $event->setData($payload);
    }
}
