<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\VehicleRequest;
use App\Dto\Request\VehicleShareRequest;
use App\Dto\Request\VehicleTransferRequest;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\VehicleShare;
use App\Enum\FuelType;
use App\Enum\ShareRole;
use App\Repository\OrganizationMemberRepository;
use App\Repository\RefuelingRepository;
use App\Repository\VehicleRepository;
use App\Repository\VehicleShareRepository;
use App\Security\Voter\VehicleVoter;
use App\Service\ActiveOrganizationResolver;
use App\Service\AttachmentCleaner;
use App\Service\DashboardChartsService;
use App\Service\MemberNotifier;
use App\Service\VehicleAccessChecker;
use App\Service\VehicleOwnershipTransfer;
use App\Service\VehicleQuota;
use App\Service\VehicleStatsService;
use App\Service\VehicleTransferException;
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
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[OA\Tag(name: 'Vehicle')]
#[Route('/api/vehicles', name: 'api_vehicles_')]
#[IsGranted('ROLE_USER')]
final class VehicleController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VehicleRepository $vehicleRepo,
        private readonly VehicleShareRepository $shareRepo,
        private readonly RefuelingRepository $refuelingRepo,
        private readonly OrganizationMemberRepository $memberRepo,
        private readonly ActiveOrganizationResolver $orgResolver,
        private readonly VehicleAccessChecker $access,
        private readonly SerializerInterface $serializer,
        private readonly NormalizerInterface $normalizer,
        private readonly ValidatorInterface $validator,
        private readonly VehicleStatsService $stats,
        private readonly AttachmentCleaner $attachmentCleaner,
        private readonly DashboardChartsService $charts,
        private readonly VehicleQuota $quota,
        private readonly VehicleOwnershipTransfer $ownershipTransfer,
        private readonly MemberNotifier $notifier,
    ) {
    }

    #[OA\Get(
        summary: 'List accessible vehicles in active organization',
        description: 'Org admin/owner sees all. Plain member sees only vehicles with explicit VehicleShare. Excludes archived unless `?archived=1`, which returns ONLY the archived ones the caller can access (same access rules). Each item carries `ownership` (owned = the caller owns it; shared = read-only share; organization = visible through the org owner/admin role) and the caller\'s `permissions`. Dashboard totals, charts, upcoming reminders and notifications only cover `owned` vehicles.',
        parameters: [
            new OA\Parameter(name: 'archived', in: 'query', required: false, description: '1 = only archived vehicles (default: only active ones)', schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of vehicles',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Vehicle::class, groups: ['vehicle:list']))),
            ),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $org = $this->orgResolver->resolve();

        $isOrgAdmin = $this->access->isOrgAdmin($user, $org);
        $vehicles = $this->vehicleRepo->findAccessibleByUserInOrganization(
            $user,
            $org,
            archivedOnly: $request->query->getBoolean('archived'),
            isOrgAdmin: $isOrgAdmin,
        );
        // Una query per tutte le condivisioni dell'utente, non due per veicolo.
        $shareRoles = $this->shareRepo->findAcceptedRolesByUserInOrganization($user, $org);

        $data = [];
        foreach ($vehicles as $vehicle) {
            $data[] = $this->normalizeWithAccess($vehicle, ['vehicle:list'], $isOrgAdmin, $shareRoles[(int) $vehicle->getId()] ?? null);
        }

        return new JsonResponse($data);
    }

    #[OA\Get(
        summary: 'Vehicle detail',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Vehicle', content: new OA\JsonContent(ref: new Model(type: Vehicle::class, groups: ['vehicle:read']))),
            new OA\Response(response: 403, description: 'No access'),
            new OA\Response(response: 404, description: 'Not found / cross-tenant'),
        ],
    )]
    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $vehicle);

        // Permessi e proprietà dell'utente corrente su QUESTO veicolo: il frontend li usa per
        // mostrare/nascondere modifica, eliminazione e condivisione (il backend applica
        // comunque i voter). Condividere richiede SHARE, come createShare.
        return $this->vehicleResponse($vehicle);
    }

    #[OA\Post(
        summary: 'Create vehicle in active organization',
        description: 'Any accepted org member. The creator becomes the vehicle owner (admin share), org owner/admin included: dashboard totals and notifications only cover owned vehicles. Bi-fuel via secondaryFuelType (must differ from fuelType). When the instance sets VEHICLES_ORG_LIMIT (default 0 = no limit), an organization that already has that many vehicles, archived ones included, gets vehicle.limit_reached.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: VehicleRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: new Model(type: Vehicle::class, groups: ['vehicle:read']))),
            new OA\Response(response: 422, description: 'validation_failed (e.g. vehicle.duplicate_fuel_type), or vehicle.limit_reached when the organization is at its vehicle cap (title = key, no errors[])'),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] VehicleRequest $payload): JsonResponse
    {
        // Ogni membro accettato dell'org attiva può creare veicoli (la membership
        // è già garantita dal resolver).
        $org = $this->orgResolver->resolve();
        /** @var User $user */
        $user = $this->getUser();

        if (($quotaError = $this->quota->violation($org)) !== null) {
            return $this->problem($quotaError, 422);
        }

        $vehicle = (new Vehicle())
            ->setOrganization($org)
            ->setName($payload->name)
            ->setBrand($payload->brand)
            ->setModel($payload->model)
            ->setYear($payload->year)
            ->setType($payload->type)
            ->setFuelType($payload->fuelType)
            ->setSecondaryFuelType($payload->secondaryFuelType)
            ->setLicensePlate($payload->licensePlate)
            ->setVin($payload->vin)
            ->setInitialKm($payload->initialKm)
            ->setPurchasePrice($payload->purchasePrice === '' ? null : $payload->purchasePrice)
            ->setNotes($payload->notes);

        // Valida vincoli a livello entity (es. bi-fuel duplicato)
        $errors = $this->validator->validate($vehicle);
        if (count($errors) > 0) {
            return $this->validationProblem($errors);
        }

        $this->em->persist($vehicle);

        // Chi crea il veicolo ne diventa il proprietario: share `admin` accettato. Vale anche per
        // owner/admin dell'org (che l'accesso l'hanno già via ruolo): la proprietà decide di chi
        // sono totali, grafici e notifiche del veicolo, non solo chi lo vede.
        $ownerShare = (new VehicleShare())
            ->setVehicle($vehicle)
            ->setUser($user)
            ->setRole(ShareRole::ADMIN)
            ->setInvitedBy($user)
            ->setAcceptedAt(new \DateTimeImmutable());
        $vehicle->getShares()->add($ownerShare);
        $this->em->persist($ownerShare);

        $this->em->flush();

        return $this->vehicleResponse($vehicle, 201);
    }

    #[OA\Put(
        summary: 'Update vehicle',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: VehicleRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: new Model(type: Vehicle::class, groups: ['vehicle:read']))),
            new OA\Response(response: 403, description: 'No EDIT permission'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'validation_failed (e.g. vehicle.fuel_type_in_use: a fuel still used by existing refuelings cannot be removed)'),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] VehicleRequest $payload): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $vehicle);

        // Va calcolato prima di applicare il payload: serve il confronto con i carburanti attuali.
        $strandedField = $this->fuelFieldDroppedWhileInUse($vehicle, $payload);
        if ($strandedField !== null) {
            return $this->fieldProblem($strandedField, 'vehicle.fuel_type_in_use');
        }

        $vehicle
            ->setName($payload->name)
            ->setBrand($payload->brand)
            ->setModel($payload->model)
            ->setYear($payload->year)
            ->setType($payload->type)
            ->setFuelType($payload->fuelType)
            ->setSecondaryFuelType($payload->secondaryFuelType)
            ->setLicensePlate($payload->licensePlate)
            ->setVin($payload->vin)
            ->setInitialKm($payload->initialKm)
            ->setPurchasePrice($payload->purchasePrice === '' ? null : $payload->purchasePrice)
            ->setNotes($payload->notes);

        $errors = $this->validator->validate($vehicle);
        if (count($errors) > 0) {
            return $this->validationProblem($errors);
        }

        $this->em->flush();

        return $this->vehicleResponse($vehicle);
    }

    #[OA\Delete(
        summary: 'Delete vehicle (cascades to maintenances, refuelings, expenses, reminders, shares)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'No DELETE permission'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::DELETE, $vehicle);

        $this->attachmentCleaner->removeVehicle($vehicle);

        return new JsonResponse(null, 204);
    }

    /**
     * Statistiche aggregate del veicolo: consumo medio per fuel, totali costi,
     * km percorsi, costo per km. Cache 5 minuti.
     */
    #[OA\Get(
        summary: 'Aggregated vehicle stats (cached 5min)',
        description: 'Per-fuel km/l consumption, total cost, kmDriven, costPerKm.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Stats payload',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'consumption', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'number', nullable: true)),
                    new OA\Property(property: 'totals', type: 'object', properties: [
                        new OA\Property(property: 'cost', type: 'string'),
                        new OA\Property(property: 'refuelings', type: 'integer'),
                        new OA\Property(property: 'maintenances', type: 'integer'),
                        new OA\Property(property: 'expenses', type: 'integer'),
                        new OA\Property(property: 'sections', type: 'object', description: 'Records per section: scheduled, unscheduled, inspection, tax, insurance, expense, component (not deleted)', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
                    ]),
                    new OA\Property(property: 'currentKm', type: 'integer'),
                    new OA\Property(property: 'kmDriven', type: 'integer'),
                    new OA\Property(property: 'costPerKm', type: 'number', nullable: true),
                ]),
            ),
        ],
    )]
    #[Route('/{id}/stats', name: 'stats', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function stats(int $id): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $vehicle);

        return new JsonResponse($this->stats->compute($vehicle));
    }

    /**
     * Serie mensili per i grafici del solo veicolo: stesso contratto di GET /api/dashboard/charts.
     * Le vede chi può vedere il veicolo (proprietario, owner/admin dell'org, condivisione in sola
     * lettura); a differenza della dashboard un veicolo archiviato è incluso.
     */
    #[OA\Get(
        summary: 'Monthly chart series of a single vehicle',
        description: 'Same payload and `months` window as GET /api/dashboard/charts, computed on this vehicle only (fuelTypes = its own fuels). Visible to anyone with VEHICLE_VIEW: owner, org owner/admin, read-only shares. Archived vehicles are included.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(
                name: 'months',
                in: 'query',
                description: 'Window size in months, current month included. Clamped to [1, 24].',
                schema: new OA\Schema(type: 'integer', default: DashboardChartsService::DEFAULT_MONTHS, minimum: 1, maximum: DashboardChartsService::MAX_MONTHS),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Chart series (see GET /api/dashboard/charts)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'from', type: 'string', example: '2025-11'),
                    new OA\Property(property: 'to', type: 'string', example: '2026-10'),
                    new OA\Property(property: 'fuelTypes', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'months', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'spendingByCategory', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'totals', type: 'object'),
                ]),
            ),
            new OA\Response(response: 403, description: 'No VIEW permission'),
            new OA\Response(response: 404, description: 'Not found / cross-tenant'),
        ],
    )]
    #[Route('/{id}/charts', name: 'charts', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function charts(int $id, Request $request): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $vehicle);

        $months = max(1, min(DashboardChartsService::MAX_MONTHS, (int) $request->query->get('months', DashboardChartsService::DEFAULT_MONTHS)));

        return new JsonResponse(DashboardChartsService::toJson($this->charts->computeForVehicle($vehicle, $months)));
    }

    #[OA\Post(
        summary: 'Archive vehicle (soft delete — sets archived_at)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Archived')],
    )]
    #[Route('/{id}/archive', name: 'archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(int $id): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::DELETE, $vehicle);

        $vehicle->setArchivedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->vehicleResponse($vehicle);
    }

    #[OA\Post(
        summary: 'Restore an archived vehicle',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Restored')],
    )]
    #[Route('/{id}/unarchive', name: 'unarchive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unarchive(int $id): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::DELETE, $vehicle);

        $vehicle->setArchivedAt(null);
        $this->em->flush();

        return $this->vehicleResponse($vehicle);
    }

    // ----- Share management -----

    #[OA\Get(
        summary: 'List vehicle shares (user-to-user within organization)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Array of shares', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: VehicleShare::class, groups: ['share:read', 'user:name'])))),
        ],
    )]
    #[Route('/{id}/shares', name: 'shares_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listShares(int $id): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::SHARE, $vehicle);

        // Solo nomi (gruppo user:name): le email dei colleghi le vede solo chi gestisce i membri dell'organizzazione.
        return $this->jsonGroups($this->shareRepo->findByVehicle($vehicle), ['share:read', 'user:name']);
    }

    #[OA\Get(
        summary: 'Org members this vehicle can be shared with (names only, no emails)',
        description: 'Accepted org members with role member, excluding the caller and anyone who already has a share (org owner/admin already see everything). Requires SHARE on the vehicle (owner or org owner/admin).',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of {id, firstName, lastName}, ordered by last then first name',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'firstName', type: 'string'),
                    new OA\Property(property: 'lastName', type: 'string'),
                ], type: 'object')),
            ),
        ],
    )]
    #[Route('/{id}/share-candidates', name: 'share_candidates', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function shareCandidates(int $id): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::SHARE, $vehicle);

        /** @var User $user */
        $user = $this->getUser();
        $alreadyShared = $this->sharedUserIds($vehicle);

        $candidates = [];
        foreach ($this->memberRepo->findShareableMembers($vehicle->getOrganization(), $user) as $member) {
            $candidate = $member->getUser();
            if (isset($alreadyShared[(int) $candidate->getId()])) {
                continue;
            }
            $candidates[] = [
                'id' => $candidate->getId(),
                'firstName' => $candidate->getFirstName(),
                'lastName' => $candidate->getLastName(),
            ];
        }

        return new JsonResponse($candidates);
    }

    #[OA\Post(
        summary: 'Share vehicle read-only with one or more org members (auto-accepted)',
        description: 'Shares are always viewer (read-only). Requires SHARE on the vehicle (owner or org owner/admin). Targets are user ids taken from share-candidates; anything else (unknown id, outsider, org owner/admin, yourself) gets the same 422, so it cannot be used to discover accounts. Already-shared ids are ignored (idempotent). The request is atomic: one invalid id rejects all.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: VehicleShareRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Array of the shares actually created (may be empty)'),
            new OA\Response(response: 422, description: 'share.not_org_member | validation_failed'),
        ],
    )]
    #[Route('/{id}/shares', name: 'shares_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createShare(int $id, #[MapRequestPayload] VehicleShareRequest $payload): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::SHARE, $vehicle);

        /** @var User $inviter */
        $inviter = $this->getUser();

        // Solo membri condivisibili (accettati, ruolo member, non chi condivide):
        // verso chiunque altro lo share sarebbe inerte o senza senso. Stessa
        // risposta per id inesistente e per id di un estraneo: nessuna enumerazione.
        $shareable = [];
        foreach ($this->memberRepo->findShareableMembers($vehicle->getOrganization(), $inviter) as $member) {
            $shareable[(int) $member->getUser()->getId()] = $member->getUser();
        }

        $targets = [];
        foreach (array_unique($payload->userIds) as $userId) {
            if (!isset($shareable[$userId])) {
                return $this->problem('share.not_org_member', 422);
            }
            $targets[] = $shareable[$userId];
        }

        $alreadyShared = $this->sharedUserIds($vehicle);
        $created = [];
        foreach ($targets as $target) {
            if (isset($alreadyShared[(int) $target->getId()])) {
                continue;
            }
            $share = (new VehicleShare())
                ->setVehicle($vehicle)
                ->setUser($target)
                // Le condivisioni sono sempre in sola lettura: modifica e gestione
                // restano al proprietario del veicolo e agli org owner/admin.
                ->setRole(ShareRole::VIEWER)
                ->setInvitedBy($inviter)
                ->setAcceptedAt(new \DateTimeImmutable()); // auto-accept per ora; inviti veri in fase futura
            $this->em->persist($share);
            $created[] = $share;
        }
        $this->em->flush();

        return $this->jsonGroups($created, ['share:read', 'user:name'], 201);
    }

    #[OA\Delete(
        summary: 'Revoke vehicle share',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'shareId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Revoked'),
            new OA\Response(response: 409, description: 'share.cannot_remove_owner'),
        ],
    )]
    #[Route('/{id}/shares/{shareId}', name: 'shares_delete', methods: ['DELETE'], requirements: ['id' => '\d+', 'shareId' => '\d+'])]
    public function deleteShare(int $id, int $shareId): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::SHARE, $vehicle);

        $share = $this->shareRepo->find($shareId);
        if (!$share || $share->getVehicle()->getId() !== $vehicle->getId()) {
            return $this->problem('share.not_found', 404);
        }

        // Lo share `admin` è la proprietà del veicolo (chi l'ha creato): revocarlo
        // lascerebbe il proprietario fuori dal proprio veicolo.
        if ($share->getRole() === ShareRole::ADMIN) {
            return $this->problem('share.cannot_remove_owner', 409);
        }

        $this->em->remove($share);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    // ----- Ownership transfer -----

    #[OA\Get(
        summary: 'Org members the vehicle ownership can be transferred to (names only, no emails)',
        description: 'Accepted, non-anonymized members of the vehicle\'s organization with any role, excluding the current owner (the caller is included when they are not the owner, e.g. an org admin adopting a member\'s vehicle). Requires SHARE on the vehicle (owner or org owner/admin).',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of {id, firstName, lastName}, ordered by last then first name',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'firstName', type: 'string'),
                    new OA\Property(property: 'lastName', type: 'string'),
                ], type: 'object')),
            ),
            new OA\Response(response: 403, description: 'No SHARE permission'),
            new OA\Response(response: 404, description: 'vehicle.not_found (also other organization)'),
        ],
    )]
    #[Route('/{id}/transfer-candidates', name: 'transfer_candidates', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function transferCandidates(int $id): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::SHARE, $vehicle);

        $candidates = [];
        foreach ($this->memberRepo->findTransferCandidates($vehicle->getOrganization(), $this->shareRepo->findOwnerShare($vehicle)?->getUser()) as $member) {
            $candidate = $member->getUser();
            $candidates[] = [
                'id' => $candidate->getId(),
                'firstName' => $candidate->getFirstName(),
                'lastName' => $candidate->getLastName(),
            ];
        }

        return new JsonResponse($candidates);
    }

    #[OA\Post(
        summary: 'Transfer the vehicle ownership to another org member (direct, no acceptance step)',
        description: 'Requires SHARE on the vehicle (current owner, or org owner/admin, who can also adopt an orphan vehicle). Only ownership moves: the recipient gets the single accepted `admin` share, so dashboard totals, charts, upcoming reminders and notifications (history included) follow them; the records of the vehicle are not rewritten. The previous owner keeps a read-only `viewer` share when keepAccess is true (default), otherwise their share is deleted. Other users\' shares are untouched. Reminders not completed get their notification state reset, so the new owner is notified of what is already due. Archived vehicles can be transferred. One transaction with a write lock on the vehicle; the new owner (always) and the previous owner (unless they are the caller) are notified by email and push after commit, best-effort. Same 422 for any invalid recipient (unknown id, other organization, pending, anonymized): accounts cannot be discovered.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: VehicleTransferRequest::class))),
        responses: [
            new OA\Response(response: 204, description: 'Transferred'),
            new OA\Response(response: 403, description: 'No SHARE permission'),
            new OA\Response(response: 404, description: 'vehicle.not_found (also other organization)'),
            new OA\Response(response: 409, description: 'transfer.same_owner | transfer.ownership_changed (the caller lost the right while the request waited for the lock)'),
            new OA\Response(response: 422, description: 'transfer.recipient_invalid | validation_failed'),
        ],
    )]
    #[Route('/{id}/transfer', name: 'transfer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transfer(int $id, #[MapRequestPayload] VehicleTransferRequest $payload): JsonResponse
    {
        $vehicle = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::SHARE, $vehicle);

        /** @var User $actor */
        $actor = $this->getUser();
        try {
            $outcome = $this->ownershipTransfer->transfer($vehicle, $payload->userId, $payload->keepAccess, $actor);
        } catch (VehicleTransferException $e) {
            return $this->problem($e->key, $e->status);
        }

        // Dopo il commit: un avviso che fallisce non deve far fallire un trasferimento già persistito.
        $this->notifier->vehicleTransferred($outcome, $actor);

        return new JsonResponse(null, 204);
    }

    // ----- helpers -----

    /** Dettaglio del veicolo con `ownership` e `permissions` dell'utente corrente. */
    private function vehicleResponse(Vehicle $vehicle, int $status = 200): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $share = $this->shareRepo->findForUserAndVehicle($user, $vehicle);

        return new JsonResponse($this->normalizeWithAccess(
            $vehicle,
            ['vehicle:read'],
            $this->access->isOrgAdmin($user, $vehicle->getOrganization()),
            $share !== null && $share->isAccepted() ? $share->getRole() : null,
        ), $status);
    }

    /**
     * Veicolo serializzato + `ownership` e `permissions` dell'utente corrente, calcolati da dati
     * già caricati (ruolo org + share accettato) con la stessa regola di VehicleAccessChecker.
     *
     * @param list<string> $groups
     * @return array<string, mixed>
     */
    private function normalizeWithAccess(Vehicle $vehicle, array $groups, bool $isOrgAdmin, ?ShareRole $acceptedShareRole): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($vehicle, 'json', ['groups' => $groups]);

        $data['ownership'] = match (true) {
            $acceptedShareRole === ShareRole::ADMIN => 'owned',
            $isOrgAdmin => 'organization',
            default => 'shared',
        };
        $data['permissions'] = VehicleAccessChecker::permissionsForLevel(
            VehicleAccessChecker::resolveLevel($isOrgAdmin, $acceptedShareRole),
        );

        return $data;
    }

    /**
     * @return array<int, true> id degli utenti che hanno già uno share sul veicolo
     */
    private function sharedUserIds(Vehicle $vehicle): array
    {
        $ids = [];
        foreach ($this->shareRepo->findByVehicle($vehicle) as $share) {
            $ids[(int) $share->getUser()->getId()] = true;
        }

        return $ids;
    }

    /**
     * Campo del payload che toglie un carburante ancora usato da dei rifornimenti, o null se nessuno.
     * Senza questo controllo quei rifornimenti restano nei totali di spesa ma spariscono dal consumo,
     * e modificarli darebbe 422 (`refueling.fuel_type_not_supported`).
     */
    private function fuelFieldDroppedWhileInUse(Vehicle $vehicle, VehicleRequest $payload): ?string
    {
        $newFuels = array_filter([$payload->fuelType, $payload->secondaryFuelType]);
        $dropped = array_filter(
            $vehicle->getAllFuelTypes(),
            static fn (FuelType $fuel): bool => !in_array($fuel, $newFuels, true),
        );
        if ($dropped === []) {
            return null;
        }

        $inUse = $this->refuelingRepo->findUsedFuelTypes($vehicle);
        foreach ($dropped as $fuel) {
            if (in_array($fuel, $inUse, true)) {
                return $fuel === $vehicle->getSecondaryFuelType() ? 'secondaryFuelType' : 'fuelType';
            }
        }

        return null;
    }

    private function mustFind(int $id): Vehicle
    {
        $org = $this->orgResolver->resolve();
        $vehicle = $this->vehicleRepo->findOneInOrganization($id, $org);
        if (!$vehicle) {
            throw $this->createNotFoundException('vehicle.not_found');
        }
        return $vehicle;
    }

    private function jsonGroups(mixed $data, array $groups, int $status = 200): JsonResponse
    {
        return new JsonResponse(
            $this->serializer->serialize($data, 'json', ['groups' => $groups]),
            $status,
            [],
            json: true,
        );
    }
}
