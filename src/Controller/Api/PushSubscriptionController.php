<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\PushSubscriptionRequest;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Enum\PushPlatform;
use App\Repository\PushSubscriptionRepository;
use App\Security\Voter\PushSubscriptionVoter;
use App\Service\Push\WebPushSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[OA\Tag(name: 'PushSubscription')]
#[Route('/api/push-subscriptions', name: 'api_push_subscriptions_')]
#[IsGranted('ROLE_USER')]
final class PushSubscriptionController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    /** Dispositivi registrabili per utente: ogni riga è una richiesta HTTP in più a ogni notifica. */
    private const MAX_SUBSCRIPTIONS_PER_USER = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushSubscriptionRepository $repo,
        private readonly SerializerInterface $serializer,
        private readonly WebPushSender $pushSender,
    ) {
    }

    #[OA\Get(
        summary: 'Active VAPID public key for the frontend to subscribe with',
        description: 'From instance push_settings (configured via UI). Empty when not configured.',
        responses: [new OA\Response(response: 200, description: '{publicKey}')],
    )]
    #[Route('/vapid-public-key', name: 'vapid_public_key', methods: ['GET'])]
    public function vapidPublicKey(): JsonResponse
    {
        return $this->json(['publicKey' => $this->pushSender->activePublicKey()]);
    }

    #[OA\Get(
        summary: 'List user push subscriptions (all devices)',
        responses: [new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: PushSubscription::class, groups: ['push:list']))))],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return $this->jsonGroups($this->repo->findActiveForUser($user), ['push:list']);
    }

    /**
     * Registra (o aggiorna se già esiste) la subscription Web Push per il device
     * corrente. Upsert per (user, endpoint).
     */
    #[OA\Post(
        summary: 'Upsert push subscription for current device',
        description: 'Web Push: requires endpoint+p256dh+authSecret. Upsert key: (user,endpoint).',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: PushSubscriptionRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Subscription registered', content: new OA\JsonContent(ref: new Model(type: PushSubscription::class, groups: ['push:read']))),
            new OA\Response(response: 400, description: 'push.web_fields_required'),
            new OA\Response(response: 422, description: 'push.too_many_subscriptions'),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] PushSubscriptionRequest $payload): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Tetto per utente: ogni riga è una richiesta HTTP in più a ogni notifica, nell'unico worker.
        if ($payload->endpoint
            && $this->repo->findByWebEndpoint($user, $payload->endpoint) === null
            && $this->repo->countForUser($user) >= self::MAX_SUBSCRIPTIONS_PER_USER
        ) {
            return $this->problem('push.too_many_subscriptions', 422);
        }

        $sub = $this->upsertSubscription($user, $payload);
        $sub
            ->setDeviceLabel($payload->deviceLabel)
            ->setUserAgent($request->headers->get('User-Agent'))
            ->touchLastSeen();

        $this->em->persist($sub);
        $this->em->flush();

        return $this->jsonGroups($sub, ['push:read'], 201);
    }

    /**
     * Il browser conosce solo l'endpoint della propria subscription (non l'id): la rimozione
     * dal device passa da qui, così la riga non resta orfana sul server.
     */
    #[OA\Post(
        summary: 'Unregister the current device subscription by endpoint',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: PushSubscriptionRequest::class))),
        responses: [new OA\Response(response: 204, description: 'Removed (idempotent)')],
    )]
    #[Route('/unsubscribe', name: 'unsubscribe', methods: ['POST'])]
    public function unsubscribe(#[MapRequestPayload] PushSubscriptionRequest $payload): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($payload->endpoint) {
            $existing = $this->repo->findByWebEndpoint($user, $payload->endpoint);
            if ($existing !== null) {
                $this->em->remove($existing);
                $this->em->flush();
            }
        }

        return new JsonResponse(null, 204);
    }

    #[OA\Delete(
        summary: 'Unregister push subscription (user-scoped)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 403, description: 'Not owner'),
            new OA\Response(response: 404, description: 'push.not_found'),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $sub = $this->repo->find($id);
        if (!$sub) {
            throw $this->createNotFoundException('push.not_found');
        }
        $this->denyAccessUnlessGranted(PushSubscriptionVoter::DELETE, $sub);

        $this->em->remove($sub);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    private function upsertSubscription(User $user, PushSubscriptionRequest $payload): PushSubscription
    {
        if (!$payload->endpoint || !$payload->p256dh || !$payload->authSecret) {
            throw $this->createBadRequest('push.web_fields_required');
        }
        $existing = $this->repo->findByWebEndpoint($user, $payload->endpoint);
        $sub = $existing ?? (new PushSubscription())->setUser($user)->setPlatform(PushPlatform::WEB);
        return $sub
            ->setEndpoint($payload->endpoint)
            ->setP256dh($payload->p256dh)
            ->setAuthSecret($payload->authSecret);
    }

    private function jsonGroups(mixed $data, array $groups, int $status = 200): JsonResponse
    {
        return new JsonResponse($this->serializer->serialize($data, 'json', ['groups' => $groups]), $status, [], json: true);
    }

    private function createBadRequest(string $title): \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
    {
        return new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException($title);
    }
}
