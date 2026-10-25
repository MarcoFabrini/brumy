<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\ActiveOrganizationResolver;
use App\Service\DashboardChartsService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Dashboard')]
#[Route('/api/dashboard', name: 'api_dashboard_')]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardChartsService $charts,
        private readonly ActiveOrganizationResolver $orgResolver,
    ) {
    }

    #[OA\Get(
        summary: 'Monthly chart series of the vehicles the caller owns',
        description: 'Only vehicles the caller owns (accepted `admin` share) in the active organization, archived ones excluded: not vehicles shared with them, nor, for org owner/admin, the other members\' vehicles. Every month of the window is present in ascending order, empty months included. Money values are 2-decimal strings; consumption is km/l per fuel type (fill-to-fill, attributed to the month of the closing full tank), null when no interval closes in that month.',
        parameters: [
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
                description: 'Chart series',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'from', type: 'string', example: '2025-11'),
                    new OA\Property(property: 'to', type: 'string', example: '2026-10'),
                    new OA\Property(property: 'fuelTypes', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'months', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'month', type: 'string', example: '2026-10'),
                        new OA\Property(property: 'spending', type: 'object', properties: [
                            new OA\Property(property: 'refuelings', type: 'string', example: '84.58'),
                            new OA\Property(property: 'scheduled', type: 'string', example: '0.00'),
                            new OA\Property(property: 'unscheduled', type: 'string', example: '0.00'),
                            new OA\Property(property: 'inspection', type: 'string', example: '0.00'),
                            new OA\Property(property: 'taxes', type: 'string', example: '0.00'),
                            new OA\Property(property: 'insurance', type: 'string', example: '650.00'),
                            new OA\Property(property: 'expenses', type: 'string', example: '0.00', description: 'Other expenses'),
                            new OA\Property(property: 'total', type: 'string', example: '734.58'),
                        ]),
                        new OA\Property(property: 'kmDriven', type: 'integer'),
                        new OA\Property(property: 'consumption', type: 'object', description: 'Keys = fuelTypes', additionalProperties: new OA\AdditionalProperties(type: 'number', nullable: true)),
                    ])),
                    new OA\Property(property: 'spendingByCategory', type: 'array', description: 'One entry per cost section (`fuel`, `scheduled`, `unscheduled`, `inspection`, `tax`, `insurance`) and `expense:<id>` for each category of the other expenses (recurring charges included); zero entries omitted; amount descending', items: new OA\Items(properties: [
                        new OA\Property(property: 'category', type: 'string', example: 'expense:12'),
                        new OA\Property(property: 'name', type: 'string', nullable: true, description: 'Current name of the expense category; null for the section entries', example: 'Assicurazione'),
                        new OA\Property(property: 'amount', type: 'string', example: '650.00'),
                    ])),
                    new OA\Property(property: 'totals', type: 'object', properties: [
                        new OA\Property(property: 'spending', type: 'string'),
                        new OA\Property(property: 'kmDriven', type: 'integer'),
                    ]),
                ]),
            ),
            new OA\Response(response: 403, description: 'auth.no_active_organization / auth.not_member_of_organization'),
        ],
    )]
    #[Route('/charts', name: 'charts', methods: ['GET'])]
    public function charts(Request $request): JsonResponse
    {
        $months = max(1, min(DashboardChartsService::MAX_MONTHS, (int) $request->query->get('months', DashboardChartsService::DEFAULT_MONTHS)));

        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse(DashboardChartsService::toJson(
            $this->charts->compute($user, $this->orgResolver->resolve(), $months),
        ));
    }
}
