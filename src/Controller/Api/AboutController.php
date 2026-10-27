<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AppUpdates;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Versione dell'app per il profilo. Solo per utenti collegati (la versione esatta aiuta chi cerca istanze
 * vulnerabili). L'aggiornamento disponibile lo vede solo l'amministratore dell'istanza, l'unico che può installarlo.
 */
#[OA\Tag(name: 'About')]
#[IsGranted('ROLE_USER')]
final class AboutController extends AbstractController
{
    public function __construct(
        private readonly AppUpdates $updates,
        private readonly UserRepository $users,
    ) {
    }

    #[OA\Get(
        summary: 'Installed app version, and the newer release for the instance admin',
        responses: [new OA\Response(response: 200, description: 'Version', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'version', type: 'string', example: '0.7.0', description: '"dev" for a build without a release version'),
            new OA\Property(property: 'update', type: 'object', nullable: true, description: 'Only for the instance admin, from the daily check of the GitHub tags; null otherwise', properties: [
                new OA\Property(property: 'version', type: 'string', example: '0.7.1'),
                new OA\Property(property: 'url', type: 'string', format: 'uri'),
            ]),
        ], type: 'object'))],
    )]
    #[Route('/api/about', name: 'api_about', methods: ['GET'])]
    public function about(#[CurrentUser] User $user): JsonResponse
    {
        $update = $this->updates->isEnabled() && $this->users->isInstanceAdmin($user) ? $this->updates->availableUpdate() : null;

        return new JsonResponse([
            'version' => $this->updates->currentVersion(),
            'update' => $update !== null ? ['version' => $update, 'url' => AppUpdates::releaseUrl($update)] : null,
        ]);
    }
}
