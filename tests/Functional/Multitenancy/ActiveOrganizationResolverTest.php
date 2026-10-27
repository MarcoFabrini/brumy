<?php

declare(strict_types=1);

namespace App\Tests\Functional\Multitenancy;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'attributo di sessione `active_org_id` non è una prova di appartenenza: a ogni richiesta
 * ActiveOrganizationResolver verifica che l'utente sia ancora membro (accettato) di
 * quell'organizzazione. Una sessione con un'org altrui, o di un membro rimosso, non apre nulla.
 * Una sessione senza org parte dall'ultima scelta dall'utente, altrimenti dalla prima membership accettata.
 */
final class ActiveOrganizationResolverTest extends ApiTestCase
{
    public function testSessionPointingToAnOrganizationTheUserDoesNotBelongToIsForbidden(): void
    {
        [$user] = $this->createAuthenticatedUser();
        [, $foreignOrg] = $this->createAuthenticatedUser();
        VehicleFactory::createOne(['organization' => $foreignOrg]);

        $this->jsonRequest('GET', '/api/vehicles', session: $this->sessionFor($user, $foreignOrg));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('auth.not_member_of_organization', $this->jsonBody()['title']);
    }

    public function testSessionOfARemovedMemberIsForbidden(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/vehicles', session: $token);
        self::assertResponseIsSuccessful('Prima della rimozione il token funziona');

        $this->em()->remove($this->membership($user, $org));
        $this->em()->flush();

        $this->jsonRequest('GET', '/api/vehicles', session: $token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('auth.not_member_of_organization', $this->jsonBody()['title']);
    }

    public function testSessionOfAMemberWhoHasNotAcceptedTheInvitationYetIsForbidden(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne([
            'user' => $user,
            'organization' => $org,
            'role' => OrgRole::MEMBER,
            'acceptedAt' => null,
        ]);

        $this->jsonRequest('GET', '/api/vehicles', session: $this->sessionFor($user, $org));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('auth.not_member_of_organization', $this->jsonBody()['title']);
    }

    public function testSessionPointingToADeletedOrganizationIsForbidden(): void
    {
        [$user] = $this->createAuthenticatedUser();
        $gone = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $gone, 'role' => OrgRole::OWNER]);
        $session = $this->sessionFor($user, $gone);
        $this->em()->remove($this->em()->find(Organization::class, $gone->getId()) ?? self::fail('org'));
        $this->em()->flush();

        $this->jsonRequest('GET', '/api/vehicles', session: $session);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('auth.organization_not_found', $this->jsonBody()['title']);
    }

    public function testSessionWithoutOrganizationStartsFromTheFirstAcceptedMembershipAndRemembersIt(): void
    {
        $user = UserFactory::createOne();
        $first = OrganizationFactory::createOne(['name' => 'Alfa']);
        $second = OrganizationFactory::createOne(['name' => 'Beta']);
        $pending = OrganizationFactory::createOne(['name' => 'Aaa in attesa']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $second, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $first, 'role' => OrgRole::MEMBER]);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $pending, 'role' => OrgRole::MEMBER, 'acceptedAt' => null]);

        $this->jsonRequest('GET', '/api/auth/me', session: $this->sessionFor($user));

        self::assertResponseIsSuccessful();
        self::assertSame($first->getId(), $this->jsonBody()['activeOrganizationId']);
        self::assertSame($first->getId(), $this->lastActiveOrganizationId($user));
    }

    public function testANewSessionStartsFromTheLastChosenOrganization(): void
    {
        [$user, $first, $session] = $this->createAuthenticatedUser();
        $second = OrganizationFactory::createOne(['name' => 'Zeta']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $second, 'role' => OrgRole::MEMBER]);

        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => $second->getId()], session: $session);
        self::assertResponseIsSuccessful();
        self::assertSame(['activeOrganizationId' => $second->getId()], $this->jsonBody());

        // Sessione nuova (browser riavviato, altro dispositivo): riparte dall'ultima scelta.
        $this->jsonRequest('GET', '/api/auth/me', session: $this->sessionFor($user));
        self::assertSame($second->getId(), $this->jsonBody()['activeOrganizationId']);

        // Uscito da quell'org, la sessione nuova torna alla prima membership accettata.
        $this->em()->remove($this->membership($user, $second));
        $this->em()->flush();
        $this->jsonRequest('GET', '/api/auth/me', session: $this->sessionFor($user));
        self::assertSame($first->getId(), $this->jsonBody()['activeOrganizationId']);
    }

    public function testSwitchingOrganizationDoesNotMoveTheOtherSessionsOfTheUser(): void
    {
        [$user, $first, $sessionA] = $this->createAuthenticatedUser();
        $sessionB = $this->sessionFor($user, $first);
        $second = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $second, 'role' => OrgRole::OWNER]);
        $vehicleInSecond = VehicleFactory::createOne(['organization' => $second]);
        \App\Tests\Factory\VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicleInSecond, 'user' => $user]);

        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => $second->getId()], session: $sessionA);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('GET', '/api/vehicles', session: $sessionA);
        self::assertSame([$vehicleInSecond->getId()], array_column($this->jsonBody(), 'id'), 'La sessione che ha cambiato org lavora sulla nuova');
        $this->jsonRequest('GET', '/api/auth/me', session: $sessionB);
        self::assertSame($first->getId(), $this->jsonBody()['activeOrganizationId'], 'Le altre sessioni restano sulla loro org');
    }

    public function testSwitchToAnUnrelatedOrPendingOrganizationIsRefused(): void
    {
        [$user, , $session] = $this->createAuthenticatedUser();
        [, $foreign] = $this->createAuthenticatedUser();
        $pending = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $pending, 'role' => OrgRole::MEMBER, 'acceptedAt' => null]);

        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => $foreign->getId()], session: $session);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('org.not_found', $this->jsonBody()['title']);

        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => 999999], session: $session);
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => $pending->getId()], session: $session);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('auth.not_member', $this->jsonBody()['title']);
    }

    private function lastActiveOrganizationId(User $user): ?int
    {
        $id = static::getContainer()->get(\Doctrine\DBAL\Connection::class)
            ->fetchOne('SELECT last_active_organization_id FROM users WHERE id = ?', [$user->getId()]);

        return $id === null ? null : (int) $id;
    }


    private function membership(User $user, Organization $org): OrganizationMember
    {
        $member = OrganizationMemberFactory::repository()->findOneBy(['user' => $user, 'organization' => $org]);
        self::assertNotNull($member);

        return $member;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
