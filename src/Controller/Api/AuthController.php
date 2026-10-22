<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\ChangePasswordRequest;
use App\Dto\Request\ForgotPasswordRequest;
use App\Dto\Request\LoginRequest;
use App\Dto\Request\RefreshRequest;
use App\Dto\Request\RegisterRequest;
use App\Dto\Request\ResetPasswordRequest;
use App\Dto\Request\SwitchOrgRequest;
use App\Dto\Request\AcceptInvitationRequest;
use App\Dto\Request\RegisterInvitedRequest;
use App\Dto\Request\UpdateProfileRequest;
use App\Dto\Request\VerifyEmailRequest;
use App\Entity\EmailVerificationToken;
use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationMember;
use App\Entity\PasswordResetToken;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use App\Repository\OrganizationInvitationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\ActiveOrganizationResolver;
use App\Service\AfterResponseTasks;
use App\Service\AppMailer;
use App\Service\MailBuilder;
use App\Service\RefreshRejection;
use App\Service\RefreshTokenService;
use App\Service\Storage\AttachmentStorageInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[OA\Tag(name: 'Auth')]
#[Route('/api/auth', name: 'api_auth_')]
final class AuthController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    /** Lock nominale (GET_LOCK) che serializza la registrazione del primo utente. */
    private const REGISTER_LOCK = 'brumy:first-registration';

    private const AVATAR_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
    private const AVATAR_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly OrganizationRepository $orgRepo,
        private readonly OrganizationMemberRepository $memberRepo,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenService $refreshTokens,
        private readonly PasswordResetTokenRepository $resetTokenRepo,
        private readonly EmailVerificationTokenRepository $verificationTokenRepo,
        private readonly OrganizationInvitationRepository $invitationRepo,
        private readonly AppMailer $mailer,
        private readonly MailBuilder $mailBuilder,
        private readonly SluggerInterface $slugger,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactory $loginLimiter,
        private readonly RateLimiterFactory $refreshLimiter,
        #[Autowire(service: 'limiter.login_ip')]
        private readonly RateLimiterFactory $loginIpLimiter,
        #[Autowire(service: 'limiter.token_actions')]
        private readonly RateLimiterFactory $tokenActionsLimiter,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactory $forgotPasswordLimiter,
        private readonly RateLimiterFactoryInterface $forgotPasswordIpLimiter,
        private readonly AfterResponseTasks $afterResponse,
        private readonly LoggerInterface $logger,
        private readonly AttachmentStorageInterface $storage,
        private readonly ActiveOrganizationResolver $activeOrganization,
        private readonly PushSubscriptionRepository $pushSubscriptions,
        private readonly Security $security,
    ) {
    }

    #[OA\Get(
        summary: 'Is open self-registration available?',
        description: 'Public. True only while the instance has no users (first-run bootstrap of the instance admin); afterwards new users can only join through an invitation.',
        security: [],
        responses: [new OA\Response(response: 200, description: '{open: bool}')],
    )]
    #[Route('/registration', name: 'registration_status', methods: ['GET'])]
    public function registrationStatus(): JsonResponse
    {
        return $this->json(['open' => !$this->userRepo->hasUsers()]);
    }

    #[OA\Post(
        summary: 'Register the first user + personal organization (first-run only)',
        description: 'Open ONLY while the instance has no users: the first account becomes the instance admin. From then on it answers 403 auth.registration_closed and new users join exclusively via invitation (POST /api/auth/invitation/register). Header X-Client-Type discriminates web (cookie) vs mobile (Bearer body).',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: RegisterRequest::class)),
        ),
        responses: [
            new OA\Response(response: 201, description: 'User created + JWT issued'),
            new OA\Response(response: 403, description: 'auth.registration_closed'),
            new OA\Response(response: 409, description: 'auth.email_taken (concurrent first-run race)'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        Request $request,
        #[MapRequestPayload] RegisterRequest $payload,
    ): JsonResponse {
        if ($this->throttled($this->tokenActionsLimiter)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        // Registrazione libera solo per il primo utente (bootstrap dell'admin di
        // istanza). Dopo, chiunque si registrasse potrebbe entrare nell'istanza:
        // si entra solo su invito (invitation/register), che ha un token.
        //
        // Due prime registrazioni concorrenti (email diverse) passerebbero entrambe il controllo
        // "nessun utente" e creerebbero due admin di istanza: check e creazione stanno sotto un lock
        // nominale del DB, e utente + organizzazione + membership in un'unica transazione (un errore a
        // metà non deve lasciare un utente senza organizzazione, con la registrazione ormai chiusa).
        $connection = $this->em->getConnection();
        if (!$connection->fetchOne('SELECT GET_LOCK(?, 10)', [self::REGISTER_LOCK])) {
            return $this->problem('auth.try_again', 503);
        }

        try {
            if ($this->userRepo->hasUsers()) {
                return $this->problem('auth.registration_closed', 403);
            }

            $user = (new User())
                ->setEmail($payload->email)
                ->setFirstName($payload->firstName)
                ->setLastName($payload->lastName)
                ->setLocale($payload->locale);
            $user->setPassword($this->passwordHasher->hashPassword($user, $payload->password));

            // Validazione completa (UniqueEntity etc.)
            $errors = $this->validator->validate($user);
            if (count($errors) > 0) {
                return $this->validationProblem($errors);
            }

            try {
                $this->em->wrapInTransaction(function () use ($user, $payload): void {
                    $this->em->persist($user);
                    $this->em->flush(); // necessario per ottenere $user->getId() prima dello slug

                    $orgName = $payload->organizationName
                        ?? sprintf('%s %s', $payload->firstName, $payload->lastName);
                    $org = (new Organization())
                        ->setName($orgName)
                        ->setSlug($this->buildUniqueSlug($orgName, $user->getId()));
                    $this->em->persist($org);

                    $this->em->persist(
                        (new OrganizationMember())
                            ->setUser($user)
                            ->setOrganization($org)
                            ->setRole(\App\Enum\OrgRole::OWNER)
                            ->setAcceptedAt(new \DateTimeImmutable()),
                    );
                });
            } catch (UniqueConstraintViolationException) {
                return $this->problem('auth.email_taken', 409);
            }
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::REGISTER_LOCK]);
        }

        // Invio email di verifica (best-effort: un SMTP giù non deve far fallire il registro).
        $this->issueAndSendVerification($user);

        $clientType = $this->resolveClientType($request);

        return $this->buildAuthResponse($user, $clientType, statusCode: 201);
    }

    #[OA\Post(
        summary: 'Login with email + password',
        description: 'Rate limited (5/min per email+IP). Header X-Client-Type: web → refresh in HttpOnly cookie. mobile → refresh in body.',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: LoginRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: 'JWT issued'),
            new OA\Response(response: 401, description: 'auth.invalid_credentials'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(
        Request $request,
        #[MapRequestPayload] LoginRequest $payload,
    ): JsonResponse {
        $limiter = $this->loginLimiter->create($this->loginLimiterKey($request, $payload->email));
        if (!$limiter->consume()->isAccepted() || $this->isBlocked($this->loginIpLimiter)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $user = $this->userRepo->findOneByEmail($payload->email);
        if (!$user) {
            // Stesso costo di una verifica reale: il tempo di risposta non rivela se l'email esiste.
            $this->passwordHasher->hashPassword(new User(), $payload->password);
            $this->throttled($this->loginIpLimiter);

            return $this->problem('auth.invalid_credentials', 401);
        }
        if (!$this->passwordHasher->isPasswordValid($user, $payload->password)) {
            $this->throttled($this->loginIpLimiter);

            return $this->problem('auth.invalid_credentials', 401);
        }

        // Null se un reset/cambio password o un cambio email è stato committato dopo la verifica: la password
        // controllata non è più quella dell'account e la sessione non deve nascere.
        $refresh = $this->refreshTokens->issueIfCredentialsUnchanged($user);
        if ($refresh === null) {
            return $this->problem('auth.invalid_credentials', 401);
        }

        return $this->buildAuthResponse($user, $this->resolveClientType($request), existingRefresh: $refresh);
    }

    #[OA\Post(
        summary: 'Rotate access + refresh token',
        description: 'Web reads cookie. Mobile sends refresh in body. Old refresh is revoked and the new one stays in the same session family. Presenting an already-rotated token again (after a 10 second grace for concurrent requests) revokes the whole family: the answer is the same 401 auth.invalid_refresh_token. Rate limited (30/min per IP).',
        security: [],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(ref: new Model(type: RefreshRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: 'New tokens'),
            new OA\Response(response: 400, description: 'auth.refresh_token_required'),
            new OA\Response(response: 401, description: 'auth.invalid_refresh_token'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')] ?RefreshRequest $payload = null,
    ): JsonResponse {
        $limiter = $this->refreshLimiter->create('refresh-'.$request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $cookieToken = $request->cookies->get(RefreshTokenService::COOKIE_NAME);
        $bodyToken = $payload?->refreshToken;

        // Il canale di ingresso determina il canale di uscita
        $clientType = $cookieToken !== null ? 'web' : 'mobile';
        $rawToken = $cookieToken ?? $bodyToken;

        if (!$rawToken) {
            return $this->problem('auth.refresh_token_required', 400);
        }

        $refresh = $this->refreshTokens->findValid($rawToken);
        if (!$refresh) {
            // Stessa risposta per token sconosciuto, scaduto, ruotato da poco o riusato: il client non
            // deve poter capire se ha fatto scattare la revoca della sessione.
            $rejection = $this->refreshTokens->classifyRejected($rawToken);
            $response = $this->problem('auth.invalid_refresh_token', 401);
            // Se era un cookie invalido, lo cancelliamo. Non se ha perso una corsa con un'altra richiesta
            // (due schede): quella ha appena consegnato il cookie nuovo e cancellarlo scollegherebbe l'utente.
            if ($cookieToken !== null && $rejection !== RefreshRejection::RaceLost) {
                $response->headers->clearCookie(RefreshTokenService::COOKIE_NAME, '/api/auth');
            }
            return $response;
        }

        $rotated = $this->refreshTokens->rotate($refresh);
        if ($rotated === null) {
            return $this->problem('auth.invalid_refresh_token', 401);
        }

        return $this->buildAuthResponse($refresh->getUser(), $clientType, existingRefresh: $rotated);
    }

    #[OA\Post(
        summary: 'Logout — revoke refresh + clear cookie',
        description: 'Accepts refresh token from cookie or body {refresh_token}. Returns 204 even if token missing. Revokes the whole session (refresh token family), including a successor issued by a concurrent rotation. With a valid refresh token it also invalidates every access token of the user already issued, on every device: the other devices keep their refresh token and recover with a refresh. Push subscriptions are not touched.',
        security: [],
        responses: [new OA\Response(response: 204, description: 'Logged out')],
    )]
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        // Rotta pubblica: il corpo può essere qualsiasi JSON (scalare, lista, refresh_token non stringa) e non deve dare 500.
        $body = json_decode($request->getContent() ?: '{}', true);
        $rawToken = $request->cookies->get(RefreshTokenService::COOKIE_NAME)
            ?? (is_array($body) ? ($body['refresh_token'] ?? null) : null);

        if (is_string($rawToken) && $rawToken !== '' && ($refresh = $this->refreshTokens->findValid($rawToken))) {
            $this->refreshTokens->revoke($refresh);
        }

        $response = new JsonResponse(null, 204);
        $response->headers->clearCookie(RefreshTokenService::COOKIE_NAME, '/api/auth');
        return $response;
    }

    /**
     * Cambia l'organization attiva: emette un nuovo JWT con `active_org_id` aggiornato e la stessa scadenza
     * del token presentato. Il client deve sostituire il vecchio access token nello state e
     * mantenere lo stesso refresh token (la sessione non cambia).
     */
    #[OA\Post(
        summary: 'Switch active organization',
        description: 'Issues a new JWT with the updated active_org_id claim and the same expiry as the presented token (it never extends the session: a fresh expiry comes only from a refresh or a credential). Refresh token unchanged.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: SwitchOrgRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: 'New access_token + active_org_id'),
            new OA\Response(response: 403, description: 'auth.not_member (membership still pending)'),
            new OA\Response(response: 404, description: 'org.not_found (unknown id, or no relation with the organization)'),
        ],
    )]
    #[Route('/switch-org', name: 'switch_org', methods: ['POST'])]
    public function switchOrg(Request $request, #[MapRequestPayload] SwitchOrgRequest $payload): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $org = $this->orgRepo->find($payload->organizationId);
        $membership = $org !== null ? $this->memberRepo->findMembership($user, $org) : null;
        // Nessun legame con l'org: stesso 404 dell'id inesistente, così gli id non si possono enumerare.
        if ($org === null || $membership === null) {
            return $this->problem('org.not_found', 404);
        }
        if (!$membership->isAccepted()) {
            return $this->problem('auth.not_member', 403); // invito ancora in attesa
        }

        $accessToken = $this->reissueAccessToken($user, $org->getId());

        // Ricorda l'org scelta sulla sessione, così un refresh non riporta alla prima org.
        $rawRefresh = $request->cookies->get(RefreshTokenService::COOKIE_NAME) ?? $payload->refreshToken;
        $refresh = $rawRefresh ? $this->refreshTokens->findValid($rawRefresh) : null;
        if ($refresh !== null && $refresh->getUser()->getId() === $user->getId()) {
            $this->refreshTokens->setActiveOrganization($refresh, (int) $org->getId());
        }

        return $this->json([
            'access_token' => $accessToken,
            'active_org_id' => $org->getId(),
        ]);
    }

    #[OA\Get(
        summary: 'Current user profile + organization memberships',
        responses: [
            new OA\Response(response: 200, description: 'User + memberships array'),
            new OA\Response(response: 401, description: 'auth.not_authenticated'),
        ],
    )]
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->problem('auth.not_authenticated', 401);
        }

        return $this->json([
            ...$this->userPayload($user),
            'memberships' => array_map(static fn (OrganizationMember $m) => [
                'id' => $m->getId(),
                'role' => $m->getRole()->value,
                'organization' => [
                    'id' => $m->getOrganization()->getId(),
                    'name' => $m->getOrganization()->getName(),
                    'slug' => $m->getOrganization()->getSlug(),
                ],
            ], $this->memberRepo->findAllForUser($user)),
        ]);
    }

    #[OA\Put(
        summary: 'Update current user profile (name, email, locale)',
        description: 'Email must stay unique. Without an email change it returns an access_token with the same expiry as the presented one. An email change (requires currentPassword) invalidates every access token and refresh token of the user, deletes the push subscriptions and opens a new session for the current client (access_token + refresh token in cookie or body).',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateProfileRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated user + access_token (new session if the email changed)'),
            new OA\Response(response: 400, description: 'auth.invalid_current_password'),
            new OA\Response(response: 409, description: 'auth.email_taken'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('/profile', name: 'update_profile', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function updateProfile(Request $request, #[MapRequestPayload] UpdateProfileRequest $payload): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $emailChanged = mb_strtolower($payload->email) !== mb_strtolower($user->getEmail());
        if ($emailChanged
            && ($payload->currentPassword === null || !$this->passwordHasher->isPasswordValid($user, $payload->currentPassword))
        ) {
            return $this->problem('auth.invalid_current_password', 400);
        }

        $existing = $this->userRepo->findOneByEmail($payload->email);
        if ($existing && $existing->getId() !== $user->getId()) {
            return $this->problem('auth.email_taken', 409);
        }

        $user
            ->setFirstName($payload->firstName)
            ->setLastName($payload->lastName)
            ->setEmail($payload->email)
            ->setLocale($payload->locale);

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            return $this->validationProblem($errors);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Race: un'altra richiesta ha preso l'email tra il check e il flush.
            return $this->problem('auth.email_taken', 409);
        }

        if ($emailChanged) {
            // La nuova email va riverificata; le altre sessioni (potenzialmente di un attaccante) decadono.
            $user->resetEmailVerification();
            $this->em->flush();
            // Un link di reset già spedito al vecchio indirizzo non deve restare spendibile dopo il cambio.
            $this->resetTokenRepo->invalidateAllForUser($user);
            $activeOrgId = $this->activeOrganization->tryResolve()?->getId();
            $this->endAllSessions($user);
            $this->issueAndSendVerification($user);

            // Nuova sessione per il client corrente (mantiene l'org attiva).
            return $this->buildAuthResponse(
                $user,
                $this->resolveClientType($request),
                existingRefresh: $this->refreshTokens->issue($user, $activeOrgId),
            );
        }

        // Email invariata: il token presentato resta valido. La risposta riporta comunque un access_token (contratto
        // del client), con l'org attiva e la stessa scadenza: un profile update non deve allungare la sessione.
        return $this->json([
            'access_token' => $this->reissueAccessToken($user, $this->activeOrganization->tryResolve()?->getId()),
            'user' => $this->userPayload($user),
        ]);
    }

    #[OA\Put(
        summary: 'Change current user password',
        description: 'Verifies currentPassword, then sets newPassword. Invalidates every access token and refresh token of the user (logs out other devices), deletes the push subscriptions and issues a fresh session for the current client, keeping the active organization.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ChangePasswordRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: 'New access_token + rotated refresh'),
            new OA\Response(response: 400, description: 'auth.invalid_current_password'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('/password', name: 'change_password', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(
        Request $request,
        #[MapRequestPayload] ChangePasswordRequest $payload,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->passwordHasher->isPasswordValid($user, $payload->currentPassword)) {
            return $this->problem('auth.invalid_current_password', 400);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $payload->newPassword));
        $this->em->flush();

        // Sicurezza: invalida tutte le sessioni esistenti, poi riapri quella corrente
        // mantenendone l'org attiva (altrimenti si ricadrebbe sulla prima membership).
        $activeOrgId = $this->activeOrganization->tryResolve()?->getId();
        $this->endAllSessions($user);

        return $this->buildAuthResponse(
            $user,
            $this->resolveClientType($request),
            existingRefresh: $this->refreshTokens->issue($user, $activeOrgId),
        );
    }

    #[OA\Post(
        summary: 'Upload current user avatar (multipart/form-data, field `file`)',
        description: 'Max 2MB. Whitelist: image/jpeg, image/png, image/webp (MIME verified server-side). Replaces the previous avatar.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: '{hasAvatar: true}'),
            new OA\Response(response: 400, description: 'upload.file_required / upload.invalid_file'),
            new OA\Response(response: 413, description: 'upload.file_too_large'),
            new OA\Response(response: 415, description: 'upload.mime_not_allowed'),
        ],
    )]
    #[Route('/avatar', name: 'avatar_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $file = $request->files->get('file');
        if (!$file) {
            return $this->problem('upload.file_required', 400);
        }
        if (!$file->isValid()) {
            return $this->problem('upload.invalid_file', 400);
        }
        if ($file->getSize() > self::AVATAR_MAX_BYTES) {
            return $this->problem('upload.file_too_large', 413);
        }
        // MIME verificato server-side: mai fidarsi del client
        $mime = $file->getMimeType() ?: '';
        if (!in_array($mime, self::AVATAR_ALLOWED_MIME, true)) {
            return $this->problem('upload.mime_not_allowed', 415);
        }

        $previousPath = $user->getAvatarPath();
        $storedPath = $this->storage->store($file, 'avatar');

        try {
            $user->setAvatarPath($storedPath);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->storage->delete($storedPath);
            throw $e;
        }

        if ($previousPath !== null) {
            $this->storage->delete($previousPath);
        }

        return $this->json(['hasAvatar' => true]);
    }

    #[OA\Get(
        summary: 'Download current user avatar',
        description: 'Returns avatar bytes, streamed directly by the app.',
        responses: [
            new OA\Response(response: 200, description: 'Binary image'),
            new OA\Response(response: 404, description: 'auth.no_avatar'),
        ],
    )]
    #[Route('/avatar', name: 'avatar_download', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function downloadAvatar(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $path = $user->getAvatarPath();
        if ($path === null || !$this->storage->exists($path)) {
            return $this->problem('auth.no_avatar', 404);
        }

        $absolutePath = $this->storage->absolutePath($path);
        // Content-Type rilevato dal contenuto: i file avatar sono salvati senza estensione.
        $mime = MimeTypes::getDefault()->guessMimeType($absolutePath) ?? 'application/octet-stream';

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff'); // come per gli allegati, anche senza il proxy davanti
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'avatar');
        return $response;
    }

    #[OA\Delete(
        summary: 'Remove current user avatar',
        responses: [new OA\Response(response: 204, description: 'Removed (idempotent)')],
    )]
    #[Route('/avatar', name: 'avatar_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteAvatar(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $path = $user->getAvatarPath();
        if ($path !== null) {
            $user->setAvatarPath(null);
            $this->em->flush();
            $this->storage->delete($path);
        }

        return new JsonResponse(null, 204);
    }

    #[OA\Post(
        summary: 'Request a password reset email',
        description: 'Always returns 200 (no user enumeration). If the email exists, sends a reset link valid 1h; the email is sent after the response so timing does not reveal whether the account exists. Rate limited per (email, IP) and per IP (429 auth.too_many_attempts).',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ForgotPasswordRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Email sent if account exists'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgotPassword(
        Request $request,
        #[MapRequestPayload] ForgotPasswordRequest $payload,
    ): JsonResponse {
        // Per IP: il limite per (email, IP) da solo lascia 3 mail per ogni indirizzo, quindi un solo IP potrebbe
        // far partire mail verso un numero illimitato di caselle (mail bombing).
        if ($this->throttled($this->forgotPasswordIpLimiter)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $limiter = $this->forgotPasswordLimiter->create('forgot-'.strtolower($payload->email).'-'.$request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        // Account esistente e inesistente devono costare circa lo stesso sul percorso della risposta, altrimenti
        // i tempi rivelano quali email sono registrate. Il token si scrive subito (serve a un reset immediato),
        // mentre la parte lenta (costruzione e invio SMTP) gira dopo la risposta. Differenza residua: l'UPDATE
        // che invalida i token precedenti e l'INSERT del nuovo, pochi millisecondi di DB.
        $user = $this->userRepo->findOneByEmail($payload->email);
        if ($user) {
            // Un solo token attivo per utente: invalida i precedenti.
            $this->resetTokenRepo->invalidateAllForUser($user);

            $rawToken = bin2hex(random_bytes(32));
            $resetToken = new PasswordResetToken(
                $user,
                hash('sha256', $rawToken),
                (new \DateTimeImmutable())->modify('+1 hour'),
            );
            $this->em->persist($resetToken);
            $this->em->flush();

            // Il fallimento SMTP non diventa un 500 (la risposta è già partita): AfterResponseTasks lo logga e
            // il client può ritentare; il token verrà invalidato dal prossimo forgot.
            $this->afterResponse->defer(
                fn () => $this->mailer->send($this->mailBuilder->resetPassword($user, $rawToken)->to($user->getEmail())),
                ['event' => 'password_reset_email', 'user_id' => $user->getId()],
            );
        }

        return $this->json(['status' => 'ok']);
    }

    #[OA\Post(
        summary: 'Reset password with a token from the email',
        description: 'Consumes a valid (unexpired, unused) token, sets the new password, invalidates every access token and refresh token of the user and deletes the push subscriptions.',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ResetPasswordRequest::class)),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Password reset'),
            new OA\Response(response: 400, description: 'auth.invalid_reset_token'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(#[MapRequestPayload] ResetPasswordRequest $payload): JsonResponse
    {
        if ($this->throttled($this->tokenActionsLimiter)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $resetToken = $this->resetTokenRepo->findValidByHash(hash('sha256', $payload->token));
        if (!$resetToken) {
            return $this->problem('auth.invalid_reset_token', 400);
        }

        // Consumo atomico: due richieste concorrenti con lo stesso token non passano entrambe.
        if (!$this->resetTokenRepo->consume($resetToken)) {
            return $this->problem('auth.invalid_reset_token', 400);
        }

        $user = $resetToken->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $payload->password));
        // Il link è arrivato alla casella: il reset ne prova il possesso.
        $user->markEmailVerified();
        $this->em->flush();

        // Tutte le sessioni esistenti vanno invalidate dopo un reset.
        $this->endAllSessions($user);

        return new JsonResponse(null, 204);
    }

    #[OA\Post(
        summary: 'Confirm email address with a token from the verification email',
        description: 'Consumes a valid (unexpired, unused) token and marks the user email as verified.',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: VerifyEmailRequest::class)),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Email verified'),
            new OA\Response(response: 400, description: 'auth.invalid_verification_token'),
        ],
    )]
    #[Route('/verify-email', name: 'verify_email', methods: ['POST'])]
    public function verifyEmail(#[MapRequestPayload] VerifyEmailRequest $payload): JsonResponse
    {
        if ($this->throttled($this->tokenActionsLimiter)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $token = $this->verificationTokenRepo->findValidByHash(hash('sha256', $payload->token));
        if (!$token) {
            return $this->problem('auth.invalid_verification_token', 400);
        }

        if (!$this->verificationTokenRepo->consume($token)) {
            return $this->problem('auth.invalid_verification_token', 400);
        }

        $token->getUser()->markEmailVerified();
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    #[OA\Post(
        summary: 'Resend the email verification link to the current user',
        description: 'No-op (still 204) if the email is already verified.',
        responses: [new OA\Response(response: 204, description: 'Verification email sent if needed')],
    )]
    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resendVerification(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user->isEmailVerified()) {
            $this->issueAndSendVerification($user);
        }

        return new JsonResponse(null, 204);
    }

    #[OA\Get(
        summary: 'Preview an organization invitation by token (for the accept page)',
        description: 'Public. Returns org name, target email, role and whether an account already exists for that email.',
        security: [],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: '{organizationName, email, role, accountExists}'),
            new OA\Response(response: 400, description: 'invitation.invalid'),
        ],
    )]
    #[Route('/invitation/{token}', name: 'invitation_preview', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function previewInvitation(string $token): JsonResponse
    {
        if ($this->throttled($this->tokenActionsLimiter)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $invitation = $this->invitationRepo->findValidByHash(hash('sha256', $token));
        if (!$invitation) {
            return $this->problem('invitation.invalid', 400);
        }

        return $this->json([
            'organizationName' => $invitation->getOrganization()->getName(),
            'email' => $invitation->getEmail(),
            'role' => $invitation->getRole()->value,
            'accountExists' => $this->userRepo->findOneByEmail($invitation->getEmail()) !== null,
        ]);
    }

    #[OA\Post(
        summary: 'Accept an organization invitation (existing, logged-in user)',
        description: 'Authenticated. The current user email must match the invitation email. Creates the accepted membership.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: AcceptInvitationRequest::class))),
        responses: [
            new OA\Response(response: 200, description: '{status, organizationId}'),
            new OA\Response(response: 400, description: 'invitation.invalid'),
            new OA\Response(response: 403, description: 'invitation.not_for_you'),
        ],
    )]
    #[Route('/invitation/accept', name: 'invitation_accept', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function acceptInvitation(#[MapRequestPayload] AcceptInvitationRequest $payload): JsonResponse
    {
        if ($this->throttled($this->tokenActionsLimiter)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $invitation = $this->invitationRepo->findValidByHash(hash('sha256', $payload->token));
        if (!$invitation) {
            return $this->problem('invitation.invalid', 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        if (mb_strtolower($user->getEmail()) !== mb_strtolower($invitation->getEmail())) {
            return $this->problem('invitation.not_for_you', 403);
        }

        // Claim atomico dell'invito + membership nella stessa transazione.
        $accepted = $this->em->wrapInTransaction(function () use ($user, $invitation): bool {
            if (!$this->invitationRepo->consume($invitation)) {
                return false;
            }
            $this->acceptInvitationFor($user, $invitation);

            return true;
        });
        if (!$accepted) {
            return $this->problem('invitation.invalid', 400);
        }

        return $this->json(['status' => 'ok', 'organizationId' => $invitation->getOrganization()->getId()]);
    }

    #[OA\Post(
        summary: 'Register a new account from an invitation and accept it',
        description: 'Public. Creates the user (email taken from the invitation, marked verified), a personal organization, and the accepted membership in the inviting org. Issues JWT.',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RegisterInvitedRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'User created + joined + JWT issued'),
            new OA\Response(response: 400, description: 'invitation.invalid'),
            new OA\Response(response: 409, description: 'auth.email_taken (account exists → log in instead)'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('/invitation/register', name: 'invitation_register', methods: ['POST'])]
    public function registerInvited(
        Request $request,
        #[MapRequestPayload] RegisterInvitedRequest $payload,
    ): JsonResponse {
        if ($this->throttled($this->tokenActionsLimiter)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $invitation = $this->invitationRepo->findValidByHash(hash('sha256', $payload->token));
        if (!$invitation) {
            return $this->problem('invitation.invalid', 400);
        }

        $email = $invitation->getEmail();
        if ($this->userRepo->findOneByEmail($email) !== null) {
            // Esiste già: deve accedere e usare l'accept autenticato.
            return $this->problem('auth.email_taken', 409);
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($payload->firstName)
            ->setLastName($payload->lastName)
            ->setLocale($payload->locale);
        $user->setPassword($this->passwordHasher->hashPassword($user, $payload->password));
        // L'invito è arrivato a quell'indirizzo: il possesso dell'email è provato.
        $user->markEmailVerified();

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            return $this->validationProblem($errors);
        }

        // Niente org personale: l'invitato ENTRA nell'organizzazione che lo ha
        // invitato (modello family-sharing self-host) col ruolo dell'invito, non
        // ne possiede una propria. Resta quindi un'unica membership → l'org attiva
        // (JWT active_org_id e display frontend) è sempre quella dell'invito.
        // Claim dell'invito, utente e membership in un'unica transazione: un invito non si usa due volte
        // e un errore a metà non lascia un utente senza organizzazione.
        try {
            $registered = $this->em->wrapInTransaction(function () use ($user, $invitation): bool {
                if (!$this->invitationRepo->consume($invitation)) {
                    return false;
                }
                $this->em->persist($user);
                $this->em->flush();
                $this->acceptInvitationFor($user, $invitation);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->problem('auth.email_taken', 409);
        }
        if (!$registered) {
            return $this->problem('invitation.invalid', 400);
        }

        return $this->buildAuthResponse($user, $this->resolveClientType($request), statusCode: 201);
    }

    // ----- helpers -----

    /**
     * Crea (o conferma) la membership accettata di $user nell'org dell'invito e
     * marca l'invito come usato. Idempotente se già membro.
     */
    private function acceptInvitationFor(User $user, OrganizationInvitation $invitation): void
    {
        $org = $invitation->getOrganization();
        $existing = $this->memberRepo->findMembership($user, $org);
        if ($existing === null) {
            $this->em->persist(
                (new OrganizationMember())
                    ->setOrganization($org)
                    ->setUser($user)
                    ->setRole($invitation->getRole())
                    ->setInvitedBy($invitation->getInvitedBy())
                    ->setAcceptedAt(new \DateTimeImmutable()),
            );
        } elseif (!$existing->isAccepted()) {
            $existing->setAcceptedAt(new \DateTimeImmutable());
        }
        // L'invito è già stato marcato usato (atomicamente) da InvitationRepository::consume().
    }

    /**
     * Genera un token di verifica (un solo attivo per utente) e invia l'email.
     * Best-effort: un errore SMTP viene loggato ma non propagato.
     */
    private function issueAndSendVerification(User $user): void
    {
        $this->verificationTokenRepo->invalidateAllForUser($user);

        $rawToken = bin2hex(random_bytes(32));
        $token = new EmailVerificationToken(
            $user,
            hash('sha256', $rawToken),
            (new \DateTimeImmutable())->modify('+24 hours'),
        );
        $this->em->persist($token);
        $this->em->flush();

        try {
            $this->mailer->send($this->mailBuilder->verifyEmail($user, $rawToken)->to($user->getEmail()));
        } catch (\Throwable $e) {
            $this->logger->error('Email verification send failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{id: int|null, email: string, firstName: string, lastName: string, locale: string, hasAvatar: bool, emailVerified: bool}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'locale' => $user->getLocale(),
            'hasAvatar' => $user->hasAvatar(),
            'emailVerified' => $user->isEmailVerified(),
            // Amministratore di istanza (primo utente registrato): unico autorizzato
            // a toccare la config VAPID di istanza (SMTP è solo env, niente UI). Il
            // frontend lo usa per mostrare/nascondere quella sezione invece di
            // basarsi (erroneamente) sul ruolo OWNER, che chiunque si registri
            // ottiene sulla propria org personale.
            'isInstanceAdmin' => $this->userRepo->isInstanceAdmin($user),
        ];
    }

    /**
     * Transizione di credenziali (reset/cambio password, cambio email): chiude ogni sessione dell'utente, refresh e
     * access token, e cancella le push subscription di tutti i dispositivi. Chi aveva accesso prima non riceve più
     * né dati né notifiche; il client corrente riceve una sessione nuova (con la `token_version` aggiornata) e
     * ri-registra da solo la propria subscription. Le modifiche all'entity `User` vanno flushate prima.
     */
    private function endAllSessions(User $user): void
    {
        $this->refreshTokens->revokeAllForUser($user);
        $this->pushSubscriptions->deleteAllForUser($user);
    }

    /**
     * Riemette l'access token della richiesta (nuovo `active_org_id`) SENZA estenderne la durata: stesso `exp` del
     * token presentato. Una scadenza nuova arriva solo da una sessione refresh o da una credenziale
     * ({@see self::buildAuthResponse()}), altrimenti un access token rubato si rinnoverebbe da solo all'infinito.
     * Se `exp` non è leggibile si rifiuta con 401: mai una scadenza nuova come ripiego.
     */
    private function reissueAccessToken(User $user, ?int $activeOrgId): string
    {
        $token = $this->security->getToken();
        $exp = null;
        if ($token instanceof JWTPostAuthenticationToken) {
            try {
                $exp = $this->jwtManager->parse($token->getCredentials())['exp'] ?? null;
            } catch (JWTDecodeFailureException) {
                $exp = null;
            }
        }
        if (!is_int($exp)) {
            throw new UnauthorizedHttpException('Bearer');
        }

        $payload = ['exp' => $exp];
        if ($activeOrgId !== null) {
            $payload['active_org_id'] = $activeOrgId;
        }

        return $this->jwtManager->createFromPayload($user, $payload);
    }

    private function buildAuthResponse(
        User $user,
        string $clientType,
        ?RefreshToken $existingRefresh = null,
        int $statusCode = 200,
    ): JsonResponse {
        $refresh = $existingRefresh ?? $this->refreshTokens->issue($user);

        // Sessione con org attiva già scelta (refresh): la manteniamo se l'utente è ancora membro.
        $activeOrgId = $refresh->getActiveOrganizationId();
        $activeOrg = $activeOrgId !== null ? $this->orgRepo->find($activeOrgId) : null;
        $membership = $activeOrg !== null ? $this->memberRepo->findMembership($user, $activeOrg) : null;
        $accessToken = $membership !== null && $membership->isAccepted()
            ? $this->jwtManager->createFromPayload($user, ['user_id' => $user->getId(), 'active_org_id' => $activeOrgId])
            : $this->jwtManager->create($user);

        $body = [
            'access_token' => $accessToken,
            'user' => $this->userPayload($user),
        ];

        if ($clientType === 'mobile') {
            $body['refresh_token'] = $refresh->getPlainToken();
            return new JsonResponse($body, $statusCode);
        }

        // Web: refresh nel cookie HttpOnly
        $response = new JsonResponse($body, $statusCode);
        $response->headers->setCookie(Cookie::create(
            name: RefreshTokenService::COOKIE_NAME,
            value: $refresh->getPlainToken(),
            expire: $refresh->getExpiresAt(),
            path: '/api/auth',
            secure: $this->getParameter('kernel.environment') !== 'dev',
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));
        return $response;
    }

    /**
     * True se l'IP ha già esaurito i tentativi, SENZA consumarne uno. Il limiter di login per solo IP
     * conta i fallimenti (vedi {@see self::throttled()} nei rami di errore): tanti login riusciti
     * dietro lo stesso NAT non devono bloccare nessuno.
     */
    private function isBlocked(RateLimiterFactory $factory): bool
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';

        // consume(0) risulta sempre "accettato": si guarda quanti tentativi restano.
        return $factory->create($ip)->consume(0)->getRemainingTokens() <= 0;
    }

    /** Consuma un tentativo per l'IP del client; true se con questo il limite è superato. */
    private function throttled(RateLimiterFactoryInterface $factory): bool
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';

        return !$factory->create($ip)->consume()->isAccepted();
    }

    private function resolveClientType(Request $request): string
    {
        $header = strtolower((string) $request->headers->get('X-Client-Type', 'web'));
        return in_array($header, ['web', 'mobile'], true) ? $header : 'web';
    }

    private function loginLimiterKey(Request $request, string $email): string
    {
        return 'login-'.strtolower($email).'-'.$request->getClientIp();
    }

    private function buildUniqueSlug(string $name, int $userId): string
    {
        $base = strtolower((string) $this->slugger->slug($name));
        if ($base === '') {
            $base = 'workspace';
        }
        $candidate = $base;
        $suffix = 1;
        while ($this->orgRepo->findOneBySlug($candidate) !== null) {
            $candidate = $base.'-'.$userId.'-'.$suffix;
            $suffix++;
        }
        return $candidate;
    }
}
