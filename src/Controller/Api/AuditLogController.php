<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\AuditLogRepository;
use App\Security\Voter\OrganizationVoter;
use App\Service\ActiveOrganizationResolver;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit log read-only API per active organization (#3.6).
 * Solo org owner/admin (MANAGE_MEMBERS).
 */
#[OA\Tag(name: 'AuditLog')]
#[Route('/api/audit-logs', name: 'api_audit_logs_')]
#[IsGranted('ROLE_USER')]
final class AuditLogController extends AbstractController
{
    public function __construct(
        private readonly AuditLogRepository $repo,
        private readonly ActiveOrganizationResolver $orgResolver,
    ) {
    }

    #[OA\Get(
        summary: 'List audit logs for active organization (admin only)',
        description: 'Append-only entries (create/update/delete) su entità org-scoped. Solo owner/admin.',
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 500)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit logs (most recent first)'),
            new OA\Response(response: 403, description: 'Org admin required'),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $org = $this->orgResolver->resolve();
        $this->denyAccessUnlessGranted(OrganizationVoter::MANAGE_MEMBERS, $org);

        $limit = min(500, max(1, (int) $request->query->get('limit', '100')));
        $offset = min(10_000_000, max(0, (int) $request->query->get('offset', '0')));

        $logs = $this->repo->findByOrganization($org, $limit, $offset);

        return $this->json($logs, context: ['groups' => ['audit:read']]);
    }
}
