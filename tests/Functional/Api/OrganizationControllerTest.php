<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\OrgRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use App\Repository\OrganizationRepository;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

final class OrganizationControllerTest extends ApiTestCase
{
    use MailerAssertionsTrait;

    public function testListReturnsOnlyUserOrganizations(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();

        // Crea un'altra org dove l'utente NON è membro
        OrganizationFactory::createOne();

        $this->jsonRequest('GET', '/api/organizations', session: $token);

        self::assertResponseIsSuccessful();
        /** @var list<array<string, mixed>> $orgs */
        $orgs = $this->jsonBody();
        self::assertCount(1, $orgs);
        self::assertSame($org->getId(), $orgs[0]['id']);
    }

    public function testCreateOrganizationMakesUserOwner(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/organizations', [
            'name' => 'Famiglia Rossi',
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Famiglia Rossi', $this->jsonBody()['name']);
        self::assertNotEmpty($this->jsonBody()['slug']);

        // Tramite /me l'utente deve avere ora 2 memberships
        $this->jsonRequest('GET', '/api/auth/me', session: $token);
        self::assertCount(2, $this->jsonBody()['memberships']);
    }

    public function testCreateOrganizationIsForbiddenToNonInstanceAdmins(): void
    {
        // Il primo utente è l'admin di istanza: gli altri (qualunque ruolo nella loro org) non creano org
        $this->createAuthenticatedUser();
        foreach ([OrgRole::OWNER, OrgRole::ADMIN, OrgRole::MEMBER] as $role) {
            [, , $token] = $this->createAuthenticatedUser($role);

            $this->jsonRequest('POST', '/api/organizations', ['name' => 'Abuso '.$role->value], session: $token);

            self::assertResponseStatusCodeSame(403);
            self::assertSame('org.create_forbidden', $this->jsonBody()['title']);
        }
        self::assertCount(4, OrganizationFactory::all(), 'Nessuna organizzazione creata oltre alle 4 dei test');
    }

