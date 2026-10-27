<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\TwoFactorCredentialsRequest;
use App\Entity\User;
use App\Service\TwoFactor\TwoFactorSetup;
use App\Service\TwoFactor\TwoFactorSetupError;
use App\Service\TwoFactor\TwoFactorSetupException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Verifica in due passaggi (TOTP + codici di recupero): il secondo passaggio del login (gestito dal firewall, Scheb) e
 * la gestione dal profilo. Le regole stanno in {@see TwoFactorSetup}.
 */
#[OA\Tag(name: 'Two-step verification')]
#[Route('/api/auth/2fa', name: 'api_auth_2fa_')]
final class TwoFactorController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly TwoFactorSetup $twoFactor,
    ) {
    }

    /** Il codice lo verifica il firewall (Scheb, check path `two_factor`): questa azione esiste solo come rotta. */
    #[OA\Post(
        summary: 'Second sign-in step: code from the authenticator app or a recovery code',
        description: 'Only while two-step verification is in progress (after POST /api/auth/login answered twoFactorRequired: true). A recovery code works once. After 5 wrong codes in 15 minutes it answers 429, also with the right code. On success the session gets a new id and the brumy_remember cookie is set.',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['authCode'],
                properties: [new OA\Property(property: 'authCode', type: 'string', example: '123456')],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: '{twoFactorRequired: false}'),
            new OA\Response(response: 401, description: 'auth.invalid_two_factor_code; http.401 without a verification in progress'),
            new OA\Response(response: 403, description: 'auth.csrf_invalid'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/check', name: 'check', methods: ['POST'])]
    public function check(): never
    {
        throw new BadRequestHttpException();
    }

    #[OA\Post(
        summary: 'Start setting up two-step verification',
        description: 'Creates a new secret waiting for confirmation (it replaces an unconfirmed one). qrCode is an SVG data URI to scan; secret is for manual entry; otpauthUri opens the authenticator app on mobile. Nothing changes at sign-in until POST /api/auth/2fa/enable.',
        responses: [
            new OA\Response(response: 200, description: '{secret, otpauthUri, qrCode}'),
            new OA\Response(response: 409, description: 'two_factor.already_enabled'),
        ],
    )]
    #[Route('/setup', name: 'setup', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function setup(): JsonResponse
    {
        try {
            return $this->json($this->twoFactor->begin($this->currentUser()));
        } catch (TwoFactorSetupException $e) {
            return $this->setupProblem($e);
        }
    }

    #[OA\Post(
        summary: 'Turn on two-step verification',
        description: 'Confirms the pending setup with the current password and a code from the authenticator app. Returns the 10 recovery codes, shown only now. Other open sessions are not signed out. Wrong password or code count against the same 5-per-15-minutes limit as the sign-in codes.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: TwoFactorCredentialsRequest::class))),
        responses: [
            new OA\Response(response: 200, description: '{backupCodes: string[10]}'),
            new OA\Response(response: 400, description: 'auth.invalid_current_password'),
            new OA\Response(response: 409, description: 'two_factor.already_enabled / two_factor.setup_required'),
            new OA\Response(response: 422, description: 'validation_failed (code: two_factor.invalid_code)'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/enable', name: 'enable', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function enable(#[MapRequestPayload] TwoFactorCredentialsRequest $payload): JsonResponse
    {
        try {
            return $this->json(['backupCodes' => $this->twoFactor->enable($this->currentUser(), $payload->currentPassword, $payload->code)]);
        } catch (TwoFactorSetupException $e) {
            return $this->setupProblem($e);
        }
    }

    #[OA\Post(
        summary: 'Turn off two-step verification',
        description: 'Needs the current password and a code from the authenticator app or a recovery code (which is used up). Secret and recovery codes are deleted.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: TwoFactorCredentialsRequest::class))),
        responses: [
            new OA\Response(response: 204, description: 'Turned off'),
            new OA\Response(response: 400, description: 'auth.invalid_current_password'),
            new OA\Response(response: 409, description: 'two_factor.not_enabled'),
            new OA\Response(response: 422, description: 'validation_failed (code: two_factor.invalid_code)'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/disable', name: 'disable', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function disable(#[MapRequestPayload] TwoFactorCredentialsRequest $payload): JsonResponse
    {
        try {
            $this->twoFactor->disable($this->currentUser(), $payload->currentPassword, $payload->code);
        } catch (TwoFactorSetupException $e) {
            return $this->setupProblem($e);
        }

        return new JsonResponse(null, 204);
    }

    #[OA\Post(
        summary: 'Generate new recovery codes',
        description: 'Needs the current password and a code from the authenticator app or a recovery code. The previous recovery codes stop working.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: TwoFactorCredentialsRequest::class))),
        responses: [
            new OA\Response(response: 200, description: '{backupCodes: string[10]}'),
            new OA\Response(response: 400, description: 'auth.invalid_current_password'),
            new OA\Response(response: 409, description: 'two_factor.not_enabled'),
            new OA\Response(response: 422, description: 'validation_failed (code: two_factor.invalid_code)'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/backup-codes', name: 'backup_codes', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function regenerateBackupCodes(#[MapRequestPayload] TwoFactorCredentialsRequest $payload): JsonResponse
    {
        try {
            return $this->json(['backupCodes' => $this->twoFactor->regenerateBackupCodes($this->currentUser(), $payload->currentPassword, $payload->code)]);
        } catch (TwoFactorSetupException $e) {
            return $this->setupProblem($e);
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function setupProblem(TwoFactorSetupException $e): JsonResponse
    {
        return match ($e->error) {
            TwoFactorSetupError::AlreadyEnabled => $this->problem('two_factor.already_enabled', 409),
            TwoFactorSetupError::NotEnabled => $this->problem('two_factor.not_enabled', 409),
            TwoFactorSetupError::SetupRequired => $this->problem('two_factor.setup_required', 409),
            TwoFactorSetupError::InvalidPassword => $this->problem('auth.invalid_current_password', 400),
            TwoFactorSetupError::InvalidCode => $this->fieldProblem('code', 'two_factor.invalid_code'),
            TwoFactorSetupError::TooManyAttempts => $this->problem('auth.too_many_attempts', 429),
        };
    }
}
