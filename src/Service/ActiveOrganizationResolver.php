<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Risolve l'`Organization` corrente dall'attributo di sessione `active_org_id` e verifica a ogni richiesta che l'utente
 * ne sia ancora membro accettato (un'org da cui è stato rimosso dà 403).
 *
 * Una sessione nuova (login, ripresa da remember-me, altro dispositivo) non ha l'attributo: si riparte dall'ultima org
 * scelta dall'utente (`users.last_active_organization_id`) se ne è ancora membro accettato, altrimenti dalla prima
 * membership accettata, e la scelta si memorizza.
 */
final class ActiveOrganizationResolver
{
    public const SESSION_KEY = 'active_org_id';

    public function __construct(
        private readonly Security $security,
        private readonly OrganizationRepository $orgRepo,
        private readonly OrganizationMemberRepository $memberRepo,
        private readonly UserRepository $userRepo,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function resolve(): Organization
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Session', 'auth.not_authenticated');
        }

        $session = $this->session();
        $orgId = $session?->get(self::SESSION_KEY);
        if ($orgId === null) {
            $org = $this->defaultFor($user);
            if ($org === null) {
                throw new AccessDeniedHttpException('auth.no_active_organization');
            }
            $this->remember($user, $org);

            return $org;
        }

        if (!\is_int($orgId)) {
            throw new AccessDeniedHttpException('auth.no_active_organization');
        }

        $org = $this->orgRepo->find($orgId);
        if (!$org) {
            throw new AccessDeniedHttpException('auth.organization_not_found');
        }

        $membership = $this->memberRepo->findMembership($user, $org);
        if (!$membership || !$membership->isAccepted()) {
            throw new AccessDeniedHttpException('auth.not_member_of_organization');
        }

        return $org;
    }

    /**
     * Risolve l'org senza lanciare eccezione (utile nei voter).
     */
    public function tryResolve(): ?Organization
    {
        try {
            return $this->resolve();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Rende $organization l'org attiva della sessione corrente e l'ultima scelta dell'utente. Il chiamante ha già
     * verificato che $user ne sia membro accettato (switch-org, default di una sessione nuova).
     */
    public function remember(User $user, Organization $organization): void
    {
        $this->session()?->set(self::SESSION_KEY, $organization->getId());
        if ($user->getLastActiveOrganization()?->getId() !== $organization->getId()) {
            $this->userRepo->rememberActiveOrganization($user, $organization);
        }
    }

    private function defaultFor(User $user): ?Organization
    {
        $last = $user->getLastActiveOrganization();
        if ($last !== null && $this->memberRepo->findMembership($user, $last)?->isAccepted()) {
            return $last;
        }

        return ($this->memberRepo->findAllForUser($user)[0] ?? null)?->getOrganization();
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();

        return $request !== null && $request->hasSession() ? $request->getSession() : null;
    }
}
