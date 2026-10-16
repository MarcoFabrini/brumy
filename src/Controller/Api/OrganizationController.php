<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\LogRedactor;
use App\Dto\Request\MemberInviteRequest;
use App\Dto\Request\MemberRoleRequest;
use App\Dto\Request\OrganizationRequest;
use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Repository\OrganizationInvitationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Security\Voter\OrganizationVoter;
use App\Service\AppMailer;
use App\Service\AttachmentCleaner;
use App\Service\MailBuilder;
use App\Service\MemberNotifier;
use App\Service\MemberRoleChangeException;
use App\Service\OrganizationMemberRemover;
use App\Service\OrganizationMemberRoleChanger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[OA\Tag(name: 'Organization')]
#[Route('/api/organizations', name: 'api_organizations_')]
#[IsGranted('ROLE_USER')]
final class OrganizationController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizationRepository $orgRepo,
        private readonly OrganizationMemberRepository $memberRepo,
        private readonly OrganizationInvitationRepository $invitationRepo,
        private readonly UserRepository $userRepo,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly SluggerInterface $slugger,
        private readonly AppMailer $mailer,
        private readonly MailBuilder $mailBuilder,
        private readonly LoggerInterface $logger,
        private readonly AttachmentCleaner $attachmentCleaner,
        private readonly OrganizationMemberRemover $memberRemover,
        private readonly OrganizationMemberRoleChanger $roleChanger,
        private readonly MemberNotifier $memberNotifier,
        private readonly RateLimiterFactoryInterface $inviteLimiter, // framework.yaml: rate_limiter.invite
    ) {
    }

    #[OA\Get(
        summary: 'List organizations the user belongs to',
        responses: [new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Organization::class, groups: ['org:list']))))],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $memberships = $this->memberRepo->findAllForUser($user);
        $orgs = array_map(static fn (OrganizationMember $m) => $m->getOrganization(), $memberships);
        return $this->jsonGroups($orgs, ['org:list']);
    }

    #[OA\Get(
        summary: 'Organization detail',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Organization', content: new OA\JsonContent(ref: new Model(type: Organization::class, groups: ['org:read']))),
            new OA\Response(response: 403, description: 'Not a member'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $org = $this->mustFind($id);
        $this->denyAccessUnlessGranted(OrganizationVoter::VIEW, $org);
        return $this->jsonGroups($org, ['org:read']);
    }

    #[OA\Post(
        summary: 'Create a new organization (instance administrator only; the creator becomes owner)',
        description: 'Instance administrator only (the first registered user): registration already creates the first organization and invited users join an existing one, so any other account creating organizations is just an abuse vector.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: OrganizationRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: new Model(type: Organization::class, groups: ['org:read']))),
            new OA\Response(response: 403, description: 'org.create_forbidden'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] OrganizationRequest $payload): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->userRepo->isInstanceAdmin($user)) {
            return $this->problem('org.create_forbidden', 403);
        }

        $org = (new Organization())
            ->setName($payload->name)
            ->setSlug($payload->slug ?? $this->buildUniqueSlug($payload->name, $user->getId()));

        $errors = $this->validator->validate($org);
        if (count($errors) > 0) {
            return $this->validationProblem($errors);
        }

        $membership = (new OrganizationMember())
            ->setOrganization($org)
            ->setUser($user)
            ->setRole(OrgRole::OWNER)
            ->setAcceptedAt(new \DateTimeImmutable());

        $this->em->persist($org);
        $this->em->persist($membership);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Controllo e INSERT non sono atomici: due richieste con lo stesso indirizzo nello stesso
            // istante passano entrambe la validazione e la seconda cade sull'indice unico.
            return $this->fieldProblem('slug', 'org.slug_taken');
        }

        return $this->jsonGroups($org, ['org:read'], 201);
    }

    #[OA\Put(
        summary: 'Update organization (owner/admin only)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: OrganizationRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: new Model(type: Organization::class, groups: ['org:read']))),
            new OA\Response(response: 403, description: 'No EDIT permission (member role)'),
        ],
    )]
    #[Route('/{id}', name: 'update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[MapRequestPayload] OrganizationRequest $payload): JsonResponse
    {
        $org = $this->mustFind($id);
        $this->denyAccessUnlessGranted(OrganizationVoter::EDIT, $org);

        $org->setName($payload->name);
        if ($payload->slug !== null && $payload->slug !== '') {
            $org->setSlug($payload->slug);
        }

        $errors = $this->validator->validate($org);
        if (count($errors) > 0) {
            return $this->validationProblem($errors);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->fieldProblem('slug', 'org.slug_taken'); // stessa corsa della creazione
        }

        return $this->jsonGroups($org, ['org:read']);
    }

    #[OA\Delete(
        summary: 'Delete organization (owner only — cascades to all data)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Owner only'),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $org = $this->mustFind($id);
        $this->denyAccessUnlessGranted(OrganizationVoter::DELETE, $org);

        // FK ON DELETE CASCADE rimuoverà membri, veicoli, manutenzioni ecc.
        // I file degli allegati (non coperti dal cascade) li elimina il cleaner dopo il commit.
        $this->attachmentCleaner->removeOrganization($org);
        return new JsonResponse(null, 204);
    }

    // ----- Members -----

    #[OA\Get(
        summary: 'List org members',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Array of memberships', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: OrganizationMember::class, groups: ['membership:read', 'user:list'])))),
        ],
    )]
    #[Route('/{id}/members', name: 'members_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listMembers(int $id): JsonResponse
    {
        $org = $this->mustFind($id);
        // Solo owner/admin vede la lista membri (un membro non gestisce l'org).
        $this->denyAccessUnlessGranted(OrganizationVoter::MANAGE_MEMBERS, $org);

        $members = $this->memberRepo->findBy(['organization' => $org], ['createdAt' => 'ASC']);
        return $this->jsonGroups($members, ['membership:read', 'user:list']);
    }

    #[OA\Get(
        summary: 'List pending invitations for the org',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Array of pending invitations', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: OrganizationInvitation::class, groups: ['invitation:read'])))),
        ],
    )]
    #[Route('/{id}/invitations', name: 'invitations_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listInvitations(int $id): JsonResponse
    {
        $org = $this->mustFind($id);
        $this->denyAccessUnlessGranted(OrganizationVoter::MANAGE_MEMBERS, $org);

        return $this->jsonGroups($this->invitationRepo->findPendingByOrganization($org), ['invitation:read']);
    }

    /**
     * Invita un indirizzo email all'org (l'invitato può non avere ancora un account:
     * lo creerà all'accettazione). Crea un OrganizationInvitation pendente e manda
     * l'email con token (7 giorni). Un solo invito attivo per (org, email): un nuovo
     * invito invalida i precedenti. 409 solo se l'email è già membro accettato.
     */
    #[OA\Post(
        summary: 'Invite an email address to the org',
        description: 'Owner/admin only. Works for non-registered emails (they sign up on accept). Resends if a pending invite exists. 409 only if already a member. At most 20 invitations per hour per inviting user (429 member.invite_rate_limited). A failure sending the email is logged but does not fail the request: the invitation is created and can be re-sent.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MemberInviteRequest::class))),
        responses: [
            new OA\Response(response: 201, description: 'Invitation sent', content: new OA\JsonContent(ref: new Model(type: OrganizationInvitation::class, groups: ['invitation:read']))),
            new OA\Response(response: 409, description: 'member.already_exists'),
            new OA\Response(response: 429, description: 'member.invite_rate_limited'),
        ],
    )]
    #[Route('/{id}/members', name: 'members_invite', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function inviteMember(int $id, #[MapRequestPayload] MemberInviteRequest $payload): JsonResponse
    {
        $org = $this->mustFind($id);
        $this->denyAccessUnlessGranted(OrganizationVoter::MANAGE_MEMBERS, $org);

        /** @var User $inviter */
        $inviter = $this->getUser();
        // Prima di ogni altro controllo: anche i 409 "già membro" costano una richiesta a chi abusa.
        if (!$this->inviteLimiter->create((string) $inviter->getId())->consume()->isAccepted()) {
            return $this->problem('member.invite_rate_limited', 429);
        }

        $email = mb_strtolower(trim($payload->email));

        // Se l'email è già di un utente che è già membro → 409.
        $existingUser = $this->userRepo->findOneByEmail($email);
        if ($existingUser !== null && $this->memberRepo->findMembership($existingUser, $org) !== null) {
            return $this->problem('member.already_exists', 409);
        }

        // Solo un OWNER può creare altri OWNER: un ADMIN non può auto-promuoversi via invito.
        if ($payload->role === OrgRole::OWNER
            && $this->memberRepo->findMembership($inviter, $org)?->getRole() !== OrgRole::OWNER
        ) {
            return $this->problem('member.owner_role_forbidden', 403);
        }

        // Un solo invito pendente per (org, email): invalida i precedenti (re-invito).
        $this->invitationRepo->invalidateForOrgEmail($org, $email);

        $rawToken = bin2hex(random_bytes(32));
        $invitation = new OrganizationInvitation(
            $org,
            $email,
            $payload->role,
            hash('sha256', $rawToken),
            (new \DateTimeImmutable())->modify('+7 days'),
            $inviter,
        );
        $this->em->persist($invitation);
        $this->em->flush();

        try {
            $this->mailer->send(
                $this->mailBuilder->invitation($email, $org, $inviter, $rawToken, $inviter->getLocale())->to($email),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Invitation email failed', [
                'org_id' => $org->getId(),
                'invitation_id' => $invitation->getId(),
                'error' => LogRedactor::scrub($e->getMessage()),
            ]);
        }

        return $this->jsonGroups($invitation, ['invitation:read'], 201);
    }

    #[OA\Delete(
        summary: 'Revoke a pending invitation',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'invId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Revoked'),
            new OA\Response(response: 404, description: 'invitation.not_found'),
        ],
    )]
    #[Route('/{id}/invitations/{invId}', name: 'invitations_revoke', methods: ['DELETE'], requirements: ['id' => '\d+', 'invId' => '\d+'])]
    public function revokeInvitation(int $id, int $invId): JsonResponse
    {
        $org = $this->mustFind($id);
        $this->denyAccessUnlessGranted(OrganizationVoter::MANAGE_MEMBERS, $org);

        $invitation = $this->invitationRepo->find($invId);
        if (!$invitation || $invitation->getOrganization()->getId() !== $org->getId()) {
            return $this->problem('invitation.not_found', 404);
        }

        $this->em->remove($invitation);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    #[OA\Patch(
        summary: 'Change the organization role of a member',
        description: 'Strict hierarchy. Owner: any role to anyone (co-owners are explicit; an owner can step down while another owner remains). Admin: can only promote a `member` to `admin`; never demotes, never touches another admin or an owner, never assigns `owner`, cannot change their own role. Same role = 200, no audit row, no notification. The organization always keeps at least one accepted owner (409 member.last_owner). Demoting a member deletes the pending invitations they sent in this organization. Takes effect on the member\'s next request (the role is not in the JWT). The affected member is notified by email and push (best-effort, failures are logged only).',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'memberId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: MemberRoleRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'Updated membership', content: new OA\JsonContent(ref: new Model(type: OrganizationMember::class, groups: ['membership:read', 'user:list']))),
            new OA\Response(response: 403, description: 'member.cannot_change_owner_role | member.cannot_change_admin_role | member.cannot_change_own_role | member.owner_role_forbidden | no MANAGE_MEMBERS permission'),
            new OA\Response(response: 404, description: 'org.not_found | member.not_found'),
            new OA\Response(response: 409, description: 'member.last_owner'),
            new OA\Response(response: 422, description: 'validation_failed'),
        ],
    )]
    #[Route('/{id}/members/{memberId}', name: 'members_update_role', methods: ['PATCH'], requirements: ['id' => '\d+', 'memberId' => '\d+'])]
    public function updateMemberRole(int $id, int $memberId, #[MapRequestPayload] MemberRoleRequest $payload): JsonResponse
    {
        $org = $this->mustFind($id);
        $this->denyAccessUnlessGranted(OrganizationVoter::MANAGE_MEMBERS, $org);

        $member = $this->memberRepo->find($memberId);
        if (!$member || $member->getOrganization()->getId() !== $org->getId() || !$member->isAccepted()) {
            return $this->problem('member.not_found', 404);
        }

        /** @var User $actor */
        $actor = $this->getUser();
        try {
            $changed = $this->roleChanger->change($member, $payload->role, $actor);
        } catch (MemberRoleChangeException $e) {
            return $this->problem($e->key, $e->status);
        }

        // Dopo il commit e solo se è cambiato qualcosa: l'avviso non deve mai far fallire il cambio.
        if ($changed) {
            $this->memberNotifier->roleChanged($member, $actor);
        }

        return $this->jsonGroups($member, ['membership:read', 'user:list']);
    }

    #[OA\Delete(
        summary: 'Remove member from organization',
        description: 'An owner cannot be removed: demote them first (PATCH the member role). The vehicles the member owns are transferred to the user performing the removal; the member\'s other vehicle shares in the organization are dropped.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'memberId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 403, description: 'member.cannot_remove_admin (only an owner can remove an admin; an admin may only remove themselves)'),
            new OA\Response(response: 404, description: 'member.not_found'),
            new OA\Response(response: 409, description: 'member.cannot_remove_owner'),
        ],
    )]
    #[Route('/{id}/members/{memberId}', name: 'members_delete', methods: ['DELETE'], requirements: ['id' => '\d+', 'memberId' => '\d+'])]
    public function removeMember(int $id, int $memberId): JsonResponse
    {
        $org = $this->mustFind($id);
        $this->denyAccessUnlessGranted(OrganizationVoter::MANAGE_MEMBERS, $org);

        $member = $this->memberRepo->find($memberId);
        if (!$member || $member->getOrganization()->getId() !== $org->getId()) {
            return $this->problem('member.not_found', 404);
        }

        if ($member->getRole() === OrgRole::OWNER) {
            return $this->problem('member.cannot_remove_owner', 409);
        }

        /** @var User $actor */
        $actor = $this->getUser();

        // Gli admin li rimuove solo un owner (come per il cambio ruolo): un admin può solo andarsene da sé.
        $actorRole = $this->memberRepo->findMembership($actor, $org)?->getRole();
        if ($member->getRole() === OrgRole::ADMIN
            && $member->getUser()->getId() !== $actor->getId()
            && $actorRole !== OrgRole::OWNER
        ) {
            return $this->problem('member.cannot_remove_admin', 403);
        }

        // I veicoli del membro passano a chi lo rimuove (altrimenti resterebbero senza proprietario
        // e nessuno riceverebbe più le notifiche); le sue altre share dell'org cadono con la membership.
        $this->memberRemover->remove($member, $actor);

        return new JsonResponse(null, 204);
    }

    // ----- helpers -----

    private function mustFind(int $id): Organization
    {
        $org = $this->orgRepo->find($id);
        // Per chi non ha nessun legame con l'org (nemmeno un invito in attesa) l'id inesistente e quello altrui
        // sono indistinguibili: stesso 404. Un membro senza i permessi giusti resta 403 dal voter.
        /** @var User $user */
        $user = $this->getUser();
        if (!$org || $this->memberRepo->findMembership($user, $org) === null) {
            throw $this->createNotFoundException('org.not_found');
        }
        return $org;
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

    private function jsonGroups(mixed $data, array $groups, int $status = 200): JsonResponse
    {
        return new JsonResponse($this->serializer->serialize($data, 'json', ['groups' => $groups]), $status, [], json: true);
    }
}
