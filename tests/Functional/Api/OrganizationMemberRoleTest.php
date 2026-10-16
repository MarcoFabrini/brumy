<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationMember;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Enum\PushPlatform;
use App\Repository\OrganizationMemberRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\VehicleRepository;
use App\Service\AppMailer;
use App\Service\MailBuilder;
use App\Service\MemberNotifier;
use App\Service\Push\FakePushNotifier;
use App\Service\Push\PushDispatcher;
use App\Service\Push\PushNotifierInterface;
use App\Service\Push\PushDeliveryResult;
use App\Service\Push\PushPayload;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ChartFixtures;
use App\Tests\Support\SpyLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * PATCH /api/organizations/{id}/members/{memberId}: gerarchia stretta (owner assoluto, admin solo
 * promuove un member ad admin), ultimo owner, auto-modifica, no-op, pulizia degli inviti, effetto
 * immediato, indipendenza dalla proprietà dei veicoli, audit e notifiche.
 */
final class OrganizationMemberRoleTest extends ApiTestCase
{
    use ChartFixtures;
    use MailerAssertionsTrait;

    // ---------------- helpers ----------------

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function membership(User $user, Organization $org): OrganizationMember
    {
        $this->em()->clear();
        $m = static::getContainer()->get(OrganizationMemberRepository::class)->findOneBy(['user' => $user, 'organization' => $org]);
        self::assertNotNull($m);

        return $m;
    }

    private function roleOf(User $user, Organization $org): OrgRole
    {
        return $this->membership($user, $org)->getRole();
    }

