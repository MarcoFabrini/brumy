<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\ComponentRequest;
use App\Entity\Component;
use App\Entity\User;
use App\Entity\UserValue;
use App\Enum\UserValueKind;
use App\Repository\ComponentRepository;
use App\Repository\VehicleRepository;
use App\Security\Voter\VehicleVoter;
use App\Service\ActiveOrganizationResolver;
use App\Service\UserValues;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Componenti (pezzi comprati) di un veicolo. Si creano anche scrivendone il nome in un lavoro
 * ({@see \App\Service\Components}); qui si completano, si modificano e si eliminano.
 */
#[OA\Tag(name: 'Component')]
#[Route('/api/components', name: 'api_components_')]
#[IsGranted('ROLE_USER')]
final class ComponentController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    private const GROUPS = ['component:read', 'vehicle:nested', 'user_value:nested'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComponentRepository $repo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly ActiveOrganizationResolver $orgResolver,
        private readonly NormalizerInterface $normalizer,
        private readonly UserValues $values,
    ) {
    }

    #[OA\Get(
        summary: 'Components of a vehicle, by name',
        parameters: [new OA\Parameter(name: 'vehicleId', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Component::class, groups: ['component:list', 'vehicle:nested', 'user_value:nested'])))),
            new OA\Response(response: 400, description: 'query.vehicle_id_required'),
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

        return new JsonResponse($this->normalizer->normalize($this->repo->findByVehicle($vehicle), 'json', ['groups' => ['component:list', 'vehicle:nested', 'user_value:nested']]));
    }

    #[OA\Get(
        summary: 'Component detail, with the maintenances that used it (most recent first)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Component plus maintenances: [{id, performedAt, category, description}]', content: new OA\JsonContent(ref: new Model(type: Component::class, groups: self::GROUPS))),
            new OA\Response(response: 404, description: 'component.not_found'),
        ],
    )]
    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $c = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $c);

        return $this->present($c);
    }

    #[OA\Post(
        summary: 'Create a component for a vehicle',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ComponentRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: new Model(type: Component::class, groups: self::GROUPS))),
            new OA\Response(response: 404, description: 'vehicle.not_found'),
            new OA\Response(response: 422, description: 'validation_failed / component.name_taken'),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] ComponentRequest $payload): JsonResponse
    {
        $vehicle = $this->vehicleRepo->findOneInOrganization($payload->vehicleId, $this->orgResolver->resolve());
        if (!$vehicle) {
            throw $this->createNotFoundException('vehicle.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $vehicle);

        $name = UserValue::normalizeName($payload->name);
        if ($this->repo->findOneByKey($vehicle, UserValue::keyOf($name)) !== null) {
            return $this->fieldProblem('name', 'component.name_taken');
        }
        $c = new Component($vehicle, $name);
        $this->fill($c, $payload);
        $this->em->persist($c);
        $this->em->flush();

        return $this->present($c, 201);
    }

    #[OA\Put(
        summary: 'Update a component',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ComponentRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: new Model(type: Component::class, groups: self::GROUPS))),
            new OA\Response(response: 404, description: 'component.not_found'),
            new OA\Response(response: 422, description: 'validation_failed / component.name_taken'),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] ComponentRequest $payload): JsonResponse
    {
        $c = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $c);

        $name = UserValue::normalizeName($payload->name);
        $clash = $this->repo->findOneByKey($c->getVehicle(), UserValue::keyOf($name));
        if ($clash !== null && $clash->getId() !== $c->getId()) {
            return $this->fieldProblem('name', 'component.name_taken');
        }
        $c->rename($name);
        $this->fill($c, $payload);
        $this->em->flush();

        return $this->present($c);
    }

    #[OA\Delete(
        summary: 'Delete a component (soft): it leaves the list and the suggestions, maintenances that used it keep showing it (deleted: true)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 404, description: 'component.not_found')],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $c = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $c);

        $c->softDelete();
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /** Campi modificabili (il nome a parte); il negozio dalla lista del proprietario del veicolo. */
    private function fill(Component $c, ComponentRequest $payload): void
    {
        /** @var User $user */
        $user = $this->getUser();
        $blankToNull = static fn (?string $v): ?string => $v === null || trim($v) === '' ? null : trim($v);

        $c->setCode($blankToNull($payload->code))
            ->setSerialNumber($blankToNull($payload->serialNumber))
            ->setShop($this->values->resolve($c->getVehicle(), $user, UserValueKind::SHOP, $payload->shop, $c->getShop()))
            ->setPurchasedAt(($date = $blankToNull($payload->purchasedAt)) !== null ? new \DateTimeImmutable($date) : null)
            ->setPrice($blankToNull($payload->price))
            ->setNotes($blankToNull($payload->notes));
    }

    private function present(Component $c, int $status = 200): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($c, 'json', ['groups' => self::GROUPS]);
        $data['maintenances'] = array_map(static fn ($m): array => [
            'id' => $m->getId(),
            'performedAt' => $m->getPerformedAt()->format('Y-m-d'),
            'category' => $m->getCategory()->value,
            'description' => $m->getDescription(),
        ], $this->repo->findMaintenancesUsing($c));

        return new JsonResponse($data, $status);
    }

    private function mustFind(int $id): Component
    {
        $c = $this->repo->findOneActiveInOrganization($id, $this->orgResolver->resolve());
        if (!$c) {
            throw $this->createNotFoundException('component.not_found');
        }

        return $c;
    }
}
