<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\ChangePasswordRequest;
use App\Dto\Request\ForgotPasswordRequest;
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
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use App\Repository\OrganizationInvitationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Repository\VehicleShareRepository;
use App\Service\ActiveOrganizationResolver;
use App\Service\AfterResponseTasks;
use App\Service\AppMailer;
use App\Service\LogRedactor;
use App\Service\MailBuilder;
use App\Service\OrganizationSlugs;
use App\Service\UserAvatar;
use App\Service\Storage\AttachmentStorageInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
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
        private readonly PasswordResetTokenRepository $resetTokenRepo,
        private readonly EmailVerificationTokenRepository $verificationTokenRepo,
        private readonly OrganizationInvitationRepository $invitationRepo,
        private readonly AppMailer $mailer,
        private readonly MailBuilder $mailBuilder,
        private readonly OrganizationSlugs $orgSlugs,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $tokenActionsLimiter,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactoryInterface $forgotPasswordLimiter,
        private readonly RateLimiterFactoryInterface $forgotPasswordIpLimiter,
        private readonly RateLimiterFactoryInterface $verificationEmailLimiter,
        private readonly AfterResponseTasks $afterResponse,
        private readonly LoggerInterface $logger,
        private readonly AttachmentStorageInterface $storage,
        private readonly ActiveOrganizationResolver $activeOrganization,
        private readonly PushSubscriptionRepository $pushSubscriptions,
        private readonly Security $security,
        private readonly VehicleShareRepository $shareRepo,
        private readonly UserAvatar $avatar,
        #[Autowire(service: 'security.authenticator.remember_me_handler.api')]
        private readonly RememberMeHandlerInterface $rememberMe,
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
        description: 'Open ONLY while the instance has no users: the first account becomes the instance admin. From then on it answers 403 auth.registration_closed and new users join exclusively via invitation (POST /api/auth/invitation/register). Signs the new user in: session cookie brumy_session plus the brumy_remember cookie.',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: RegisterRequest::class)),
        ),
        responses: [
            new OA\Response(response: 201, description: '{user}; session opened'),
            new OA\Response(response: 403, description: 'auth.registration_closed'),
            new OA\Response(response: 409, description: 'auth.email_taken (concurrent first-run race)'),
            new OA\Response(response: 422, description: 'validation_failed'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
            new OA\Response(response: 503, description: 'auth.try_again'),
        ],
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $payload): JsonResponse
    {
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
                        ->setSlug($this->orgSlugs->unique($orgName, $user));
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

        return $this->signIn($user);
    }

    /**
     * Il login lo fa il firewall (`json_login`, success/failure handler in {@see \App\Security}): questa azione esiste
     * solo come rotta e per la documentazione. La raggiunge soltanto una richiesta che non è JSON.
     */
    #[OA\Post(
        summary: 'Sign in with email + password',
        description: 'JSON only. Throttled by the firewall: 5 failures per minute per (email, IP) and 25 per IP, then 429 even with the right password. Without two-step verification it opens the session (cookie brumy_session) and sets the brumy_remember cookie (7 days from the last use); with two-step verification on it answers twoFactorRequired: true and the session waits for the code at POST /api/auth/2fa/check (every protected route answers 401 auth.two_factor_required until then). Unknown email and wrong password give the same answer.',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: '{twoFactorRequired: bool}'),
            new OA\Response(response: 400, description: 'http.400 (not JSON, malformed JSON, missing email or password)'),
            new OA\Response(response: 401, description: 'auth.invalid_credentials'),
            new OA\Response(response: 403, description: 'auth.csrf_invalid'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(): never
    {
        throw new BadRequestHttpException();
    }

    /** Il logout lo fa il firewall ({@see \App\EventListener\LogoutResponseListener}): rotta e documentazione. */
    #[OA\Post(
        summary: 'Sign out',
        description: 'Always 204, also without a session or while two-step verification is in progress. Ends the session, deletes the remember-me series of this device and clears both cookies. Push subscriptions are not touched.',
        security: [],
        responses: [
            new OA\Response(response: 204, description: 'Signed out'),
            new OA\Response(response: 403, description: 'auth.csrf_invalid'),
        ],
    )]
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new BadRequestHttpException();
    }

    /**
     * Cambia l'organizzazione attiva della sessione corrente (le altre sessioni dell'utente restano sulla loro) e la
     * ricorda come ultima scelta per le sessioni nuove.
     */
    #[OA\Post(
        summary: 'Switch active organization',
        description: 'Changes the active organization of this session only and remembers it as the starting organization of the next sessions (e.g. after the browser is restarted).',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: SwitchOrgRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: '{activeOrganizationId}'),
            new OA\Response(response: 403, description: 'auth.not_member (membership still pending)'),
            new OA\Response(response: 404, description: 'org.not_found (unknown id, or no relation with the organization)'),
        ],
    )]
    #[Route('/switch-org', name: 'switch_org', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function switchOrg(#[MapRequestPayload] SwitchOrgRequest $payload): JsonResponse
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

        $this->activeOrganization->remember($user, $org);

        return $this->json(['activeOrganizationId' => $org->getId()]);
    }

    #[OA\Get(
        summary: 'Current user profile + organization memberships',
        description: 'How the SPA knows who is signed in. activeOrganizationId is the active organization of this session (null without an accepted membership).',
        responses: [
            new OA\Response(response: 200, description: 'User (with twoFactorEnabled, backupCodesRemaining) + activeOrganizationId + memberships array'),
            new OA\Response(response: 401, description: 'http.401 (no session); auth.two_factor_required (two-step verification in progress)'),
        ],
    )]
    #[Route('/me', name: 'me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            ...$this->userPayload($user),
            'activeOrganizationId' => $this->activeOrganization->tryResolve()?->getId(),
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
        description: 'Name and locale change at once. A different email (requires currentPassword) does not change the account email: it becomes `pendingEmail` and a confirmation link is sent to it; the change happens when the link is opened (POST /verify-email), which also ends every session of the user. The answer is the same whether or not the new address already has an account (the check runs at confirmation). At most 3 confirmation emails per 15 minutes per user (429 auth.too_many_attempts).',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: UpdateProfileRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: '{user} (with pendingEmail)'),
            new OA\Response(response: 400, description: 'auth.invalid_current_password'),
            new OA\Response(response: 422, description: 'validation_failed'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts (confirmation emails)'),
        ],
    )]
    #[Route('/profile', name: 'update_profile', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function updateProfile(#[MapRequestPayload] UpdateProfileRequest $payload): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $emailChanged = mb_strtolower(trim($payload->email)) !== $user->getEmail();
        if ($emailChanged
            && ($payload->currentPassword === null || !$this->passwordHasher->isPasswordValid($user, $payload->currentPassword))
        ) {
            return $this->problem('auth.invalid_current_password', 400);
        }
        if ($emailChanged && $this->verificationMailBudgetExhausted($user)) {
            return $this->problem('auth.too_many_attempts', 429);
        }

        $user
            ->setFirstName($payload->firstName)
            ->setLastName($payload->lastName)
            ->setLocale($payload->locale);

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            return $this->validationProblem($errors);
        }

        // L'email dell'account non cambia qui: il nuovo indirizzo resta in attesa finché l'utente non apre il link
        // mandato lì (verifyEmail). Un indirizzo mai dimostrato non diventa login, e la risposta è la stessa che sia
        // libero o già di un altro account: niente oracolo sugli account dell'istanza.
        if ($emailChanged) {
            $user->requestEmailChange($payload->email);
        }
        $this->em->flush();
        if ($emailChanged) {
            $this->sendEmailChangeConfirmation($user);
        }

        return $this->json(['user' => $this->userPayload($user)]);
    }

    #[OA\Put(
        summary: 'Change current user password',
        description: 'Verifies currentPassword, then sets newPassword. Every other session and remember-me cookie of the user stops working (other devices are signed out at their next request) and the push subscriptions are deleted. This session stays signed in with a new id and a new brumy_remember cookie, keeping the active organization.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: ChangePasswordRequest::class)),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Password changed'),
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

        // Le altre sessioni hanno l'impronta della vecchia password e cadono alla richiesta successiva, i cookie
        // remember-me ne avevano la firma. Questa resta: il token in sessione porta l'utente aggiornato. Id di sessione
        // nuovo (attributi conservati, org attiva compresa) e cookie remember-me nuovo, firmato con la password nuova.
        $request->getSession()->migrate(true);
        $this->rememberMe->clearRememberMeCookie();
        $this->rememberMe->createRememberMeCookie($user);
        $this->forgetDevices($user);

        return new JsonResponse(null, 204);
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

        $storedPath = $this->storage->store($file, 'avatar');
        try {
            $this->avatar->replace($user, $storedPath);
        } catch (\Throwable $e) {
            $this->storage->delete($storedPath);
            throw $e;
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

        // BinaryFileResponse è `public` di default: un file autenticato non deve finire in una cache condivisa, e
        // l'URL è lo stesso per ogni utente (niente cache nemmeno nel browser, che potrebbe servirlo all'account dopo).
        $response = new BinaryFileResponse($absolutePath, public: false);
        $response->headers->addCacheControlDirective('no-store');
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

        $this->avatar->replace($user, null);

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
        description: 'Consumes a valid (unexpired, unused) token, sets the new password (every session and remember-me cookie of the user stops working) and deletes the push subscriptions.',
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

        // Le sessioni esistenti cadono da sole (la password è cambiata); le push dei dispositivi vanno cancellate.
        $this->forgetDevices($user);
        $this->endCurrentSessionOf($user);

        return new JsonResponse(null, 204);
    }

    #[OA\Post(
        summary: 'Confirm email address with a token from the verification email',
        description: 'Consumes a valid (unexpired, unused) token. With a pending email change it makes `pendingEmail` the account email (verified), invalidates reset tokens and ends every session of the user (sessions, remember-me cookies, push subscriptions): `emailChanged: true`, log in again with the new address. If the address got an account in the meantime: 409 auth.email_taken and the pending change is dropped. Otherwise it marks the current email as verified (`emailChanged: false`).',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: VerifyEmailRequest::class)),
        ),
        responses: [
            new OA\Response(response: 200, description: '{emailChanged: bool}'),
            new OA\Response(response: 400, description: 'auth.invalid_verification_token'),
            new OA\Response(response: 409, description: 'auth.email_taken (the new address got an account meanwhile)'),
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

        $user = $token->getUser();
        if ($user->getPendingEmail() !== null) {
            $owner = $this->userRepo->findOneByEmail($user->getPendingEmail());
            if ($owner !== null && $owner->getId() !== $user->getId()) {
                $user->cancelPendingEmail();
                $this->em->flush();

                return $this->problem('auth.email_taken', 409);
            }

            $user->confirmPendingEmail();
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                // Race: un'altra richiesta ha preso l'indirizzo tra il controllo e il flush.
                return $this->problem('auth.email_taken', 409);
            }
            // Un link di reset già spedito al vecchio indirizzo non deve restare spendibile dopo il cambio; le
            // sessioni aperte (potenzialmente di chi aveva accesso prima) decadono.
            $this->resetTokenRepo->invalidateAllForUser($user);
            $this->forgetDevices($user);
            $this->endCurrentSessionOf($user);

            return $this->json(['emailChanged' => true]);
        }

        $user->markEmailVerified();
        $this->em->flush();

        return $this->json(['emailChanged' => false]);
    }

    #[OA\Post(
        summary: 'Resend the email verification link to the current user',
        description: 'Sends the confirmation link again to the pending new address, or else to the current address if it is not verified yet. No-op (still 204) otherwise. At most 3 confirmation emails per 15 minutes per user.',
        responses: [
            new OA\Response(response: 204, description: 'Verification email sent if needed'),
            new OA\Response(response: 429, description: 'auth.too_many_attempts'),
        ],
    )]
    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resendVerification(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($this->verificationMailBudgetExhausted($user)) {
            return $this->problem('auth.too_many_attempts', 429);
        }
        if ($user->getPendingEmail() !== null) {
            $this->sendEmailChangeConfirmation($user);
        } elseif (!$user->isEmailVerified()) {
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
        description: 'Public. Creates the user (email taken from the invitation, marked verified) and the accepted membership in the inviting org, then signs the user in (session + brumy_remember cookie).',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RegisterInvitedRequest::class))),
        responses: [
            new OA\Response(response: 201, description: '{user}; user created, joined and signed in'),
            new OA\Response(response: 400, description: 'invitation.invalid'),
            new OA\Response(response: 409, description: 'auth.email_taken (account exists → log in instead)'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('/invitation/register', name: 'invitation_register', methods: ['POST'])]
    public function registerInvited(#[MapRequestPayload] RegisterInvitedRequest $payload): JsonResponse
    {
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
        // della sessione è sempre quella dell'invito.
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

        return $this->signIn($user);
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
        if ($existing === null || !$existing->isAccepted()) {
            // Chi (ri)entra parte senza condivisioni: quelle rimaste da una vecchia membership (rimozione con codice
            // precedente, o dati migrati) ridarebbero accesso o proprietà dei veicoli. Le condivisioni si creano solo
            // verso membri accettati, quindi qui nessuna è legittima.
            $this->shareRepo->deleteAllForUserInOrganization($user, $org);
        }
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
                'error' => LogRedactor::scrub($e->getMessage()),
            ]);
        }
    }

    /**
     * Link di conferma per il cambio email in attesa, mandato al nuovo indirizzo dopo la risposta. Se l'indirizzo è già
     * di un altro account il token si crea comunque ma la mail non parte: stessi passi e stessi tempi di risposta,
     * e la conferma fallirebbe in ogni caso (verifyEmail ricontrolla l'unicità).
     */
    private function sendEmailChangeConfirmation(User $user): void
    {
        $pending = $user->getPendingEmail();
        if ($pending === null) {
            return;
        }
        $this->verificationTokenRepo->invalidateAllForUser($user);

        $rawToken = bin2hex(random_bytes(32));
        $this->em->persist(new EmailVerificationToken($user, hash('sha256', $rawToken), (new \DateTimeImmutable())->modify('+24 hours')));
        $this->em->flush();

        if ($this->userRepo->findOneByEmail($pending) !== null) {
            return;
        }
        $mail = $this->mailBuilder->confirmEmailChange($user, $rawToken)->to($pending);
        $this->afterResponse->defer(fn () => $this->mailer->send($mail), ['user_id' => $user->getId()]);
    }

    /** Email di verifica e conferma per utente: senza, il profilo diventerebbe un modo per spedire posta a chiunque. */
    private function verificationMailBudgetExhausted(User $user): bool
    {
        return !$this->verificationEmailLimiter->create('user-'.$user->getId())->consume()->isAccepted();
    }

    /**
     * @return array{id: int|null, email: string, pendingEmail: string|null, firstName: string, lastName: string, locale: string, hasAvatar: bool, emailVerified: bool, isInstanceAdmin: bool, twoFactorEnabled: bool, backupCodesRemaining: int}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'pendingEmail' => $user->getPendingEmail(),
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
            'twoFactorEnabled' => $user->isTotpAuthenticationEnabled(),
            'backupCodesRemaining' => $user->backupCodesRemaining(),
        ];
    }

    /**
     * Transizione di credenziali (reset/cambio password, cambio email): cancella le push subscription di tutti i
     * dispositivi, così chi aveva accesso prima non riceve più notifiche. Le sessioni e i cookie remember-me cadono da
     * soli, perché password o email non coincidono più ({@see User::isEqualTo()}); il client corrente ri-registra la
     * propria subscription.
     */
    private function forgetDevices(User $user): void
    {
        $this->pushSubscriptions->deleteAllForUser($user);
    }

    /**
     * Reset della password e conferma del cambio email chiudono ogni sessione dell'utente. Le altre cadono da sole alla
     * richiesta successiva; quella che fa la richiesta, se è dello stesso utente, porta già l'utente aggiornato e
     * resterebbe valida: si chiude con il logout del firewall (sessione, cookie e serie remember-me).
     */
    private function endCurrentSessionOf(User $user): void
    {
        $current = $this->getUser();
        if ($current instanceof User && $current->getId() === $user->getId()) {
            $this->security->logout(validateCsrfToken: false);
        }
    }

    /**
     * Apre la sessione per un utente appena registrato (registrazione del primo utente o da invito), come un login:
     * id di sessione nuovo e cookie remember-me (always_remember_me).
     */
    private function signIn(User $user): JsonResponse
    {
        $this->security->login($user, 'json_login', 'api', [new RememberMeBadge()]);

        return new JsonResponse(['user' => $this->userPayload($user)], 201);
    }

    /** Consuma un tentativo per l'IP del client; true se con questo il limite è superato. */
    private function throttled(RateLimiterFactoryInterface $factory): bool
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';

        return !$factory->create($ip)->consume()->isAccepted();
    }
}
