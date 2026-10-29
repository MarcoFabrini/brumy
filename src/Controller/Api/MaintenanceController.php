<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\MaintenanceRequest;
use App\Entity\Maintenance;
use App\Entity\User;
use App\Enum\MaintenanceCategory;
use App\Enum\UserValueKind;
use App\Repository\MaintenanceRepository;
use App\Repository\VehicleRepository;
use App\Security\Voter\VehicleVoter;
use App\Service\ActiveOrganizationResolver;
use App\Service\AttachmentCleaner;
use App\Service\Components;
use App\Service\RecordReminders;
use App\Service\Search\ListSearch;
use App\Service\UserValues;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Maintenance')]
#[Route('/api/maintenances', name: 'api_maintenances_')]
#[IsGranted('ROLE_USER')]
final class MaintenanceController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaintenanceRepository $repo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly ActiveOrganizationResolver $orgResolver,
        private readonly AttachmentCleaner $attachmentCleaner,
        private readonly UserValues $values,
        private readonly Components $components,
        private readonly RecordReminders $recordReminders,
    ) {
    }

    #[OA\Get(
        summary: 'List maintenances for a vehicle (paginated)',
        parameters: [
            new OA\Parameter(name: 'vehicleId', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'category', in: 'query', description: 'Section: scheduled, unscheduled or inspection; omitted = all', schema: new OA\Schema(type: 'string', enum: ['scheduled', 'unscheduled', 'inspection'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
            new OA\Parameter(ref: '#/components/parameters/ListSearchQ'),
            new OA\Parameter(ref: '#/components/parameters/ListSearchHint'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Maintenance::class, groups: ['maintenance:list', 'vehicle:nested'])))),
            new OA\Response(response: 400, description: 'query.vehicle_id_required; http.400 for a malformed q/hint'),
            new OA\Response(response: 403, description: 'No access to the vehicle'),
            new OA\Response(response: 404, description: 'Vehicle not found'),
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

        $page = min(100_000, max(1, (int) $request->query->get('page', 1)));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $category = MaintenanceCategory::tryFrom((string) $request->query->get('category', ''));
        $search = ListSearch::fromQuery((string) $request->query->get('q', ''), $request->query->all('hint'));

        $items = $this->repo->findByVehiclePaginated($vehicle, $page, $limit, $category, $search);
        return $this->json($items, context: ['groups' => ['maintenance:list', 'vehicle:nested']]);
    }

    #[OA\Get(
        summary: 'Maintenance detail',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Maintenance', content: new OA\JsonContent(ref: new Model(type: Maintenance::class, groups: ['maintenance:read', 'vehicle:nested', 'user_value:nested', 'component:nested']))),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $m = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $m);

        return $this->json($m, context: ['groups' => ['maintenance:read', 'vehicle:nested', 'user_value:nested', 'component:nested']]);
    }

    #[OA\Post(
        summary: 'Create maintenance entry for a vehicle',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MaintenanceRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: new Model(type: Maintenance::class, groups: ['maintenance:read', 'vehicle:nested', 'user_value:nested', 'component:nested']))),
            new OA\Response(response: 404, description: 'Vehicle not found'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] MaintenanceRequest $payload): JsonResponse
    {
        $vehicle = $this->vehicleRepo->findOneInOrganization($payload->vehicleId, $this->orgResolver->resolve());
        if (!$vehicle) {
            throw $this->createNotFoundException('vehicle.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $vehicle);
        /** @var User $user */
        $user = $this->getUser();

        $m = (new Maintenance())
            ->setOrganization($vehicle->getOrganization())
            ->setVehicle($vehicle)
            ->setPerformedAt(new \DateTimeImmutable($payload->performedAt))
            ->setKm($payload->km)
            ->classify($payload->type, $payload->category)
            ->setDescription($payload->description)
            ->setCost($payload->cost)
            ->setNotes($payload->notes)
            ->setWorkshop($this->values->resolve($vehicle, $user, UserValueKind::WORKSHOP, $payload->workshop))
            ->setShop($this->values->resolve($vehicle, $user, UserValueKind::SHOP, $payload->shop))
            ->setComponents($this->components->resolve($vehicle, $payload->components));

        $this->em->persist($m);
        $this->em->flush();
        if ($payload->nextDueDate !== null && $payload->nextDueDate !== '') {
            $this->recordReminders->forMaintenance($m, new \DateTimeImmutable($payload->nextDueDate));
        }

        return $this->json($m, 201, context: ['groups' => ['maintenance:read', 'vehicle:nested', 'user_value:nested', 'component:nested']]);
    }

    #[OA\Put(
        summary: 'Update maintenance',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MaintenanceRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: new Model(type: Maintenance::class, groups: ['maintenance:read', 'vehicle:nested', 'user_value:nested', 'component:nested']))),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] MaintenanceRequest $payload): JsonResponse
    {
        $m = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $m);
        $vehicle = $m->getVehicle();
        /** @var User $user */
        $user = $this->getUser();

        $m
            ->setPerformedAt(new \DateTimeImmutable($payload->performedAt))
            ->setKm($payload->km)
            ->classify($payload->type, $payload->category)
            ->setDescription($payload->description)
            ->setCost($payload->cost)
            ->setNotes($payload->notes)
            ->setWorkshop($this->values->resolve($vehicle, $user, UserValueKind::WORKSHOP, $payload->workshop, $m->getWorkshop()))
            ->setShop($this->values->resolve($vehicle, $user, UserValueKind::SHOP, $payload->shop, $m->getShop()))
            ->setComponents($this->components->resolve($vehicle, $payload->components, $m->getComponents()));

        $this->em->flush();

        return $this->json($m, context: ['groups' => ['maintenance:read', 'vehicle:nested', 'user_value:nested', 'component:nested']]);
    }

    #[OA\Delete(
        summary: 'Delete maintenance',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 204, description: 'Deleted')],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $m = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $m);

        $this->attachmentCleaner->removeRecord($m);

        return new JsonResponse(null, 204);
    }

    private function mustFind(int $id): Maintenance
    {
        $m = $this->repo->findOneInOrganization($id, $this->orgResolver->resolve());
        if (!$m) {
            throw $this->createNotFoundException('maintenance.not_found');
        }
        return $m;
    }
}
