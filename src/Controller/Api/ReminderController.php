<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\CompleteReminderRequest;
use App\Dto\Request\ReminderRequest;
use App\Dto\Request\UpdateReminderRequest;
use App\Entity\Reminder;
use App\Entity\User;
use App\Repository\ReminderRepository;
use App\Repository\VehicleRepository;
use App\Security\Voter\VehicleVoter;
use App\Service\ActiveOrganizationResolver;
use App\Service\AppClock;
use App\Service\AttachmentCleaner;
use App\Service\ReminderAlreadyCompletedException;
use App\Service\ReminderCompletion;
use App\Service\ReminderOverview;
use App\Service\Search\ListSearch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[OA\Tag(name: 'Reminder')]
#[Route('/api/reminders', name: 'api_reminders_')]
#[IsGranted('ROLE_USER')]
final class ReminderController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReminderRepository $repo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly ActiveOrganizationResolver $orgResolver,
        private readonly AttachmentCleaner $attachmentCleaner,
        private readonly ReminderCompletion $completion,
        private readonly AppClock $clock,
        private readonly NormalizerInterface $normalizer,
        private readonly ReminderOverview $overview,
    ) {
    }

    #[OA\Get(
        summary: 'List reminders for a vehicle',
        parameters: [
            new OA\Parameter(name: 'vehicleId', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'onlyActive', in: 'query', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(ref: '#/components/parameters/ListSearchQ'),
            new OA\Parameter(ref: '#/components/parameters/ListSearchHint'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Reminder::class, groups: ['reminder:list', 'vehicle:nested'])))),
            new OA\Response(response: 400, description: 'query.vehicle_id_required; http.400 for a malformed q/hint'),
            new OA\Response(response: 403, description: 'No access to the vehicle'),
            new OA\Response(response: 404, description: 'vehicle.not_found'),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $vehicleId = (int) $request->query->get('vehicleId', 0);
        if ($vehicleId <= 0) {
            return $this->problem('query.vehicle_id_required', 400);
        }
        $vehicle = $this->vehicleRepo->findOneInOrganization($vehicleId, $this->orgResolver->resolve());
        if (!$vehicle) {
            throw $this->createNotFoundException('vehicle.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $vehicle);

        $onlyActive = filter_var($request->query->get('onlyActive', 'true'), FILTER_VALIDATE_BOOLEAN);
        $search = ListSearch::fromQuery((string) $request->query->get('q', ''), $request->query->all('hint'));

        return $this->json($this->repo->findByVehicle($vehicle, $onlyActive, $search), context: ['groups' => ['reminder:list', 'vehicle:nested']]);
    }

    #[OA\Get(
        summary: 'Upcoming date-based reminders of the vehicles the caller owns',
        description: 'Only vehicles the caller owns (created by them): not vehicles shared with them, nor, for org owner/admin, the other members\' vehicles.',
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer', default: 30)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Reminder::class, groups: ['reminder:list', 'vehicle:nested']))))],
    )]
    #[Route('/upcoming', name: 'upcoming', methods: ['GET'])]
    public function upcoming(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int) $request->query->get('days', 30)));
        $limit = max(1, min(50, (int) $request->query->get('limit', 10)));

        /** @var User $user */
        $user = $this->getUser();
        $org = $this->orgResolver->resolve();

        // Solo i veicoli propri, anche per owner/admin dell'org: le scadenze altrui (o di un
        // veicolo condiviso in sola lettura) non sono affar suo.
        $reminders = $this->repo->findUpcomingForOwner($org, $user, $days, $limit);
        return $this->json($reminders, context: ['groups' => ['reminder:list', 'vehicle:nested']]);
    }

    #[OA\Get(
        summary: 'All active reminders of the vehicles the caller owns, with their urgency',
        description: 'Date- and km-based reminders of the caller\'s own active vehicles (not shared ones, nor, for org owner/admin, the other members\'), each with `urgency` (ok | soon | overdue, date and km together, like notifications) and the vehicle `currentKm`. Sorted: overdue, soon, ok; then nearest date, then fewest km left.',
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/ListSearchQ'),
            new OA\Parameter(ref: '#/components/parameters/ListSearchHint'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array of reminders (reminder:list + vehicle:nested) with urgency and currentKm'),
            new OA\Response(response: 400, description: 'http.400 for a malformed q/hint'),
        ],
    )]
    #[Route('/overview', name: 'overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $search = ListSearch::fromQuery((string) $request->query->get('q', ''), $request->query->all('hint'));
        $items = $this->overview->forOwner($this->orgResolver->resolve(), $user, $search);

        return $this->json(array_map(fn (array $item): array => [
            ...(array) $this->normalizer->normalize($item['reminder'], 'json', ['groups' => ['reminder:list', 'vehicle:nested']]),
            'urgency' => $item['urgency']->value,
            'currentKm' => $item['currentKm'],
        ], $items));
    }

    #[OA\Get(
        summary: 'Reminder detail',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Reminder', content: new OA\JsonContent(ref: new Model(type: Reminder::class, groups: ['reminder:read', 'vehicle:nested'])))],
    )]
    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $r = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $r);
        return $this->json($r, context: ['groups' => ['reminder:read', 'vehicle:nested']]);
    }

    #[OA\Post(
        summary: 'Create reminder (date-based or km-based)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ReminderRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: new Model(type: Reminder::class, groups: ['reminder:read', 'vehicle:nested']))),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] ReminderRequest $payload): JsonResponse
    {
        $vehicle = $this->vehicleRepo->findOneInOrganization($payload->vehicleId, $this->orgResolver->resolve());
        if (!$vehicle) {
            throw $this->createNotFoundException('vehicle.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $vehicle);

        $r = (new Reminder())
            ->setOrganization($vehicle->getOrganization())
            ->setVehicle($vehicle)
            ->setType($payload->type)
            ->setDescription($payload->description)
            ->setNotes($payload->notes)
            ->setDueDate($payload->dueDate ? new \DateTimeImmutable($payload->dueDate) : null)
            ->setDueKm($payload->dueKm)
            ->setNotifyDaysBefore($payload->notifyDaysBefore)
            ->setNotifyKmBefore($payload->notifyKmBefore)
            ->setRecurrenceMonths($payload->recurrenceMonths)
            ->setRecurrenceKm($payload->recurrenceKm);

        $this->em->persist($r);
        $this->em->flush();
        return $this->json($r, 201, context: ['groups' => ['reminder:read', 'vehicle:nested']]);
    }

    #[OA\Put(
        summary: 'Update reminder',
        description: 'The vehicle of a reminder never changes: a `vehicleId` in the body is ignored.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateReminderRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: new Model(type: Reminder::class, groups: ['reminder:read', 'vehicle:nested']))),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] UpdateReminderRequest $payload): JsonResponse
    {
        $r = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $r);

        $r
            ->setType($payload->type)
            ->setDescription($payload->description)
            ->setNotes($payload->notes)
            ->setDueDate($payload->dueDate ? new \DateTimeImmutable($payload->dueDate) : null)
            ->setDueKm($payload->dueKm)
            ->setNotifyDaysBefore($payload->notifyDaysBefore)
            ->setNotifyKmBefore($payload->notifyKmBefore)
            ->setRecurrenceMonths($payload->recurrenceMonths)
            ->setRecurrenceKm($payload->recurrenceKm);

        $this->em->flush();
        return $this->json($r, context: ['groups' => ['reminder:read', 'vehicle:nested']]);
    }

    #[OA\Post(
        summary: 'Mark reminder as completed, with the actual date and km',
        description: 'The body is optional: `completedOn` defaults to today (instance timezone), `completedKm` to the vehicle\'s current km '
            .'when the reminder recurs by km (null otherwise). A recurring reminder gets a NEW reminder (`next`) whose due date/km '
            .'are computed from the completion: `completedOn` + `recurrenceMonths`, `completedKm` + `recurrenceKm`. '
            .'The completion km do not update the vehicle odometer.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: new Model(type: CompleteReminderRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'Completed; `next` is the follow-up reminder or null for a one-off', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'completed', ref: new Model(type: Reminder::class, groups: ['reminder:read', 'vehicle:nested'])),
                    new OA\Property(property: 'next', nullable: true, oneOf: [new OA\Schema(ref: new Model(type: Reminder::class, groups: ['reminder:read', 'vehicle:nested']))]),
                ],
                type: 'object',
                required: ['completed', 'next'],
            )),
            new OA\Response(response: 409, description: 'reminder.already_completed'),
            new OA\Response(response: 422, description: 'validation_failed (`completedOn`: common.invalid_date, reminder.completed_on_future; `completedKm`: common.km_too_large)'),
        ],
    )]
    #[Route('/{id}/complete', name: 'complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function complete(int $id, #[MapRequestPayload] ?CompleteReminderRequest $payload = null): JsonResponse
    {
        // Payload nullable: un POST senza body (e senza Content-Type) arriva qui con null e usa i default.
        $r = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $r);

        $completedOn = $payload !== null && $payload->completedOn ? new \DateTimeImmutable($payload->completedOn) : null;
        if ($completedOn !== null && $completedOn > $this->clock->today()) {
            return $this->fieldProblem('completedOn', 'reminder.completed_on_future');
        }

        try {
            $next = $this->completion->complete($r, $completedOn, $payload?->completedKm);
        } catch (ReminderAlreadyCompletedException) {
            return $this->problem('reminder.already_completed', 409);
        }

        return $this->json(['completed' => $r, 'next' => $next], context: ['groups' => ['reminder:read', 'vehicle:nested']]);
    }

    #[OA\Delete(
        summary: 'Delete reminder',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 204, description: 'Deleted')],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $r = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $r);
        $this->attachmentCleaner->removeRecord($r);
        return new JsonResponse(null, 204);
    }

    private function mustFind(int $id): Reminder
    {
        $r = $this->repo->findOneInOrganization($id, $this->orgResolver->resolve());
        if (!$r) {
            throw $this->createNotFoundException('reminder.not_found');
        }
        return $r;
    }
}
