<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Gestione di membri e inviti: l'id nell'URL dice quale organizzazione si autorizza, ma l'id della
 * membership o dell'invito deve appartenere ANCHE a quella organizzazione (altrimenti un admin
 * potrebbe agire sui membri o sugli inviti di un'altra).
 */
final class OrganizationAccessTest extends ApiTestCase
{
    public function testAnAdminCannotRemoveAMembershipOfAnotherOrganizationThroughHisOwnOrgUrl(): void
    {
        [, $orgA, $tokenA] = $this->createAuthenticatedUser(); // owner di A
        [, $orgB] = $this->createAuthenticatedUser();
        $victim = OrganizationMemberFactory::createOne(['organization' => $orgB, 'user' => UserFactory::createOne(), 'role' => OrgRole::MEMBER]);

        $this->jsonRequest('DELETE', '/api/organizations/'.$orgA->getId().'/members/'.$victim->getId(), accessToken: $tokenA);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('member.not_found', $this->jsonBody()['title']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM organization_members WHERE id = ?', [$victim->getId()]), 'La membership altrui non è stata toccata');
    }

    public function testAnAdminCannotRevokeAnInvitationOfAnotherOrganizationThroughHisOwnOrgUrl(): void
    {
        [, $orgA, $tokenA] = $this->createAuthenticatedUser();
        [, $orgB] = $this->createAuthenticatedUser();
        $invitation = new OrganizationInvitation($orgB, 'guest@test.it', OrgRole::MEMBER, str_repeat('a', 64), new \DateTimeImmutable('+7 days'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($invitation);
        $em->flush();

        $this->jsonRequest('DELETE', '/api/organizations/'.$orgA->getId().'/invitations/'.$invitation->getId(), accessToken: $tokenA);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('invitation.not_found', $this->jsonBody()['title']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM organization_invitations WHERE id = ?', [$invitation->getId()]));
    }

    public function testAnotherOrganizationIsIndistinguishableFromAnUnknownOne(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        [, $foreign] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/organizations/'.$foreign->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('org.not_found', $this->jsonBody()['title']);
        $foreignBody = $this->jsonBody();

        $this->jsonRequest('GET', '/api/organizations/999999', accessToken: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreignBody, $this->jsonBody(), 'Stessa risposta: gli id delle altre org non si enumerano');
    }

    public function testManagingAnotherOrganizationDirectlyIsNotFound(): void
    {
        [, $orgA, $tokenA] = $this->createAuthenticatedUser();
        [, $orgB] = $this->createAuthenticatedUser();
        $victim = OrganizationMemberFactory::createOne(['organization' => $orgB, 'user' => UserFactory::createOne(), 'role' => OrgRole::MEMBER]);

        $this->jsonRequest('DELETE', '/api/organizations/'.$orgB->getId().'/members/'.$victim->getId(), accessToken: $tokenA);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('POST', '/api/organizations/'.$orgB->getId().'/members', ['email' => 'x@test.it', 'role' => 'member'], accessToken: $tokenA);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', '/api/organizations/'.$orgB->getId().'/invitations', accessToken: $tokenA);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('PUT', '/api/organizations/'.$orgB->getId(), ['name' => 'Rubata'], accessToken: $tokenA);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('DELETE', '/api/organizations/'.$orgB->getId(), accessToken: $tokenA);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($orgB->getName(), $this->db()->fetchOne('SELECT name FROM organizations WHERE id = ?', [$orgB->getId()]));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('adminOnlyActions')]
    public function testAPlainMemberOfTheOrganizationCannotManageIt(string $method, string $path, array $body): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $token = $this->tokenOfNewMember($org, OrgRole::MEMBER);
        $other = OrganizationMemberFactory::createOne(['organization' => $org, 'user' => UserFactory::createOne(), 'role' => OrgRole::MEMBER]);
        $path = strtr($path, ['{org}' => (string) $org->getId(), '{member}' => (string) $other->getId()]);

        $this->jsonRequest($method, $path, $body === [] ? null : $body, accessToken: $token);

        self::assertResponseStatusCodeSame(403, "$method $path");
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM organization_members WHERE id = ?', [$other->getId()]));
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function adminOnlyActions(): iterable
    {
        yield 'invite' => ['POST', '/api/organizations/{org}/members', ['email' => 'x@test.it', 'role' => 'member']];
        yield 'list invitations' => ['GET', '/api/organizations/{org}/invitations', []];
        yield 'remove member' => ['DELETE', '/api/organizations/{org}/members/{member}', []];
        yield 'update' => ['PUT', '/api/organizations/{org}', ['name' => 'Nuovo nome']];
        yield 'delete' => ['DELETE', '/api/organizations/{org}', []];
    }

    public function testAnAdminManagesMembersButCannotDeleteTheOrganization(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $adminToken = $this->tokenOfNewMember($org, OrgRole::ADMIN);

        $this->jsonRequest('PUT', '/api/organizations/'.$org->getId(), ['name' => 'Rinominata da admin'], accessToken: $adminToken);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('DELETE', '/api/organizations/'.$org->getId(), accessToken: $adminToken);
        self::assertResponseStatusCodeSame(403, 'Solo l\'owner elimina l\'organizzazione (e a cascata tutti i dati)');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM organizations WHERE id = ?', [$org->getId()]));
    }

    public function testAPendingMembershipGivesNoAccessToTheOrganization(): void
    {
        $org = OrganizationFactory::createOne();
        $user = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $user, 'role' => OrgRole::ADMIN, 'acceptedAt' => null]);
        $token = $this->jwtFor($user, $org);

        $this->jsonRequest('GET', '/api/organizations/'.$org->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(403);
        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/invitations', accessToken: $token);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownOrganizationIsNotFoundWithTheI18nKey(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/organizations/999999', accessToken: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('org.not_found', $this->jsonBody()['title']);
    }

    public function testUpdateValidatesTheNameAndKeepsTheOldOne(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $before = $org->getName();

        $this->jsonRequest('PUT', '/api/organizations/'.$org->getId(), ['name' => ''], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('name', $this->jsonBody()['errors'][0]['field']);
        self::assertSame($before, $this->db()->fetchOne('SELECT name FROM organizations WHERE id = ?', [$org->getId()]));
    }

    // -------------------- helpers --------------------

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function tokenOfNewMember(Organization $org, OrgRole $role): string
    {
        $user = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $user, 'role' => $role]);

        return $this->jwtFor($user, $org);
    }

    private function jwtFor(User $user, Organization $org): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)
            ->createFromPayload($user, ['user_id' => $user->getId(), 'active_org_id' => $org->getId()]);
    }
}