    /** @param array<string, mixed> $userAttrs */
    private function addMember(Organization $org, OrgRole $role, array $userAttrs = []): User
    {
        $user = UserFactory::createOne($userAttrs);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => $role]);

        return $user;
    }

    /** @param array<string, mixed> $body */
    private function changeRole(User $actor, Organization $org, User $target, array $body): void
    {
        $memberId = $this->membership($target, $org)->getId();
        $this->jsonRequest('PATCH', '/api/organizations/'.$org->getId().'/members/'.$memberId, $body, $this->tokenFor($actor, $org));
    }

    private function auditRows(): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE entity_class LIKE '%OrganizationMember' AND action = 'updated'");
    }

    private function registerDevice(User $user): void
    {
        $sub = (new PushSubscription())
            ->setUser($user)
            ->setPlatform(PushPlatform::WEB)
            ->setEndpoint('https://push.example.com/'.bin2hex(random_bytes(6)))
            ->setP256dh('p256dh')
            ->setAuthSecret('auth');
        $this->em()->persist($sub);
        $this->em()->flush();
    }

    // ---------------- autorizzazione e matrice ----------------

    /** @return iterable<string, array{OrgRole, OrgRole, OrgRole, int, ?string}> attore, bersaglio, nuovo ruolo, status, title (solo errori) */
    public static function matrix(): iterable
    {
        $roles = [OrgRole::OWNER, OrgRole::ADMIN, OrgRole::MEMBER];
        foreach ($roles as $target) {
            foreach ($roles as $new) {
                // OWNER: potere assoluto (anche no-op e declassamento di altri owner, ne resta sempre uno: l'attore)
                yield "owner su {$target->value} -> {$new->value}" => [OrgRole::OWNER, $target, $new, 200, null];
                // MEMBER: niente, 403 del voter
                yield "member su {$target->value} -> {$new->value}" => [OrgRole::MEMBER, $target, $new, 403, 'http.403'];
            }
        }
        // ADMIN: solo la promozione member -> admin
        foreach ($roles as $new) {
            yield "admin su owner -> {$new->value}" => [OrgRole::ADMIN, OrgRole::OWNER, $new, 403, 'member.cannot_change_owner_role'];
            yield "admin su admin -> {$new->value}" => [OrgRole::ADMIN, OrgRole::ADMIN, $new, 403, 'member.cannot_change_admin_role'];
        }
        yield 'admin su member -> owner' => [OrgRole::ADMIN, OrgRole::MEMBER, OrgRole::OWNER, 403, 'member.owner_role_forbidden'];
        yield 'admin su member -> admin' => [OrgRole::ADMIN, OrgRole::MEMBER, OrgRole::ADMIN, 200, null];
        yield 'admin su member -> member (no-op)' => [OrgRole::ADMIN, OrgRole::MEMBER, OrgRole::MEMBER, 200, null];
    }

    #[DataProvider('matrix')]
    public function testRoleMatrix(OrgRole $actorRole, OrgRole $targetRole, OrgRole $newRole, int $status, ?string $title): void
    {
        [$actor, $org] = $this->createAuthenticatedUser($actorRole);
        $target = $this->addMember($org, $targetRole);

        $this->changeRole($actor, $org, $target, ['role' => $newRole->value]);

        self::assertResponseStatusCodeSame($status);
        if ($title !== null) {
            self::assertSame($title, $this->jsonBody()['title']);
            self::assertSame($targetRole, $this->roleOf($target, $org), 'Un rifiuto non scrive nulla');
        } else {
            self::assertSame($newRole->value, $this->jsonBody()['role']);
            self::assertSame($newRole, $this->roleOf($target, $org));
        }
    }

    public function testRequiresAuthentication(): void
    {
        $this->jsonRequest('PATCH', '/api/organizations/1/members/1', ['role' => 'admin']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testCallerOfAnotherOrganizationGetsNotFound(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $target = $this->addMember($org, OrgRole::MEMBER);
        [$stranger, $strangerOrg] = $this->createAuthenticatedUser(); // owner di un'altra org

        $memberId = $this->membership($target, $org)->getId();
        $this->jsonRequest('PATCH', '/api/organizations/'.$org->getId().'/members/'.$memberId, ['role' => 'admin'], $this->tokenFor($stranger, $strangerOrg));

        // 404 come per un'org inesistente: chi non ne fa parte non ne deduce l'esistenza. Il ruolo non cambia.
        self::assertResponseStatusCodeSame(404);
        self::assertSame(OrgRole::MEMBER, $this->roleOf($target, $org));
    }

    public function testUnknownOrganizationIs404(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $target = $this->addMember($org, OrgRole::MEMBER);

        $this->jsonRequest('PATCH', '/api/organizations/999999/members/'.$this->membership($target, $org)->getId(), ['role' => 'admin'], $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('org.not_found', $this->jsonBody()['title']);
    }

    public function testUnknownMemberIs404(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PATCH', '/api/organizations/'.$org->getId().'/members/999999', ['role' => 'admin'], $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('member.not_found', $this->jsonBody()['title']);
    }

    public function testMemberOfAnotherOrganizationIs404(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $otherOrg = OrganizationFactory::createOne();
        $foreign = $this->addMember($otherOrg, OrgRole::MEMBER);

        $this->jsonRequest('PATCH', '/api/organizations/'.$org->getId().'/members/'.$this->membership($foreign, $otherOrg)->getId(), ['role' => 'admin'], $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('member.not_found', $this->jsonBody()['title']);
        self::assertSame(OrgRole::MEMBER, $this->roleOf($foreign, $otherOrg));
    }

    public function testNotAcceptedMembershipIs404(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $pending = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $pending, 'organization' => $org, 'role' => OrgRole::MEMBER, 'acceptedAt' => null]);

        $this->changeRole($owner, $org, $pending, ['role' => 'admin']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('member.not_found', $this->jsonBody()['title']);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testInvalidRoleIs422(array $body): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $target = $this->addMember($org, OrgRole::MEMBER);

        $this->changeRole($owner, $org, $target, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertSame('role', $this->jsonBody()['errors'][0]['field']);
        self::assertSame(OrgRole::MEMBER, $this->roleOf($target, $org));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'valore sconosciuto' => [['role' => 'superuser']];
        yield 'ruolo mancante' => [[]];
        yield 'tipo errato' => [['role' => 42]];
    }

    // ---------------- auto-modifica e ultimo owner ----------------

    #[DataProvider('anyRole')]
    public function testAdminCannotChangeOwnRole(OrgRole $new): void
    {
        [$admin, $org] = $this->createAuthenticatedUser(OrgRole::ADMIN);

        $this->changeRole($admin, $org, $admin, ['role' => $new->value]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('member.cannot_change_own_role', $this->jsonBody()['title']);
        self::assertSame(OrgRole::ADMIN, $this->roleOf($admin, $org));
    }

    /** @return iterable<string, array{OrgRole}> */
    public static function anyRole(): iterable
    {
        yield 'owner' => [OrgRole::OWNER];
        yield 'admin' => [OrgRole::ADMIN];
        yield 'member' => [OrgRole::MEMBER];
    }

    public function testOwnerCanStepDownWhenAnotherOwnerRemains(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $this->addMember($org, OrgRole::OWNER);

        $this->changeRole($owner, $org, $owner, ['role' => 'member']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrgRole::MEMBER, $this->roleOf($owner, $org));
    }

    public function testSoleOwnerCannotStepDown(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $this->addMember($org, OrgRole::ADMIN);

        $this->changeRole($owner, $org, $owner, ['role' => 'admin']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('member.last_owner', $this->jsonBody()['title']);
        self::assertSame(OrgRole::OWNER, $this->roleOf($owner, $org));
        self::assertSame(0, $this->auditRows());
    }

    public function testAnonymizedOwnerDoesNotCountAsRemainingOwner(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $this->addMember($org, OrgRole::OWNER, ['email' => 'deleted-1'.User::ANONYMIZED_EMAIL_SUFFIX]);

        $this->changeRole($owner, $org, $owner, ['role' => 'admin']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('member.last_owner', $this->jsonBody()['title']);
    }

    public function testTwoOwnersSteppingDownInSequenceLeaveOneOwner(): void
    {
        [$a, $org] = $this->createAuthenticatedUser();
        $b = $this->addMember($org, OrgRole::OWNER);

        $this->changeRole($a, $org, $a, ['role' => 'admin']);
        self::assertResponseStatusCodeSame(200);

        // B è rimasto l'unico owner: non può più scendere
        $this->changeRole($b, $org, $b, ['role' => 'admin']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('member.last_owner', $this->jsonBody()['title']);

        self::assertSame(OrgRole::ADMIN, $this->roleOf($a, $org));
        self::assertSame(OrgRole::OWNER, $this->roleOf($b, $org));
        self::assertSame(1, static::getContainer()->get(OrganizationMemberRepository::class)->countAcceptedOwners($org));
    }

    public function testOwnerDemotingTheOtherOwnerThenTheOtherCannotTouchBack(): void
    {
        [$a, $org] = $this->createAuthenticatedUser();
        $b = $this->addMember($org, OrgRole::OWNER);

        $this->changeRole($a, $org, $b, ['role' => 'member']);
        self::assertResponseStatusCodeSame(200);

        // B è ormai un member: non gestisce più nulla, e l'unico owner resta A
        $this->changeRole($b, $org, $a, ['role' => 'member']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrgRole::OWNER, $this->roleOf($a, $org));
    }

    // ---------------- no-op, risposta, audit ----------------

    public function testSameRoleIsANoOpWithoutAuditOrNotification(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $target = $this->addMember($org, OrgRole::ADMIN);
        $this->registerDevice($target);
        $this->client->disableReboot();
        $push = static::getContainer()->get(FakePushNotifier::class);

        $this->changeRole($owner, $org, $target, ['role' => 'admin']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('admin', $this->jsonBody()['role']);
        self::assertSame(0, $this->auditRows());
        self::assertEmailCount(0);
        self::assertSame([], $push->sent());
    }

    public function testSuccessReturnsTheMembershipWithTheListShape(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $target = $this->addMember($org, OrgRole::MEMBER, ['email' => 'target@test.it']);

        $this->changeRole($owner, $org, $target, ['role' => 'admin']);
        $patched = $this->jsonBody();
        $this->jsonRequest('GET', '/api/organizations/'.$org->getId().'/members', accessToken: $token);
        $listed = array_values(array_filter($this->jsonBody(), static fn (array $m): bool => $m['id'] === $patched['id']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($listed[0], $patched, 'Stessa forma e stessi dati di un elemento di GET .../members');
        self::assertSame('admin', $patched['role']);
        self::assertSame('target@test.it', $patched['user']['email']);
        self::assertNotNull($patched['acceptedAt']);
        self::assertArrayHasKey('createdAt', $patched);
    }

    public function testRoleChangeWritesOneAuditRowVisibleToAdmins(): void
    {
        [$owner, $org, $ownerToken] = $this->createAuthenticatedUser();
        $target = $this->addMember($org, OrgRole::MEMBER);

        $this->changeRole($owner, $org, $target, ['role' => 'admin']);
        self::assertResponseStatusCodeSame(200);

        $row = $this->em()->getConnection()->fetchAllAssociative("SELECT * FROM audit_logs WHERE entity_class LIKE '%OrganizationMember' AND action = 'updated'");
        self::assertCount(1, $row, 'Esattamente una riga di audit');
        self::assertSame((int) $org->getId(), (int) $row[0]['organization_id']);
        self::assertSame((int) $owner->getId(), (int) $row[0]['user_id'], 'L\'attore, non il bersaglio');
        self::assertSame(['role' => ['member', 'admin']], json_decode((string) $row[0]['changes'], true));

        // visibile a owner e admin dell'org tramite l'API
        $admin = $this->addMember($org, OrgRole::ADMIN);
        foreach ([$ownerToken, $this->tokenFor($admin, $org)] as $token) {
            $this->jsonRequest('GET', '/api/audit-logs', accessToken: $token);
            self::assertResponseIsSuccessful();
            $entries = array_values(array_filter($this->jsonBody(), static fn (array $l): bool => $l['action'] === 'updated' && str_ends_with((string) $l['entityClass'], 'OrganizationMember')));
            self::assertCount(1, $entries);
            self::assertSame(['role' => ['member', 'admin']], $entries[0]['changes']);
        }
    }

    // ---------------- inviti ----------------

    private function invitation(Organization $org, User $inviter, string $email, OrgRole $role = OrgRole::MEMBER, bool $used = false): OrganizationInvitation
    {
        $invitation = new OrganizationInvitation($org, $email, $role, hash('sha256', $email.random_bytes(4)), new \DateTimeImmutable('+7 days'), $inviter);
        if ($used) {
            $invitation->markUsed();
        }
        $this->em()->persist($invitation);
        $this->em()->flush();

        return $invitation;
    }

    private function countInvitationsFrom(User $inviter, ?Organization $org = null): int
    {
        $this->em()->clear();
        $sql = 'SELECT COUNT(*) FROM organization_invitations WHERE invited_by = ?'.($org !== null ? ' AND organization_id = ?' : '');

        return (int) $this->em()->getConnection()->fetchOne($sql, $org !== null ? [$inviter->getId(), $org->getId()] : [$inviter->getId()]);
    }

    #[DataProvider('demotions')]
    public function testDemotionDeletesThePendingInvitationsSentInThatOrganization(OrgRole $from, OrgRole $to): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $this->addMember($org, OrgRole::OWNER); // l'org resta con un owner anche se si declassa l'altro
        $target = $this->addMember($org, $from);
        $otherOrg = OrganizationFactory::createOne();
        $this->invitation($org, $target, 'a@test.it', OrgRole::ADMIN);
        $this->invitation($org, $target, 'b@test.it');
        $kept = $this->invitation($org, $target, 'used@test.it', OrgRole::MEMBER, used: true);
        $this->invitation($otherOrg, $target, 'c@test.it');
        $this->invitation($org, $owner, 'd@test.it');

        $this->changeRole($owner, $org, $target, ['role' => $to->value]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->countInvitationsFrom($target, $org), 'Resta solo l\'invito già usato');
        self::assertSame(1, $this->countInvitationsFrom($target, $otherOrg), 'Gli inviti di un\'altra org non si toccano');
        self::assertSame(1, $this->countInvitationsFrom($owner, $org), 'Quelli di altri non si toccano');
        self::assertNotNull($kept->getId());
    }

    /** @return iterable<string, array{OrgRole, OrgRole}> */
    public static function demotions(): iterable
    {
        yield 'owner -> admin' => [OrgRole::OWNER, OrgRole::ADMIN];
        yield 'owner -> member' => [OrgRole::OWNER, OrgRole::MEMBER];
        yield 'admin -> member' => [OrgRole::ADMIN, OrgRole::MEMBER];
    }

    public function testPromotionAndNoOpKeepTheInvitations(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $target = $this->addMember($org, OrgRole::MEMBER);
        $this->invitation($org, $target, 'a@test.it');

        $this->changeRole($owner, $org, $target, ['role' => 'admin']);
        self::assertResponseStatusCodeSame(200);
        $this->changeRole($owner, $org, $target, ['role' => 'admin']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(1, $this->countInvitationsFrom($target, $org));
    }

    // ---------------- effetto immediato e proprietà ----------------

    public function testRoleChangeTakesEffectOnTheNextRequestWithTheExistingToken(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $admin = $this->addMember($org, OrgRole::ADMIN);
        $member = $this->addMember($org, OrgRole::MEMBER);
        $adminToken = $this->tokenFor($admin, $org);
        $memberToken = $this->tokenFor($member, $org);
        $list = '/api/organizations/'.$org->getId().'/members';

        $this->jsonRequest('GET', $list, accessToken: $adminToken);
        self::assertResponseStatusCodeSame(200);
        $this->jsonRequest('GET', $list, accessToken: $memberToken);
        self::assertResponseStatusCodeSame(403);

        $this->changeRole($owner, $org, $admin, ['role' => 'member']);
        $this->changeRole($owner, $org, $member, ['role' => 'admin']);

        $this->jsonRequest('GET', $list, accessToken: $adminToken);
        self::assertResponseStatusCodeSame(403, 'L\'admin declassato perde l\'accesso con lo stesso token');
        $this->jsonRequest('GET', $list, accessToken: $memberToken);
        self::assertResponseStatusCodeSame(200, 'Il member promosso lo ottiene senza nuovo login');
    }

    public function testRoleChangeDoesNotMoveVehicleOwnership(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $admin = $this->addMember($org, OrgRole::ADMIN);
        $mine = $this->ownedVehicle($admin, $org, ['name' => 'Mia']);
        $theirs = $this->ownedVehicle($owner, $org, ['name' => 'Dell\'owner']);
        $this->expense($mine, $this->day(0), '10.00');
        $this->expense($theirs, $this->day(0), '1000.00');
        $adminToken = $this->tokenFor($admin, $org);

        $this->changeRole($owner, $org, $admin, ['role' => 'member']);
        self::assertResponseStatusCodeSame(200);

        // Il veicolo posseduto resta suo: proprietà, totali e notifiche
        $this->em()->clear();
        $owned = static::getContainer()->get(VehicleRepository::class)->findOwnedByUserInOrganization($admin, $org);
        self::assertSame([$mine->getId()], array_map(static fn ($v) => $v->getId(), $owned));
        $this->jsonRequest('GET', '/api/dashboard/charts?months=2', accessToken: $adminToken);
        self::assertSame('10.00', $this->jsonBody()['totals']['spending']);
        $recipients = static::getContainer()->get(OrganizationMemberRepository::class)->findNotificationRecipients($org, $mine);
        self::assertSame([$admin->getId()], array_map(static fn (OrganizationMember $m) => $m->getUser()->getId(), $recipients));

        // Quello visto solo grazie al ruolo admin non c'è più
        $this->jsonRequest('GET', '/api/vehicles', accessToken: $adminToken);
        self::assertSame(['Mia'], array_column($this->jsonBody(), 'name'));
        $this->jsonRequest('GET', '/api/vehicles/'.$theirs->getId(), accessToken: $adminToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPromotionKeepsTheTotalsAndAddsOrganizationVehiclesToTheList(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $member = $this->addMember($org, OrgRole::MEMBER);
        $mine = $this->ownedVehicle($member, $org, ['name' => 'Mia']);
        $theirs = $this->ownedVehicle($owner, $org, ['name' => 'Dell\'owner']);
        $this->expense($mine, $this->day(0), '10.00');
        $this->expense($theirs, $this->day(0), '1000.00');
        $memberToken = $this->tokenFor($member, $org);

        $this->changeRole($owner, $org, $member, ['role' => 'admin']);
        self::assertResponseStatusCodeSame(200);

        $this->jsonRequest('GET', '/api/dashboard/charts?months=2', accessToken: $memberToken);
        self::assertSame('10.00', $this->jsonBody()['totals']['spending'], 'I totali restano quelli dei veicoli posseduti');
        $this->jsonRequest('GET', '/api/vehicles', accessToken: $memberToken);
        $items = $this->jsonBody();
        $byName = array_column($items, 'ownership', 'name');
        self::assertEqualsCanonicalizing(['Mia' => 'owned', 'Dell\'owner' => 'organization'], $byName);
    }

    // ---------------- notifiche ----------------

    public function testRecipientIsNotifiedByEmailAndPushInTheirLanguage(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $owner->setFirstName('Mario')->setLastName('Rossi');
        $this->em()->flush();
        $this->client->disableReboot();
        $it = $this->addMember($org, OrgRole::MEMBER, ['email' => 'it@test.it', 'locale' => 'it']);
        $en = $this->addMember($org, OrgRole::MEMBER, ['email' => 'en@test.it', 'locale' => 'en']);
        $this->registerDevice($it);
        $this->registerDevice($en);
        $push = static::getContainer()->get(FakePushNotifier::class);

        $this->changeRole($owner, $org, $it, ['role' => 'admin']);
        self::assertEmailCount(1); // il logger dei messaggi si azzera a ogni richiesta
        $mailIt = self::getMailerMessage();
        $this->changeRole($owner, $org, $en, ['role' => 'admin']);
        self::assertEmailCount(1);
        $mailEn = self::getMailerMessage();

        self::assertInstanceOf(Email::class, $mailIt);
        self::assertInstanceOf(Email::class, $mailEn);
        self::assertSame('it@test.it', $mailIt->getTo()[0]->getAddress());
        self::assertStringContainsString('è cambiato', (string) $mailIt->getSubject());
        self::assertStringContainsString($org->getName(), (string) $mailIt->getSubject());
        self::assertStringContainsString('Mario Rossi ha cambiato il tuo ruolo', (string) $mailIt->getTextBody());
        self::assertStringContainsString('ora sei Amministratore', (string) $mailIt->getTextBody());
        self::assertSame('en@test.it', $mailEn->getTo()[0]->getAddress());
        self::assertStringContainsString('has changed', (string) $mailEn->getSubject());
        self::assertStringContainsString('Mario Rossi changed your role', (string) $mailEn->getTextBody());
        self::assertStringContainsString('you are now Admin', (string) $mailEn->getTextBody());

        $sent = $push->sent();
        self::assertCount(2, $sent);
        self::assertSame($it->getId(), $sent[0]['userId']);
        self::assertSame('Il tuo ruolo è cambiato', $sent[0]['payload']->title);
        self::assertStringContainsString('ora sei Amministratore', $sent[0]['payload']->body);
        self::assertSame($en->getId(), $sent[1]['userId']);
        self::assertSame('Your role has changed', $sent[1]['payload']->title);
        self::assertStringContainsString('you are now Admin', $sent[1]['payload']->body);
    }

    public function testNoNotificationToTheActorOrToAnonymizedAccountsOrOthers(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $other = $this->addMember($org, OrgRole::ADMIN); // uno spettatore: non riceve nulla
        $coOwner = $this->addMember($org, OrgRole::OWNER);
        $anonymized = $this->addMember($org, OrgRole::MEMBER, ['email' => 'deleted-9'.User::ANONYMIZED_EMAIL_SUFFIX]);
        foreach ([$owner, $other, $coOwner, $anonymized] as $user) {
            $this->registerDevice($user);
        }
        $this->client->disableReboot();
        $push = static::getContainer()->get(FakePushNotifier::class);

        $this->changeRole($owner, $org, $owner, ['role' => 'admin']);   // auto-modifica
        self::assertResponseStatusCodeSame(200);
        $this->changeRole($coOwner, $org, $anonymized, ['role' => 'admin']); // account anonimizzato
        self::assertResponseStatusCodeSame(200);

        self::assertEmailCount(0);
        self::assertSame([], $push->sent());
        self::assertSame(OrgRole::ADMIN, $this->roleOf($anonymized, $org), 'La modifica è comunque persistita');
        self::assertSame(OrgRole::ADMIN, $this->roleOf($other, $org));
    }

    public function testMailerAndPushFailuresAreLoggedAndDoNotFailTheRequest(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $target = $this->addMember($org, OrgRole::MEMBER);
        $this->registerDevice($target);
        $container = static::getContainer();
        $logger = new SpyLogger();

        $failingMailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP down');
            }
        };
        $failingPush = new class implements PushNotifierInterface {
            public function supportedPlatforms(): array
            {
                return [PushPlatform::WEB];
            }

            public function send(PushSubscription $subscription, PushPayload $payload): PushDeliveryResult
            {
                throw new \RuntimeException('push service down');
            }
        };
        $this->client->disableReboot();
        $container->set(MemberNotifier::class, new MemberNotifier(
            $container->get(MailBuilder::class),
            new AppMailer($failingMailer, $logger, 'smtp://localhost'),
            new PushDispatcher([$failingPush], $container->get(PushSubscriptionRepository::class), $this->em(), $logger),
            $logger,
        ));

        $this->changeRole($owner, $org, $target, ['role' => 'admin']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrgRole::ADMIN, $this->roleOf($target, $org), 'Il cambio è già persistito');
        $messages = array_column($logger->records, 'message');
        self::assertContains('Member notification email failed', $messages);
        self::assertContains('Member notification push failed', $messages);
    }
}
