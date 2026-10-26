<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\FuelType;
use App\Service\ActiveOrganizationResolver;
use App\Service\FuelPrices\FuelPrices;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Prezzi del carburante dall'open data MIMIT (dati pubblici, uguali per tutta l'istanza: nessuno scoping per
 * organizzazione, tranne l'andamento dei distributori seguiti, che viene dall'elenco dell'utente). La posizione dei distributori vicini serve solo alla ricerca e non si salva. Le regole stanno in
 * {@see FuelPrices}; con FUEL_PRICES_ENABLED=0 rispondono tutti 404 fuel_prices.disabled tranne lo stato.
 */
#[OA\Tag(name: 'FuelPrice')]
#[Route('/api/fuel-prices', name: 'api_fuel_prices_')]
#[IsGranted('ROLE_USER')]
final class FuelPriceController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly FuelPrices $fuelPrices,
        private readonly ActiveOrganizationResolver $orgResolver,
    ) {
    }

    #[OA\Get(
        summary: 'Whether fuel prices are on for this instance, and the date of the data',
        description: 'Data older than a day queue a new import from the MIMIT open data.',
        responses: [new OA\Response(response: 200, description: 'Status', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(property: 'extractedOn', type: 'string', format: 'date', nullable: true, description: 'Date of the MIMIT extraction, null before the first import'),
        ], type: 'object'))],
    )]
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $this->fuelPrices->requestImportIfStale();

        return new JsonResponse($this->fuelPrices->status());
    }

    #[OA\Get(
        summary: 'Fuel stations within 10 km of a position, nearest first (max 10)',
        description: 'The position is used for this search only and is not stored.',
        parameters: [
            new OA\Parameter(name: 'lat', in: 'query', required: true, schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'lng', in: 'query', required: true, schema: new OA\Schema(type: 'number')),
            new OA\Parameter(ref: '#/components/parameters/FuelPriceFuel'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stations', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/FuelStationView'))),
            new OA\Response(response: 400, description: 'fuel_prices.invalid_query'),
            new OA\Response(response: 404, description: 'fuel_prices.disabled'),
        ],
    )]
    #[Route('/nearby', name: 'nearby', methods: ['GET'])]
    public function nearby(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $lat = $request->query->get('lat');
        $lng = $request->query->get('lng');
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return $this->problem('fuel_prices.invalid_query', 400);
        }
        $this->fuelPrices->requestImportIfStale();

        return new JsonResponse($this->fuelPrices->nearby((float) $lat, (float) $lng, $this->fuel($request)));
    }

    #[OA\Get(
        summary: 'Fuel stations whose name, brand, address or town contain every word (max 20)',
        parameters: [new OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 100)), new OA\Parameter(ref: '#/components/parameters/FuelPriceFuel')],
        responses: [
            new OA\Response(response: 200, description: 'Stations', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/FuelStationView'))),
            new OA\Response(response: 400, description: 'fuel_prices.invalid_query'),
            new OA\Response(response: 404, description: 'fuel_prices.disabled'),
        ],
    )]
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return $this->problem('fuel_prices.invalid_query', 400);
        }

        return new JsonResponse($this->fuelPrices->search($q, $this->fuel($request)));
    }

    #[OA\Get(
        summary: 'One fuel station with its prices',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(ref: '#/components/parameters/FuelPriceFuel')],
        responses: [
            new OA\Response(response: 200, description: 'Station', content: new OA\JsonContent(ref: '#/components/schemas/FuelStationView')),
            new OA\Response(response: 404, description: 'fuel_prices.station_not_found / fuel_prices.disabled'),
        ],
    )]
    #[Route('/stations/{id}', name: 'station', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function station(int $id, Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $station = $this->fuelPrices->station($id, $this->fuel($request));
        if ($station === null) {
            throw $this->createNotFoundException('fuel_prices.station_not_found');
        }

        return new JsonResponse($station);
    }

    #[OA\Get(
        summary: 'Daily self price of a fuel over the last 30 days, for each station the user follows in the active organization',
        parameters: [new OA\Parameter(name: 'fuel', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['gasoline', 'diesel', 'lpg', 'cng']))],
        responses: [
            new OA\Response(response: 200, description: 'Followed stations, by name', content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'valueId', type: 'integer', description: 'The station entry of the user\'s list'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'fuelStationId', type: 'integer'),
                new OA\Property(property: 'points', type: 'array', description: 'Oldest first; empty when the station has no price for the fuel', items: new OA\Items(properties: [
                    new OA\Property(property: 'day', type: 'string', format: 'date'),
                    new OA\Property(property: 'price', type: 'string', example: '1.729'),
                ], type: 'object')),
            ], type: 'object'))),
            new OA\Response(response: 400, description: 'fuel_prices.invalid_query'),
            new OA\Response(response: 404, description: 'fuel_prices.disabled'),
        ],
    )]
    #[Route('/followed', name: 'followed', methods: ['GET'])]
    public function followed(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $fuel = $this->fuel($request);
        if ($fuel === null || FuelPrices::priceFuel($fuel) !== $fuel) {
            return $this->problem('fuel_prices.invalid_query', 400);
        }
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse($this->fuelPrices->followedTrend($user, $this->orgResolver->resolve(), $fuel));
    }

    private function ensureEnabled(): void
    {
        if (!$this->fuelPrices->isEnabled()) {
            throw $this->createNotFoundException('fuel_prices.disabled');
        }
    }

    /** Carburante sconosciuto o assente: i distributori arrivano comunque, senza prezzi. */
    private function fuel(Request $request): ?FuelType
    {
        return FuelType::tryFrom((string) $request->query->get('fuel', ''));
    }
}
