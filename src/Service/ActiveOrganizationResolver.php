<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationMemberRepository;
use App\Repository\OrganizationRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Risolve l'`Organization` corrente partendo dal claim JWT `active_org_id`.
 * Verifica che l'utente sia effettivamente membro dell'organizzazione.
 */
final class ActiveOrganizationResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly OrganizationRepository $orgRepo,
        private readonly OrganizationMemberRepository $memberRepo,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
    }

    public function resolve(): Organization
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'auth.not_authenticated');
        }

        $payload = $this->extractJwtPayload();
        $orgId = $payload['active_org_id'] ?? null;

        if (!is_int($orgId) && !ctype_digit((string) $orgId)) {
            throw new AccessDeniedHttpException('auth.no_active_organization');
        }

        $org = $this->orgRepo->find((int) $orgId);
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
     * In Lexik 3.x il payload JWT non è disponibile come attribute sul token finale.
     * Ricaviamo il payload decodificando il JWT raw esposto da JWTPostAuthenticationToken::getCredentials().
     *
     * @return array<string, mixed>
     */
    private function extractJwtPayload(): array
    {
        $token = $this->security->getToken();
        if (!$token instanceof JWTPostAuthenticationToken) {
            return [];
        }
        $raw = $token->getCredentials();
        try {
            return $this->jwtManager->parse($raw);
        } catch (\Throwable) {
            return [];
        }
    }
}
