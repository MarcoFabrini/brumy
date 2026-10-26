<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\UserValueFollowPriceRequest;
use App\Dto\Request\UserValueFuelStationRequest;
use App\Dto\Request\UserValueMergeRequest;
use App\Dto\Request\UserValueNameRequest;
use App\Entity\User;
use App\Entity\UserValue;
use App\Enum\UserValueKind;
use App\Repository\UserValueRepository;
use App\Repository\VehicleRepository;
use App\Security\Voter\VehicleVoter;
use App\Service\ActiveOrganizationResolver;
use App\Service\FuelPrices\FuelPrices;
use App\Service\UserValueNameTakenException;
use App\Service\UserValues;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Voci ricorrenti private (distributori, officine, categorie di spesa): suggerimenti per veicolo
 * e gestione della propria lista. Le regole stanno in {@see UserValues}.
 */
#[OA\Tag(name: 'UserValue')]
#[Route('/api', name: 'api_values_')]
#[IsGranted('ROLE_USER')]
final class UserValueController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    private const KIND = 'station|workshop|shop|expense_category|tax_type|insurer';

    public function __construct(
        private readonly UserValueRepository $repo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly ActiveOrganizationResolver $orgResolver,
        private readonly UserValues $values,
        private readonly FuelPrices $fuelPrices,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[OA\Get(
        summary: 'Suggestions for a vehicle: the vehicle owner\'s list, most used first',
        parameters: [
            new OA\Parameter(name: 'vehicleId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['station', 'workshop', 'shop', 'expense_category', 'tax_type', 'insurer'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'starterKey', type: 'string', nullable: true, description: 'Starter entry it comes from (e.g. road_tax), null if created by the user'),
                new OA\Property(property: 'fuelStationId', type: 'integer', nullable: true, description: 'Stations only: linked MIMIT fuel station, for its price'),
            ], type: 'object'))),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'vehicle.not_found'),
        ],
    )]
    #[Route('/vehicles/{vehicleId}/values/{kind}', name: 'suggestions', methods: ['GET'], requirements: ['vehicleId' => '\d+', 'kind' => self::KIND])]
    public function suggestions(int $vehicleId, UserValueKind $kind): JsonResponse
    {
        $org = $this->orgResolver->resolve();
        $vehicle = $this->vehicleRepo->findOneInOrganization($vehicleId, $org);
        if (!$vehicle) {
            throw $this->createNotFoundException('vehicle.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $vehicle);

        $owner = $this->values->listOwner($vehicle, $this->currentUser());
        $this->values->ensureStarters($owner, $org, $kind);

        return new JsonResponse($this->repo->findSuggestions($owner, $org, $kind));
    }

    #[OA\Get(
        summary: 'The authenticated user\'s list with usage counts, by name',
        parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['station', 'workshop', 'shop', 'expense_category', 'tax_type', 'insurer']))],
        responses: [
            new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'usageCount', type: 'integer'),
                new OA\Property(property: 'fuelStationId', type: 'integer', nullable: true),
                new OA\Property(property: 'followsPrice', type: 'boolean', description: 'Stations only: its daily price is kept for the price trend'),
            ], type: 'object'))),
        ],
    )]
    #[Route('/values/{kind}', name: 'list', methods: ['GET'], requirements: ['kind' => self::KIND])]
    public function list(UserValueKind $kind): JsonResponse
    {
        $org = $this->orgResolver->resolve();
        $user = $this->currentUser();
        $this->values->ensureStarters($user, $org, $kind);

        return new JsonResponse($this->repo->findWithUsage($user, $org, $kind));
    }

    #[OA\Post(
        summary: 'Add an entry to the authenticated user\'s list',
        parameters: [new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['station', 'workshop', 'shop', 'expense_category', 'tax_type', 'insurer']))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string', maxLength: 200)], type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'usageCount', type: 'integer'),
                new OA\Property(property: 'fuelStationId', type: 'integer', nullable: true),
                new OA\Property(property: 'followsPrice', type: 'boolean', description: 'Stations only: its daily price is kept for the price trend'),
            ], type: 'object')),
            new OA\Response(response: 422, description: 'validation_failed / user_value.name_taken'),
        ],
    )]
    #[Route('/values/{kind}', name: 'create', methods: ['POST'], requirements: ['kind' => self::KIND])]
    public function create(UserValueKind $kind, #[MapRequestPayload] UserValueNameRequest $payload): JsonResponse
    {
        $org = $this->orgResolver->resolve();
        $user = $this->currentUser();
        // Prima le categorie di partenza: "Pedaggio" in una lista ancora vuota è già un nome usato
        $this->values->ensureStarters($user, $org, $kind);

        if ($this->repo->findOneInList($user, $org, $kind, UserValue::keyOf($payload->name)) !== null) {
            return $this->fieldProblem('name', 'user_value.name_taken');
        }
        $value = $this->values->findOrCreate($user, $org, $kind, $payload->name);

        return new JsonResponse($this->present($value, 0), 201);
    }

    #[OA\Patch(
        summary: 'Rename an entry of the authenticated user\'s list',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string', maxLength: 200)], type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Renamed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'usageCount', type: 'integer'),
                new OA\Property(property: 'fuelStationId', type: 'integer', nullable: true),
                new OA\Property(property: 'followsPrice', type: 'boolean', description: 'Stations only: its daily price is kept for the price trend'),
            ], type: 'object')),
            new OA\Response(response: 404, description: 'user_value.not_found'),
            new OA\Response(response: 422, description: 'validation_failed / user_value.name_taken'),
        ],
    )]
    #[Route('/values/{id}', name: 'rename', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function rename(int $id, #[MapRequestPayload] UserValueNameRequest $payload): JsonResponse
    {
        $value = $this->mustFind($id);

        try {
            $this->values->rename($value, $payload->name);
        } catch (UserValueNameTakenException) {
            return $this->fieldProblem('name', 'user_value.name_taken');
        }

        return new JsonResponse($this->present($value, $this->repo->countUsages($value)));
    }

    #[OA\Post(
        summary: 'Merge an entry into another of the same list and kind (records move, the source is removed)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'intoId', type: 'integer')], type: 'object')),
        responses: [
            new OA\Response(response: 204, description: 'Merged'),
            new OA\Response(response: 404, description: 'user_value.not_found'),
            new OA\Response(response: 422, description: 'validation_failed / user_value.merge_invalid'),
        ],
    )]
    #[Route('/values/{id}/merge', name: 'merge', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function merge(int $id, #[MapRequestPayload] UserValueMergeRequest $payload): JsonResponse
    {
        $from = $this->mustFind($id);
        $into = $this->mustFind($payload->intoId);

        try {
            $this->values->merge($from, $into);
        } catch (\InvalidArgumentException) {
            return $this->fieldProblem('intoId', 'user_value.merge_invalid');
        }

        return new JsonResponse(null, 204);
    }

    #[OA\Patch(
        summary: 'Link a station entry to a MIMIT fuel station (null unlinks), so its price is proposed in refuelings',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'fuelStationId', type: 'integer', nullable: true)], type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Linked', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'usageCount', type: 'integer'),
                new OA\Property(property: 'fuelStationId', type: 'integer', nullable: true),
                new OA\Property(property: 'followsPrice', type: 'boolean', description: 'Stations only: its daily price is kept for the price trend'),
            ], type: 'object')),
            new OA\Response(response: 404, description: 'user_value.not_found'),
            new OA\Response(response: 422, description: 'validation_failed / user_value.not_a_station / fuel_prices.station_not_found'),
        ],
    )]
    #[Route('/values/{id}/fuel-station', name: 'fuel_station', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function linkFuelStation(int $id, #[MapRequestPayload] UserValueFuelStationRequest $payload): JsonResponse
    {
        $value = $this->mustFind($id);
        if ($value->getKind() !== UserValueKind::STATION) {
            return $this->fieldProblem('fuelStationId', 'user_value.not_a_station');
        }
        if (!$this->fuelPrices->link($value, $payload->fuelStationId)) {
            return $this->fieldProblem('fuelStationId', 'fuel_prices.station_not_found');
        }
        $this->em->flush();

        return new JsonResponse($this->present($value, $this->repo->countUsages($value)));
    }

    #[OA\Patch(
        summary: 'Follow (or stop following) the price of the fuel station linked to a station entry',
        description: 'A followed station has its price kept every day, for the price trend charts.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['follow'], properties: [new OA\Property(property: 'follow', type: 'boolean')], type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'usageCount', type: 'integer'),
                new OA\Property(property: 'fuelStationId', type: 'integer', nullable: true),
                new OA\Property(property: 'followsPrice', type: 'boolean'),
            ], type: 'object')),
            new OA\Response(response: 404, description: 'user_value.not_found'),
            new OA\Response(response: 422, description: 'validation_failed / user_value.not_a_station / fuel_prices.not_linked'),
        ],
    )]
    #[Route('/values/{id}/follow-price', name: 'follow_price', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function followPrice(int $id, #[MapRequestPayload] UserValueFollowPriceRequest $payload): JsonResponse
    {
        $value = $this->mustFind($id);
        if ($value->getKind() !== UserValueKind::STATION) {
            return $this->fieldProblem('follow', 'user_value.not_a_station');
        }
        if (!$this->fuelPrices->follow($value, (bool) $payload->follow)) {
            return $this->fieldProblem('follow', 'fuel_prices.not_linked');
        }
        $this->em->flush();

        return new JsonResponse($this->present($value, $this->repo->countUsages($value)));
    }

    #[OA\Delete(
        summary: 'Delete an entry (soft): it leaves the list and the suggestions, records using it keep showing its name',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'user_value.not_found'),
        ],
    )]
    #[Route('/values/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->values->delete($this->mustFind($id));

        return new JsonResponse(null, 204);
    }

    /** Solo le voci (non eliminate) dell'utente autenticato nell'organizzazione attiva: le altre sono "non trovate". */
    private function mustFind(int $id): UserValue
    {
        $value = $this->repo->findOneOwnedBy($id, $this->currentUser(), $this->orgResolver->resolve());
        if (!$value) {
            throw $this->createNotFoundException('user_value.not_found');
        }

        return $value;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** @return array{id: int, name: string, usageCount: int, fuelStationId: int|null, followsPrice: bool} */
    private function present(UserValue $value, int $usageCount): array
    {
        return ['id' => (int) $value->getId(), 'name' => $value->getName(), 'usageCount' => $usageCount, 'fuelStationId' => $value->getFuelStationId(), 'followsPrice' => $value->followsPrice()];
    }
}