    public function testSlugAlreadyUsedIsAFieldError(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        OrganizationFactory::createOne(['slug' => 'famiglia-rossi']);

        $this->jsonRequest('POST', '/api/organizations', ['name' => 'Famiglia Rossi', 'slug' => 'famiglia-rossi'], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertContains(['field' => 'slug', 'message' => 'org.slug_taken'], $this->jsonBody()['errors']);
    }

    public function testCreateWithASlugTakenAfterTheCheckIsStillAFieldErrorNotA500(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->takeSlugRightBeforeTheNextFlush('corsa');

        $this->jsonRequest('POST', '/api/organizations', ['name' => 'Corsa', 'slug' => 'corsa'], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertContains(['field' => 'slug', 'message' => 'org.slug_taken'], $this->jsonBody()['errors']);
    }

    public function testUpdateWithASlugTakenAfterTheCheckIsStillAFieldErrorNotA500(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $this->takeSlugRightBeforeTheNextFlush('corsa-update');

        $this->jsonRequest('PUT', '/api/organizations/'.$org->getId(), ['name' => 'Nuovo nome', 'slug' => 'corsa-update'], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertContains(['field' => 'slug', 'message' => 'org.slug_taken'], $this->jsonBody()['errors']);
    }

    /**
     * Simula la corsa: un'altra richiesta inserisce lo slug dopo la validazione e prima del flush,
     * così il controllo UniqueEntity passa e a fallire è l'indice unico.
     */
    private function takeSlugRightBeforeTheNextFlush(string $slug): void
    {
        $this->client->disableReboot(); // stesso container (e stesso EntityManager) per tutta la richiesta
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getEventManager()->addEventListener(Events::preFlush, new class($em->getConnection(), $slug) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly string $slug)
            {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                if (!$this->done) {
                    $this->done = true;
                    $this->connection->insert('organizations', ['name' => 'Arrivata prima', 'slug' => $this->slug]);
                }
            }
        });
    }

    public function testGetReturnsNotFoundForNonMember(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $other = OrganizationFactory::createOne();

        // Come un id inesistente: l'esistenza di org altrui non si deduce dallo status.
        $this->jsonRequest('GET', '/api/organizations/'.$other->getId(), session: $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateRequiresAdminRole(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);

        $this->jsonRequest('PUT', '/api/organizations/'.$org->getId(), [
            'name' => 'Updated',
        ], session: $token);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateAsOwner(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/organizations/'.$org->getId(), [
            'name' => 'New Name',
        ], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('New Name', $this->jsonBody()['name']);
    }

    public function testDeleteRequiresOwner(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser(OrgRole::ADMIN);

        $this->jsonRequest('DELETE', '/api/organizations/'.$org->getId(), session: $token);
        self::assertResponseStatusCodeSame(403, 'Solo owner può cancellare org');
    }

    public function testDeleteAsOwner(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $id = $org->getId();

        $this->jsonRequest('DELETE', '/api/organizations/'.$id, session: $token);
        self::assertResponseStatusCodeSame(204);

        $repo = static::getContainer()->get(OrganizationRepository::class);
        self::assertNull($repo->find($id));
    }

    // ----- Members -----

    public function testInviteEmailsAcceptLinkAndListsPending(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/organizations/'.$org->getId().'/members', [
            'email' => 'Newcomer@Test.it',
            'role' => OrgRole::MEMBER,
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('newcomer@test.it', $this->jsonBody()['email'], 'Email normalizzata lowercase');
        self::assertSame('member', $this->jsonBody()['role']);

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(1, preg_match('/accept-invite\?token=[a-f0-9]+/', $message->toString()), 'Email con link di accettazione');

        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/invitations', session: $token);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonBody());
    }

    public function testInviteNonRegisteredEmailSucceeds(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/organizations/'.$org->getId().'/members', [
            'email' => 'ghost@nowhere.test',
            'role' => 'member',
        ], session: $token);

        self::assertResponseStatusCodeSame(201, 'Si può invitare anche chi non ha ancora un account');
    }

    public function testListMembersIncludesUserInfo(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/members', session: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertNotEmpty($body);
        $first = reset($body);
        self::assertIsArray($first);
        self::assertArrayHasKey('user', $first, 'La lista membri deve esporre l\'utente');
        self::assertIsArray($first['user']);
        self::assertArrayHasKey('email', $first['user']);
    }

