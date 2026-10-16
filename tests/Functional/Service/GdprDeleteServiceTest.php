<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\AuditLog;
use App\Entity\EmailVerificationToken;
use App\Entity\OrganizationInvitation;
use App\Entity\Organization;
use App\Entity\PasswordResetToken;
use App\Entity\PushSubscription;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\VehicleShare;
use App\Enum\AuditAction;
use App\Enum\OrgRole;
use App\Enum\PushPlatform;
use App\Enum\ShareRole;
use App\Repository\OrganizationMemberRepository;
use App\Service\Gdpr\GdprDeleteBlockedException;
use App\Service\Gdpr\GdprDeleteService;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Test GDPR Art. 17 right-to-erasure (#5.5).
 */
final class GdprDeleteServiceTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private function service(): GdprDeleteService
    {
        return static::getContainer()->get(GdprDeleteService::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnonymizeOverwritesPiiFields(): void
    {
        $user = UserFactory::createOne(['email' => 'forget@test.it', 'firstName' => 'Mario', 'lastName' => 'Rossi']);
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);

        $summary = $this->service()->anonymize($user);

        $this->em()->clear();
        $refreshed = $this->em()->find(User::class, $summary['anonymized_user_id']);
        self::assertNotNull($refreshed);
        self::assertStringStartsWith('deleted-', $refreshed->getEmail());
        self::assertStringEndsWith('@anonymized.local', $refreshed->getEmail());
        self::assertSame('Deleted', $refreshed->getFirstName());
        self::assertSame('User', $refreshed->getLastName());
    }

    public function testAnonymizeRevokesRefreshTokens(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);

        $token = new RefreshToken($user, 'tok_'.bin2hex(random_bytes(8)), new \DateTimeImmutable('+1 day'));
        $this->em()->persist($token);
        $this->em()->flush();

        $summary = $this->service()->anonymize($user);

        self::assertSame(1, $summary['revoked_tokens']);
        $this->em()->refresh($token);
        self::assertNotNull($token->getRevokedAt());
    }

    public function testAnonymizeDeletesPushSubscriptions(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);

        $sub = new PushSubscription();
        $sub->setUser($user)
            ->setPlatform(PushPlatform::WEB)
            ->setEndpoint('https://fcm.test/example');
        $this->em()->persist($sub);
        $this->em()->flush();
        $subId = $sub->getId();

        $summary = $this->service()->anonymize($user);

        self::assertSame(1, $summary['deleted_push_subs']);
        $this->em()->clear();
        self::assertNull($this->em()->find(PushSubscription::class, $subId));
    }

    public function testAnonymizeDeletesSoloOwnedOrganization(): void
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne(['slug' => 'solo-org']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $orgId = $org->getId();

        $summary = $this->service()->anonymize($user);

        self::assertSame(1, $summary['deleted_orgs']);
        self::assertNull($this->em()->find(Organization::class, $orgId));
    }

    public function testAnonymizeDeletesAuditDiffsOfTheSoloOwnedOrganizationOnly(): void
    {
        $user = UserFactory::createOne();
        $solo = OrganizationFactory::createOne(['slug' => 'solo-audit']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $solo, 'role' => OrgRole::OWNER]);
        $other = UserFactory::createOne();
        $shared = OrganizationFactory::createOne(['slug' => 'shared-audit']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $shared, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $other, 'organization' => $shared, 'role' => OrgRole::OWNER]);

        $ids = [];
        foreach ([$solo, $shared] as $org) {
            $log = (new AuditLog())
                ->setOrganization($org)
                ->setUser($user)
                ->setEntityClass('Vehicle')
                ->setEntityId('1')
                ->setAction(AuditAction::UPDATED)
                ->setChanges(['licensePlate' => ['AA111AA', 'BB222BB']]);
            $this->em()->persist($log);
            $this->em()->flush();
            $ids[$org->getSlug()] = $log->getId();
        }

        $this->service()->anonymize($user);

        $this->em()->clear();
        self::assertNull($this->em()->find(AuditLog::class, $ids['solo-audit']), 'I diff dell\'org eliminata non devono restare');
        self::assertNotNull($this->em()->find(AuditLog::class, $ids['shared-audit']), 'Le org che restano tengono il loro audit');
    }

    public function testAnonymizeClearsAvatarAndRemovesInvitationsToOldEmail(): void
    {
        $user = UserFactory::createOne(['email' => 'forget-me@test.it']);
        $user->setAvatarPath('avatar/some-file');
        $org = OrganizationFactory::createOne(['slug' => 'gdpr-invite-org']);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $other = OrganizationFactory::createOne(['slug' => 'other-org']);
        $invitation = new OrganizationInvitation(
            $other,
            'Forget-Me@test.it',
            OrgRole::MEMBER,
            hash('sha256', 'raw'),
            new \DateTimeImmutable('+7 days'),
            null,
        );
        $this->em()->persist($invitation);
        $this->em()->flush();
        $invitationId = $invitation->getId();

        $this->service()->anonymize($user);

        $this->em()->clear();
        self::assertNull($this->em()->find(User::class, $user->getId())?->getAvatarPath());
        self::assertNull($this->em()->find(OrganizationInvitation::class, $invitationId));
    }

    public function testAnonymizeDeletesPendingResetAndVerificationTokens(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $expiry = new \DateTimeImmutable('+1 day');
        $reset = new PasswordResetToken($user, hash('sha256', 'reset'), $expiry);
        $verify = new EmailVerificationToken($user, hash('sha256', 'verify'), $expiry);
        $othersReset = new PasswordResetToken($other, hash('sha256', 'other-reset'), $expiry);
        foreach ([$reset, $verify, $othersReset] as $t) {
            $this->em()->persist($t);
        }
        $this->em()->flush();
        [$resetId, $verifyId, $othersId] = [$reset->getId(), $verify->getId(), $othersReset->getId()];

        $this->service()->anonymize($user);

        $this->em()->clear();
        self::assertNull($this->em()->find(PasswordResetToken::class, $resetId), 'Il token di reset non deve restare spendibile');
        self::assertNull($this->em()->find(EmailVerificationToken::class, $verifyId));
        self::assertNotNull($this->em()->find(PasswordResetToken::class, $othersId), 'I token degli altri utenti restano');
    }

    public function testAnonymizeBlockedWhenSoleOwnerWithOtherMembers(): void
    {
        $owner = UserFactory::createOne();
        $member = UserFactory::createOne();
        $org = OrganizationFactory::createOne(['slug' => 'shared-org']);
        OrganizationMemberFactory::createOne(['user' => $owner, 'organization' => $org, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);

        $this->expectException(GdprDeleteBlockedException::class);
        $this->service()->anonymize($owner);
    }

    public function testAnonymizeAllowedWhenAnotherOwnerExists(): void
    {
        $owner1 = UserFactory::createOne();
        $owner2 = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $owner1, 'organization' => $org, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $owner2, 'organization' => $org, 'role' => OrgRole::OWNER]);

        $summary = $this->service()->anonymize($owner1);

        self::assertSame(0, $summary['deleted_orgs']);
        self::assertSame(1, $summary['kept_memberships']);
    }

    public function testAnonymizedMembersVehiclesPassToTheOldestAcceptedOwner(): void
    {
        $org = OrganizationFactory::createOne(['slug' => 'family']);
        $younger = UserFactory::createOne(['email' => 'younger-owner@test.it']);
        $oldest = UserFactory::createOne(['email' => 'oldest-owner@test.it']);
        $member = UserFactory::createOne(['email' => 'leaving@test.it']);
        // L'owner più anziano è quello con la membership creata per prima (anche se l'utente è più recente)
        OrganizationMemberFactory::createOne(['user' => $oldest, 'organization' => $org, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $younger, 'organization' => $org, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $car = VehicleFactory::createOne(['organization' => $org]);
        $viewed = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $car, 'user' => $member]);
        // Una share di sola lettura dell'utente su un veicolo altrui deve cadere
        VehicleShareFactory::createOne(['vehicle' => $viewed, 'user' => $member]);

        $summary = $this->service()->anonymize($member);

        self::assertSame(1, $summary['kept_memberships']);
        $this->em()->clear();
        $memberRepo = static::getContainer()->get(OrganizationMemberRepository::class);
        $carId = $car->getId();
        $car = $this->em()->find(Vehicle::class, $carId);
        $org = $this->em()->find(Organization::class, $org->getId());
        self::assertNotNull($car);
        self::assertNotNull($org);
        $recipients = array_map(static fn ($m) => $m->getUser()->getEmail(), $memberRepo->findNotificationRecipients($org, $car));
        self::assertSame(['oldest-owner@test.it'], $recipients, 'Le scadenze del veicolo arrivano all\'owner, non all\'account anonimo');
        $shares = $this->em()->getRepository(VehicleShare::class)->findBy(['vehicle' => [$carId, $viewed->getId()]]);
        self::assertCount(1, $shares, 'Restano solo la share del nuovo proprietario: quelle dell\'anonimo cadono');
        self::assertSame(ShareRole::ADMIN, $shares[0]->getRole());
        self::assertSame($oldest->getId(), $shares[0]->getUser()->getId());
        self::assertTrue($shares[0]->isAccepted());
        // La membership dell'account anonimo resta (storia di partecipazione)
        self::assertNotNull($memberRepo->findOneBy(['organization' => $org, 'user' => $member->getId()]));
    }

    public function testAnonymizeKeepsSoloOrgDeletionNextToTransferOnSharedOne(): void
    {
        $user = UserFactory::createOne();
        $owner = UserFactory::createOne();
        $shared = OrganizationFactory::createOne(['slug' => 'shared-with-owner']);
        $solo = OrganizationFactory::createOne(['slug' => 'only-me']);
        OrganizationMemberFactory::createOne(['user' => $owner, 'organization' => $shared, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $shared, 'role' => OrgRole::MEMBER]);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $solo, 'role' => OrgRole::OWNER]);
        $sharedCar = VehicleFactory::createOne(['organization' => $shared]);
        $soloCar = VehicleFactory::createOne(['organization' => $solo]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $sharedCar, 'user' => $user]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $soloCar, 'user' => $user]);
        [$sharedId, $soloId, $soloCarId, $sharedCarId] = [$shared->getId(), $solo->getId(), $soloCar->getId(), $sharedCar->getId()];

        $summary = $this->service()->anonymize($user);

        self::assertSame(1, $summary['deleted_orgs']);
        self::assertSame(1, $summary['kept_memberships']);
        $this->em()->clear();
        self::assertNull($this->em()->find(Organization::class, $soloId));
        self::assertNull($this->em()->find(Vehicle::class, $soloCarId));
        self::assertNotNull($this->em()->find(Organization::class, $sharedId));
        $share = $this->em()->getRepository(VehicleShare::class)->findOneBy(['vehicle' => $sharedCarId]);
        self::assertSame($owner->getId(), $share?->getUser()->getId());
    }

    public function testAnonymizeDeletesPendingInvitationsTheUserSent(): void
    {
        $org = OrganizationFactory::createOne(['slug' => 'inviting-org']);
        $owner = UserFactory::createOne();
        $leaver = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $owner, 'organization' => $org, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['user' => $leaver, 'organization' => $org, 'role' => OrgRole::ADMIN]);
        $pending = new OrganizationInvitation($org, 'guest@test.it', OrgRole::ADMIN, hash('sha256', 'a'), new \DateTimeImmutable('+7 days'), $leaver);
        $used = new OrganizationInvitation($org, 'used@test.it', OrgRole::MEMBER, hash('sha256', 'b'), new \DateTimeImmutable('+7 days'), $leaver);
        $used->markUsed();
        $others = new OrganizationInvitation($org, 'other@test.it', OrgRole::MEMBER, hash('sha256', 'c'), new \DateTimeImmutable('+7 days'), $owner);
        foreach ([$pending, $used, $others] as $i) {
            $this->em()->persist($i);
        }
        $this->em()->flush();
        [$pendingId, $usedId, $othersId] = [$pending->getId(), $used->getId(), $others->getId()];

        $this->service()->anonymize($leaver);

        $this->em()->clear();
        self::assertNull($this->em()->find(OrganizationInvitation::class, $pendingId));
        self::assertNotNull($this->em()->find(OrganizationInvitation::class, $usedId), 'Gli inviti già accettati sono storia');
        self::assertNotNull($this->em()->find(OrganizationInvitation::class, $othersId));
    }
}
