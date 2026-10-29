<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\ExpenseRequest;
use App\Entity\Expense;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\ExpenseSection;
use App\Enum\UserValueKind;
use App\Repository\ExpenseRepository;
use App\Repository\VehicleRepository;
use App\Security\Voter\VehicleVoter;
use App\Service\ActiveOrganizationResolver;
use App\Service\AttachmentCleaner;
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

#[OA\Tag(name: 'Expense')]
#[Route('/api/expenses', name: 'api_expenses_')]
#[IsGranted('ROLE_USER')]
final class ExpenseController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExpenseRepository $repo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly ActiveOrganizationResolver $orgResolver,
        private readonly AttachmentCleaner $attachmentCleaner,
        private readonly UserValues $values,
        private readonly RecordReminders $recordReminders,
    ) {
    }

    #[OA\Get(
        summary: 'List expenses for a vehicle (paginated)',
        parameters: [
            new OA\Parameter(name: 'vehicleId', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'section', in: 'query', description: 'tax, insurance or other; omitted = all', schema: new OA\Schema(type: 'string', enum: ['tax', 'insurance', 'other'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
            new OA\Parameter(ref: '#/components/parameters/ListSearchQ'),
            new OA\Parameter(ref: '#/components/parameters/ListSearchHint'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Expense::class, groups: ['expense:list', 'vehicle:nested', 'user_value:nested'])))),
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

        $page = min(100_000, max(1, (int) $request->query->get('page', 1)));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $section = ExpenseSection::tryFrom((string) $request->query->get('section', ''));
        $search = ListSearch::fromQuery((string) $request->query->get('q', ''), $request->query->all('hint'));

        return $this->json($this->repo->findByVehiclePaginated($vehicle, $page, $limit, $section, $search), context: ['groups' => ['expense:list', 'vehicle:nested', 'user_value:nested']]);
    }

    #[OA\Get(
        summary: 'Expense detail',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Expense', content: new OA\JsonContent(ref: new Model(type: Expense::class, groups: ['expense:read', 'vehicle:nested', 'user_value:nested'])))],
    )]
    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $e = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $e);
        return $this->json($e, context: ['groups' => ['expense:read', 'vehicle:nested', 'user_value:nested']]);
    }

    #[OA\Post(
        summary: 'Create expense entry',
        description: 'section is tax, insurance or other (default). category is a name, required except for insurance: for other it is looked up (ignoring case and spaces) in the expense categories of the vehicle owner, for tax in their tax types, or added there; a legacy slug (toll, road_tax, ...) maps to the matching starter entry. insurer (insurance only, optional) is a name in the owner\'s insurers. Responses carry category and insurer as {id, name} or null. Recurring expense uses recurringPeriod (weekly/monthly/quarterly/semiannual/yearly/biennial). occurredAt is the date of the FIRST charge; recurringUntil (Y-m-d, optional, inclusive, >= occurredAt) is the last possible charge date, null = still running. Every charge already due (date <= today and <= recurringUntil) is counted in total cost, cost per km and charts.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ExpenseRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: new Model(type: Expense::class, groups: ['expense:read', 'vehicle:nested', 'user_value:nested']))),
            new OA\Response(response: 422, description: 'validation_failed (e.g. validation.amount_format)'),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] ExpenseRequest $payload): JsonResponse
    {
        $vehicle = $this->vehicleRepo->findOneInOrganization($payload->vehicleId, $this->orgResolver->resolve());
        if (!$vehicle) {
            throw $this->createNotFoundException('vehicle.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $vehicle);

        $e = (new Expense())
            ->setOrganization($vehicle->getOrganization())
            ->setVehicle($vehicle)
            ->setOccurredAt(new \DateTimeImmutable($payload->occurredAt))
            ->classify(...$this->classification($vehicle, $payload))
            ->setDescription($payload->description)
            ->setAmount($payload->amount)
            ->setRecurring($payload->recurring)
            ->setRecurringPeriod($payload->recurringPeriod)
            ->setRecurringUntil(self::recurringUntil($payload))
            ->setNotes($payload->notes);

        $this->em->persist($e);
        $this->em->flush();
        if ($payload->nextDueDate !== null && $payload->nextDueDate !== '') {
            $this->recordReminders->forExpense($e, new \DateTimeImmutable($payload->nextDueDate));
        }
        return $this->json($e, 201, context: ['groups' => ['expense:read', 'vehicle:nested', 'user_value:nested']]);
    }

    #[OA\Put(
        summary: 'Update expense',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ExpenseRequest::class))),
        responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: new Model(type: Expense::class, groups: ['expense:read', 'vehicle:nested', 'user_value:nested'])))],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] ExpenseRequest $payload): JsonResponse
    {
        $e = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $e);

        $e
            ->setOccurredAt(new \DateTimeImmutable($payload->occurredAt))
            ->classify(...$this->classification($e->getVehicle(), $payload, $e))
            ->setDescription($payload->description)
            ->setAmount($payload->amount)
            ->setRecurring($payload->recurring)
            ->setRecurringPeriod($payload->recurringPeriod)
            ->setRecurringUntil(self::recurringUntil($payload))
            ->setNotes($payload->notes);

        $this->em->flush();
        return $this->json($e, context: ['groups' => ['expense:read', 'vehicle:nested', 'user_value:nested']]);
    }

    #[OA\Delete(
        summary: 'Delete expense',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 204, description: 'Deleted')],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $e = $this->mustFind($id);
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $e);
        $this->attachmentCleaner->removeRecord($e);
        return new JsonResponse(null, 204);
    }

    /** Data di fine della ricorrenza: solo per le spese ricorrenti, vuota (null o stringa vuota) = ancora in corso. */
    private static function recurringUntil(ExpenseRequest $payload): ?\DateTimeImmutable
    {
        if (!$payload->recurring || $payload->recurringUntil === null || $payload->recurringUntil === '') {
            return null;
        }

        return new \DateTimeImmutable($payload->recurringUntil);
    }

    /**
     * Sezione e voci della spesa, dalle liste del proprietario del veicolo: categoria per le altre spese, tipo di
     * tassa per le tasse, compagnia (facoltativa) per l'assicurazione. $current: la spesa in modifica, perché un
     * nome rimasto uguale tenga la sua voce anche se eliminata. Il DTO garantisce il testo dove è obbligatorio.
     *
     * @return array{ExpenseSection, ?UserValue, ?UserValue}
     */
    private function classification(Vehicle $vehicle, ExpenseRequest $payload, ?Expense $current = null): array
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($payload->section === ExpenseSection::INSURANCE) {
            return [$payload->section, null, $this->values->resolve($vehicle, $user, UserValueKind::INSURER, $payload->insurer, $current?->getInsurer())];
        }
        $kind = $payload->section === ExpenseSection::TAX ? UserValueKind::TAX_TYPE : UserValueKind::EXPENSE_CATEGORY;
        $category = $this->values->resolve($vehicle, $user, $kind, $payload->category, $current?->getCategory())
            ?? throw new \LogicException('Categoria vuota: doveva fermarla la validazione del payload.');

        return [$payload->section, $category, null];
    }

    private function mustFind(int $id): Expense
    {
        $e = $this->repo->findOneInOrganization($id, $this->orgResolver->resolve());
        if (!$e) {
            throw $this->createNotFoundException('expense.not_found');
        }
        return $e;
    }
}
