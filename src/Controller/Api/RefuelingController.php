<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\RefuelingRequest;
use App\Entity\Refueling;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\FuelType;
use App\Enum\UserValueKind;
use App\Repository\RefuelingRepository;
use App\Repository\VehicleRepository;
use App\Security\Voter\VehicleVoter;
use App\Service\ActiveOrganizationResolver;
use App\Service\AttachmentCleaner;
use App\Service\FuelPrices\FuelPrices;
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

#[OA\Tag(name: 'Refueling')]
#[Route('/api/refuelings', name: 'api_refuelings_')]
#[IsGranted('ROLE_USER')]
final class RefuelingController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefuelingRepository $repo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly ActiveOrganizationResolver $orgResolver,
        private readonly AttachmentCleaner $attachmentCleaner,
        private readonly UserValues $values,
        private readonly FuelPrices $fuelPrices,
    ) {
    }

    #[OA\Get(
        summary: 'List refuelings for a vehicle (paginated)',
        parameters: [
            new OA\Parameter(name: 'vehicleId', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Refueling::class, groups: ['refueling:list', 'vehicle:nested'])))),
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

        return $this->json($this->repo->findByVehiclePaginated($vehicle, $page, $limit), context: ['groups' => ['refueling:list', 'vehicle:nested']]);
    }

    #[OA\Get(
        summary: 'Refueling detail',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Refueling', content: new OA\JsonContent(ref: new Model(type: Refueling::class, groups: ['refueling:read', 'vehicle:nested', 'user_value:nested'])))],
    )]
    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $r = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $r);
        return $this->json($r, context: ['groups' => ['refueling:read', 'vehicle:nested', 'user_value:nested']]);
    }

    #[OA\Post(
        summary: 'Create refueling (total_cost computed by DB)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RefuelingRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Created with computed totalCost', content: new OA\JsonContent(ref: new Model(type: Refueling::class, groups: ['refueling:read', 'vehicle:nested', 'user_value:nested']))),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] RefuelingRequest $payload): JsonResponse
    {
        $vehicle = $this->vehicleRepo->findOneInOrganization($payload->vehicleId, $this->orgResolver->resolve());
        if (!$vehicle) {
            throw $this->createNotFoundException('vehicle.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $vehicle);
        /** @var User $user */
        $user = $this->getUser();

        if (($error = $this->unsupportedFuelType($vehicle, $payload->fuelType)) !== null) {
            return $error;
        }

        $r = (new Refueling())
            ->setOrganization($vehicle->getOrganization())
            ->setVehicle($vehicle)
            ->setRefueledAt(new \DateTimeImmutable($payload->refueledAt))
            ->setKm($payload->km)
            ->setLiters($payload->liters)
            ->setPricePerLiter($payload->pricePerLiter)
            ->setFuelType($payload->fuelType)
            ->setFullTank($payload->fullTank)
            ->setStation($this->values->resolve($vehicle, $user, UserValueKind::STATION, $payload->station))
            ->setDescription(self::blankToNull($payload->description))
            ->setNotes($payload->notes);

        $this->linkFuelStation($r, $payload->fuelStationId);
        $this->em->persist($r);
        $this->em->flush();

        // Refresh per popolare total_cost calcolato dal DB
        $this->em->refresh($r);

        return $this->json($r, 201, context: ['groups' => ['refueling:read', 'vehicle:nested', 'user_value:nested']]);
    }

    #[OA\Put(
        summary: 'Update refueling',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RefuelingRequest::class))),
        responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: new Model(type: Refueling::class, groups: ['refueling:read', 'vehicle:nested', 'user_value:nested'])))],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] RefuelingRequest $payload): JsonResponse
    {
        $r = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $r);
        $vehicle = $r->getVehicle();
        /** @var User $user */
        $user = $this->getUser();

        if (($error = $this->unsupportedFuelType($vehicle, $payload->fuelType)) !== null) {
            return $error;
        }

        $r
            ->setRefueledAt(new \DateTimeImmutable($payload->refueledAt))
            ->setKm($payload->km)
            ->setLiters($payload->liters)
            ->setPricePerLiter($payload->pricePerLiter)
            ->setFuelType($payload->fuelType)
            ->setFullTank($payload->fullTank)
            ->setStation($this->values->resolve($vehicle, $user, UserValueKind::STATION, $payload->station, $r->getStation()))
            ->setDescription(self::blankToNull($payload->description))
            ->setNotes($payload->notes);
        $this->linkFuelStation($r, $payload->fuelStationId);

        $this->em->flush();
        $this->em->refresh($r);

        return $this->json($r, context: ['groups' => ['refueling:read', 'vehicle:nested', 'user_value:nested']]);
    }

    #[OA\Delete(
        summary: 'Delete refueling',
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

    /**
     * Distributore scelto dai vicini o dalla ricerca: la sua voce nell'elenco si collega a quello ufficiale, così la
     * prossima volta il prezzo si propone anche da casa. Un id sconosciuto (dati riscaricati nel frattempo) si ignora.
     */
    private function linkFuelStation(Refueling $refueling, ?int $fuelStationId): void
    {
        $station = $refueling->getStation();
        if ($fuelStationId !== null && $station !== null && $this->fuelPrices->isEnabled()) {
            $this->fuelPrices->link($station, $fuelStationId);
        }
    }

    /**
     * Un rifornimento con un carburante che il veicolo non usa verrebbe contato nei costi ma non
     * comparirebbe in nessun consumo (le statistiche iterano solo i carburanti del veicolo).
     */
    private function unsupportedFuelType(Vehicle $vehicle, FuelType $fuelType): ?JsonResponse
    {
        if (in_array($fuelType, $vehicle->getAllFuelTypes(), true)) {
            return null;
        }

        return $this->fieldProblem('fuelType', 'refueling.fuel_type_not_supported');
    }

    private function mustFind(int $id): Refueling
    {
        $r = $this->repo->findOneInOrganization($id, $this->orgResolver->resolve());
        if (!$r) {
            throw $this->createNotFoundException('refueling.not_found');
        }
        return $r;
    }


    private static function blankToNull(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