    public function testListMembersRequiresAdminRole(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);

        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/members', session: $token);
        self::assertResponseStatusCodeSame(403, 'Un membro non vede la lista utenti dell\'org');
    }

    public function testReInviteSameEmailResendsAndKeepsOnePending(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $invite = fn () => $this->jsonRequest('POST', '/api/organizations/'.$org->getId().'/members', [
            'email' => 'pending@test.it',
            'role' => 'member',
        ], session: $token);

        $invite();
        self::assertResponseStatusCodeSame(201);
        $invite();
        self::assertResponseStatusCodeSame(201, 'Re-invito rimanda, non 409');

        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/invitations', session: $token);
        self::assertCount(1, $this->jsonBody(), 'Un solo invito pendente per email');
    }

    public function testInvitationEmailFailureDoesNotTurnIntoA500(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $this->client->disableReboot();
        // Il trasporto SMTP "cade": qualunque invio solleva
        $attempts = 0;
        static::getContainer()->get('event_dispatcher')->addListener(
            MessageEvent::class,
            static function () use (&$attempts): void {
                ++$attempts;
                throw new TransportException('SMTP giù');
            },
        );

        $this->jsonRequest('POST', '/api/organizations/'.$org->getId().'/members', ['email' => 'unlucky@test.it', 'role' => 'member'], session: $token);

        self::assertSame(1, $attempts, 'L\'invio è stato tentato ed è fallito');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('unlucky@test.it', $this->jsonBody()['email']);
        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/invitations', session: $token);
        self::assertSame(['unlucky@test.it'], array_column($this->jsonBody(), 'email'), 'L\'invito esiste anche senza email');
    }

    public function testInvitationsAreRateLimitedPerInvitingUser(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        [, $otherOrg, $otherToken] = $this->createAuthenticatedUser();
        $this->keepRateLimiterCountersForTheWholeTest();
        $invite = fn (int $orgId, string $session, int $n) => $this->jsonRequest('POST', '/api/organizations/'.$orgId.'/members', [
            'email' => "guest$n@test.it", 'role' => 'member',
        ], session: $session);

        for ($n = 1; $n <= 20; ++$n) {
            $invite((int) $org->getId(), $token, $n);
            self::assertResponseStatusCodeSame(201, "invito $n");
        }
        $invite((int) $org->getId(), $token, 21);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('member.invite_rate_limited', $this->jsonBody()['title']);
        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/invitations', session: $token);
        self::assertCount(20, $this->jsonBody(), 'Il 21° invito non viene creato');

        // Il limite è per utente che invita: un altro admin non ne risente
        $invite((int) $otherOrg->getId(), $otherToken, 1);
        self::assertResponseStatusCodeSame(201);
    }

    public function testRevokeInvitation(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/organizations/'.$org->getId().'/members', [
            'email' => 'revoke@test.it',
            'role' => 'member',
        ], session: $token);
        self::assertResponseStatusCodeSame(201);
        $invId = $this->jsonBody()['id'];
        self::assertIsInt($invId);

        $this->jsonRequest('DELETE', '/api/organizations/'.$org->getId().'/invitations/'.$invId, session: $token);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/invitations', session: $token);
        self::assertCount(0, $this->jsonBody());
    }

    public function testInviteAlreadyMemberReturns409(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $existing = UserFactory::createOne(['email' => 'already@test.it']);
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $existing]);

        $this->jsonRequest('POST', '/api/organizations/'.$org->getId().'/members', [
            'email' => 'already@test.it',
            'role' => 'member',
        ], session: $token);

        self::assertResponseStatusCodeSame(409);
    }

    public function testAdminCannotInviteAnOwner(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser(OrgRole::ADMIN);

        $this->jsonRequest('POST', '/api/organizations/'.$org->getId().'/members', [
            'email' => 'sneaky@test.it',
            'role' => 'owner',
        ], session: $token);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanInviteAnOwner(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/organizations/'.$org->getId().'/members', [
            'email' => 'coowner@test.it',
            'role' => 'owner',
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCannotRemoveOwner(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $ownerMembership = static::getContainer()
            ->get(\App\Repository\OrganizationMemberRepository::class)
            ->findMembership($owner, $org);
        self::assertNotNull($ownerMembership);

        $this->jsonRequest('DELETE', '/api/organizations/'.$org->getId().'/members/'.$ownerMembership->getId(), session: $token);
        self::assertResponseStatusCodeSame(409);
    }

    // ----- switch-org -----

    public function testSwitchOrgChangesTheActiveOrganizationOfTheSession(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        // Aggiungo all'utente una seconda org
        $org2 = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne([
            'organization' => $org2,
            'user' => $user,
            'role' => OrgRole::OWNER,
        ]);

        $this->jsonRequest('POST', '/api/auth/switch-org', [
            'organizationId' => $org2->getId(),
        ], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['activeOrganizationId' => $org2->getId()], $this->jsonBody());

        $this->jsonRequest('GET', '/api/auth/me', session: $token);
        self::assertSame($org2->getId(), $this->jsonBody()['activeOrganizationId']);
    }

    public function testSwitchOrgRefusesNonMember(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $other = OrganizationFactory::createOne();

        $this->jsonRequest('POST', '/api/auth/switch-org', [
            'organizationId' => $other->getId(),
        ], session: $token);

        self::assertResponseStatusCodeSame(404);
    }
}
