<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\PushSettingsRequest;
use App\Entity\PushSettings;
use App\Entity\User;
use App\Repository\PushSettingsRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\Push\PushPayload;
use App\Service\Push\WebPushSender;
use App\Service\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Configurazione Web Push (VAPID) di ISTANZA gestita da UI. Self-host familiare:
 * può modificarla solo l'amministratore di istanza (il primo utente registrato,
 * vedi UserRepository::isInstanceAdmin) — NON un generico OWNER di
 * organizzazione: essere OWNER di una org non implica amministrare
 * l'istanza (ogni utente ha la propria org personale), e legare questo
 * endpoint a quel ruolo darebbe a chiunque possieda un'organizzazione il
 * controllo delle chiavi VAPID dell'istanza. La private key non esce mai
 * dall'API (solo `hasKeys`); la public key è esposta (non è segreta).
 */
#[OA\Tag(name: 'Settings')]
#[Route('/api/settings/push', name: 'api_settings_push_')]
#[IsGranted('ROLE_USER')]
final class PushSettingsController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushSettingsRepository $repo,
        private readonly PushSubscriptionRepository $subRepo,
        private readonly UserRepository $userRepo,
        private readonly SecretCipher $cipher,
        private readonly WebPushSender $sender,
    ) {
    }

    #[OA\Get(
        summary: 'Read instance Web Push (VAPID) settings (instance admin only, private key never returned)',
        responses: [
            new OA\Response(response: 200, description: 'Settings + hasKeys'),
            new OA\Response(response: 403, description: 'settings.instance_admin_required'),
        ],
    )]
    #[Route('', name: 'get', methods: ['GET'])]
    public function read(): JsonResponse
    {
        if ($denied = $this->denyUnlessInstanceAdmin()) {
            return $denied;
        }

        return $this->json($this->payload($this->repo->get()));
    }

    #[OA\Put(
        summary: 'Update instance Web Push settings (subject + enabled, instance admin only)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: PushSettingsRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Saved settings'),
            new OA\Response(response: 400, description: 'push.no_keys (enabled without a VAPID keypair)'),
            new OA\Response(response: 403, description: 'settings.instance_admin_required'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('', name: 'update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] PushSettingsRequest $payload): JsonResponse
    {
        if ($denied = $this->denyUnlessInstanceAdmin()) {
            return $denied;
        }

        $settings = $this->repo->get() ?? new PushSettings();

        if ($payload->enabled && !$settings->isComplete()) {
            return $this->problem('push.no_keys', 400);
        }

        $settings
            ->setSubject($payload->subject ?? '')
            ->setEnabled($payload->enabled);

        /** @var User $user */
        $user = $this->getUser();
        $settings->touch($user);

        $this->em->persist($settings);
        $this->em->flush();

        return $this->json($this->payload($settings));
    }

    #[OA\Post(
        summary: 'Generate a fresh VAPID keypair (instance admin only)',
        description: 'Replaces the stored keypair and DELETES all existing push subscriptions '.
            '(the old public key is embedded in each — they can no longer receive). Users must re-subscribe.',
        responses: [
            new OA\Response(response: 200, description: '{publicKey, removedSubscriptions}'),
            new OA\Response(response: 403, description: 'settings.instance_admin_required'),
        ],
    )]
    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(): JsonResponse
    {
        if ($denied = $this->denyUnlessInstanceAdmin()) {
            return $denied;
        }

        $keys = VAPID::createVapidKeys();

        $settings = $this->repo->get() ?? new PushSettings();
        $settings
            ->setPublicKey($keys['publicKey'])
            ->setPrivateKeyCipher($this->cipher->encrypt($keys['privateKey']))
            // Generare una coppia è un'azione esplicita: attiva da subito, niente
            // secondo passaggio "abilita" separato per l'owner.
            ->setEnabled(true);

        if ($settings->getSubject() === '') {
            /** @var User $user */
            $user = $this->getUser();
            $settings->setSubject('mailto:'.$user->getEmail());
        }

        /** @var User $user */
        $user = $this->getUser();
        $settings->touch($user);

        $this->em->persist($settings);
        $this->em->flush();

        // La vecchia public key è incastonata nelle subscription: vanno azzerate.
        $removed = $this->subRepo->deleteAll();

        return $this->json([
            'publicKey' => $settings->getPublicKey(),
            'removedSubscriptions' => $removed,
        ]);
    }

    #[OA\Post(
        summary: 'Send a test push to the current user web subscriptions (instance admin only)',
        responses: [
            new OA\Response(response: 200, description: '{sent}'),
            new OA\Response(response: 400, description: 'push.not_configured | push.no_subscription'),
            new OA\Response(response: 403, description: 'settings.instance_admin_required'),
        ],
    )]
    #[Route('/test', name: 'test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        if ($denied = $this->denyUnlessInstanceAdmin()) {
            return $denied;
        }

        if (!$this->sender->isConfigured()) {
            return $this->problem('push.not_configured', 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $subs = $this->subRepo->findActiveWebForUser($user);
        if (count($subs) === 0) {
            return $this->problem('push.no_subscription', 400);
        }

        $payload = new PushPayload(
            title: 'Brumy',
            body: 'Notifica di prova: le notifiche push funzionano.',
            url: '/settings',
        );

        $sent = 0;
        foreach ($subs as $sub) {
            $result = $this->sender->send($sub, $payload);
            if ($result->success) {
                $sent++;
            } elseif ($result->gone) {
                $this->em->remove($sub);
            }
        }
        $this->em->flush();

        return $this->json(['sent' => $sent]);
    }

    // ----- helpers -----

    private function denyUnlessInstanceAdmin(): ?JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->userRepo->isInstanceAdmin($user)) {
            return $this->problem('settings.instance_admin_required', 403);
        }

        return null;
    }

    /**
     * @return array{publicKey: string, subject: string, enabled: bool, hasKeys: bool, source: string}
     */
    private function payload(?PushSettings $s): array
    {
        return [
            'publicKey' => $s?->getPublicKey() ?? '',
            'subject' => $s?->getSubject() ?? '',
            'enabled' => $s?->isEnabled() ?? false,
            'hasKeys' => $s?->isComplete() ?? false,
            // Da dove viene la config DAVVERO in uso per l'invio ('db'|'none'):
            // può differire da `publicKey` se hai generato ma non ancora abilitato.
            'source' => $this->sender->activeSource(),
        ];
    }
}
