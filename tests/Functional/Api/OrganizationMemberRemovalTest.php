<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationMember;
use App\Entity\Refueling;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Entity\VehicleShare;
use App\Enum\OrgRole;
use App\Enum\ShareRole;
use App\Enum\UserValueKind;
use App\Message\SendReminderNotificationMessage;
use App\MessageHandler\SendReminderNotificationHandler;
use App\Repository\OrganizationMemberRepository;
use App\Repository\VehicleRepository;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserValueFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ChartFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

/**
 * Rimuovere un membro non deve lasciare veicoli senza proprietario: dashboard, promemoria in
 * arrivo e notifiche sono "solo proprietario", quindi i suoi veicoli passano a chi lo rimuove.
 */
final class OrganizationMemberRemovalTest extends ApiTestCase
{
    use ChartFixtures;
    use MailerAssertionsTrait;

    private function membership(User $user, \App\Entity\Organization $org): OrganizationMember
    {
        $m = OrganizationMemberFactory::repository()->findOneBy(['user' => $user, 'organization' => $org]);
        self::assertNotNull($m);

        return $m;
    }

    private function removeMember(User $actor, \App\Entity\Organization $org, User $member): void
    {
        $this->jsonRequest(
            'DELETE',
            '/api/organizations/'.$org->getId().'/members/'.$this->membership($member, $org)->getId(),
            accessToken: $this->tokenFor($actor, $org),
        );
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return list<int> */
    private function ownedIds(User $user, \App\Entity\Organization $org): array
    {
        $this->em()->clear();
        $repo = static::getContainer()->get(VehicleRepository::class);

        return array_map(static fn (Vehicle $v) => (int) $v->getId(), $repo->findOwnedByUserInOrganization($user, $org));
    }

    public function testRemovedMembersVehicleIsOwnedByTheRemover(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $member = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $car = $this->ownedVehicle($member, $org, ['name' => 'Auto del membro']);

        $this->removeMember($owner, $org, $member);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([$car->getId()], $this->ownedIds($owner, $org));
        self::assertSame([], $this->ownedIds($member, $org));
        // Anche dall'API: il rimuovente la vede come propria
        $this->jsonRequest('GET', '/api/vehicles', accessToken: $this->tokenFor($owner, $org));
        /** @var list<array<string, mixed>> $list */
        $list = $this->jsonBody();
        self::assertSame(['Auto del membro'], array_column($list, 'name'));
        self::assertSame('owned', $list[0]['ownership']);
    }

    public function testExistingShareOfTheRemoverIsUpgradedToAcceptedAdmin(): void
    {
        [$admin, $org] = $this->createAuthenticatedUser(OrgRole::ADMIN);
        $member = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $car = $this->ownedVehicle($member, $org);
        // L'admin aveva solo una condivisione ancora in attesa
        VehicleShareFactory::createOne(['vehicle' => $car, 'user' => $admin, 'acceptedAt' => null]);

        $this->removeMember($admin, $org, $member);

        self::assertResponseStatusCodeSame(204);
        $this->em()->clear();
        $shares = $this->em()->getRepository(VehicleShare::class)->findBy(['vehicle' => $car->getId()]);
        self::assertCount(1, $shares, 'Una sola share per (veicolo, utente): quella dell\'admin, promossa');
        self::assertSame($admin->getId(), $shares[0]->getUser()->getId());
        self::assertSame(ShareRole::ADMIN, $shares[0]->getRole());
        self::assertTrue($shares[0]->isAccepted());
        self::assertSame([$car->getId()], $this->ownedIds($admin, $org));
    }

    public function testNewShareRecordsTheRemoverAsInviter(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $member = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $car = $this->ownedVehicle($member, $org);

        $this->removeMember($owner, $org, $member);

        $this->em()->clear();
        $share = $this->em()->getRepository(VehicleShare::class)->findOneBy(['vehicle' => $car->getId(), 'user' => $owner->getId()]);
        self::assertNotNull($share);
        self::assertSame($owner->getId(), $share->getInvitedBy()?->getId());
    }

    public function testArchivedVehiclesAreTransferredToo(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $member = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $archived = $this->ownedVehicle($member, $org, ['archivedAt' => new \DateTimeImmutable('-1 week')]);

        $this->removeMember($owner, $org, $member);

        self::assertResponseStatusCodeSame(204);
        $this->em()->clear();
        $share = $this->em()->getRepository(VehicleShare::class)->findOneBy(['vehicle' => $archived->getId(), 'user' => $owner->getId()]);
        self::assertSame(ShareRole::ADMIN, $share?->getRole(), 'Ripristinandolo non deve restare senza proprietario');
    }

    public function testRemovedMembersReadOnlySharesAndOtherOrgsAreHandledSeparately(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $member = UserFactory::createOne();
        $other = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        OrganizationMemberFactory::createOne(['user' => $other, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $othersCar = $this->ownedVehicle($other, $org);
        VehicleShareFactory::createOne(['vehicle' => $othersCar, 'user' => $member]); // sola lettura
        // Stessa persona proprietaria in un'altra org: non è toccata
        $otherOrg = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $otherOrg, 'role' => OrgRole::OWNER]);
        $elsewhere = $this->ownedVehicle($member, $otherOrg);

        $this->removeMember($owner, $org, $member);

        self::assertResponseStatusCodeSame(204);
        $this->em()->clear();
        $shares = $this->em()->getRepository(VehicleShare::class);
        self::assertNull($shares->findOneBy(['vehicle' => $othersCar->getId(), 'user' => $member->getId()]), 'La share in sola lettura cade');
        self::assertNotNull($shares->findOneBy(['vehicle' => $othersCar->getId(), 'user' => $other->getId()]), 'Il vero proprietario non cambia');
        self::assertNull($shares->findOneBy(['vehicle' => $othersCar->getId(), 'user' => $owner->getId()]), 'Nessun veicolo altrui al rimuovente');
        self::assertSame(ShareRole::ADMIN, $shares->findOneBy(['vehicle' => $elsewhere->getId(), 'user' => $member->getId()])?->getRole());
        self::assertNull($shares->findOneBy(['vehicle' => $elsewhere->getId(), 'user' => $owner->getId()]));
    }

    public function testReminderNotificationsGoToTheNewOwner(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $owner->setEmail('new-owner@test.it');
        $this->em()->flush();
        $member = UserFactory::createOne(['email' => 'gone@test.it']);
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $car = $this->ownedVehicle($member, $org);
        $reminder = ReminderFactory::createOne([
            'organization' => $org,
            'vehicle' => $car,
            'description' => 'Tagliando passaggio',
            'dueDate' => new \DateTimeImmutable('+10 days'),
        ]);

        $this->removeMember($owner, $org, $member);
        self::assertResponseStatusCodeSame(204);

        $this->em()->clear();
        $car = $this->em()->find(Vehicle::class, $car->getId());
        self::assertNotNull($car);
        $recipients = static::getContainer()->get(OrganizationMemberRepository::class)->findNotificationRecipients($car->getOrganization(), $car);
        self::assertSame(['new-owner@test.it'], array_map(static fn (OrganizationMember $m) => $m->getUser()->getEmail(), $recipients));

        // E l'handler spedisce davvero al nuovo proprietario e a nessun altro
        ($this->client->getContainer()->get(SendReminderNotificationHandler::class))(new SendReminderNotificationMessage((int) $reminder->getId()));
        $to = [];
        foreach (static::getMailerMessages() as $msg) {
            if ($msg instanceof Email && $msg->getSubject() === 'Brumy — Scadenza: Tagliando passaggio') {
                foreach ($msg->getTo() as $addr) {
                    $to[] = $addr->getAddress();
                }
            }
        }
        self::assertSame(['new-owner@test.it'], array_values(array_unique($to)));
    }

    public function testRemovedMemberUnusedValuesAreDeleted(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $member = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $otherOrg = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $otherOrg, 'role' => OrgRole::MEMBER]);
        $car = $this->ownedVehicle($member, $org);
        // Veicolo senza proprietario: nessuno lo eredita, la voce che usa resta
        $orphan = VehicleFactory::createOne(['organization' => $org]);
        $value = static fn (string $name, ?\App\Entity\Organization $in = null): UserValue => UserValueFactory::createOne(
            ['user' => $member, 'organization' => $in ?? $org, 'kind' => UserValueKind::STATION, 'name' => $name],
        );
        $eni = $value('Eni');
        $value('Q8 mai usato');
        $shell = $value('Shell');
        $elsewhere = $value('Tamoil', $otherOrg);
        $onCar = RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $car, 'station' => $eni]);
        RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $orphan, 'station' => $shell]);

        $this->removeMember($owner, $org, $member);

        self::assertResponseStatusCodeSame(204);
        $this->em()->clear();
        $names = fn (User $user, \App\Entity\Organization $in): array => array_map(
            static fn (UserValue $v): string => $v->getName(),
            $this->em()->getRepository(UserValue::class)->findBy(['user' => $user, 'organization' => $in], ['name' => 'ASC']),
        );
        self::assertSame(['Shell'], $names($member, $org), 'Restano solo le voci ancora usate');
        self::assertSame(['Tamoil'], $names($member, $otherOrg), 'Le altre organizzazioni non si toccano');
        self::assertNotNull($this->em()->find(UserValue::class, $elsewhere->getId()));
        // Il rifornimento sull'auto ereditata punta all'"Eni" della lista di chi l'ha ricevuta
        $station = $this->em()->find(Refueling::class, $onCar->getId())?->getStation();
        self::assertNotNull($station);
        self::assertSame('Eni', $station->getName());
        self::assertSame($owner->getId(), $station->getUser()->getId());
    }

    public function testRemovingAMemberWhoOwnsNothingStillWorks(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $member = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $someoneElses = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::createOne(['vehicle' => $someoneElses, 'user' => $member]);
        $membershipId = $this->membership($member, $org)->getId();

        $this->removeMember($owner, $org, $member);

        self::assertResponseStatusCodeSame(204);
        $this->em()->clear();
        self::assertNull($this->em()->find(OrganizationMember::class, $membershipId));
        self::assertSame(0, $this->em()->getRepository(VehicleShare::class)->count([]));
    }

    public function testOrgOwnerStillCannotBeRemoved(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $coOwner = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $coOwner, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $car = $this->ownedVehicle($coOwner, $org);

        $this->removeMember($owner, $org, $coOwner);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('member.cannot_remove_owner', $this->jsonBody()['title']);
        self::assertSame([$car->getId()], $this->ownedIds($coOwner, $org), 'Nessun trasferimento se la rimozione è rifiutata');
        self::assertSame([], $this->ownedIds($owner, $org));
    }

    public function testAdminCannotRemoveAnotherAdmin(): void
    {
        [$admin, $org] = $this->createAuthenticatedUser(OrgRole::ADMIN);
        $peer = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $peer, 'organization' => $org, 'role' => OrgRole::ADMIN]);
        $car = $this->ownedVehicle($peer, $org);

        $this->removeMember($admin, $org, $peer);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('member.cannot_remove_admin', $this->jsonBody()['title']);
        $this->membership($peer, $org); // fallisce se il peer admin è stato rimosso
        self::assertSame([$car->getId()], $this->ownedIds($peer, $org), 'Nessun trasferimento di veicoli');
        self::assertSame([], $this->ownedIds($admin, $org));
    }

    public function testOwnerCanStillRemoveAnAdminAndAnAdminCanStillRemoveAMember(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $admin = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $admin, 'organization' => $org, 'role' => OrgRole::ADMIN]);
        $member = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $member, 'organization' => $org, 'role' => OrgRole::MEMBER]);

        $this->removeMember($admin, $org, $member);
        self::assertResponseStatusCodeSame(204);

        $this->removeMember($owner, $org, $admin);
        self::assertResponseStatusCodeSame(204);
    }

    public function testAdminLeavingByRemovingThemselvesHandsVehiclesToAnOrgOwner(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $admin = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $admin, 'organization' => $org, 'role' => OrgRole::ADMIN]);
        $car = $this->ownedVehicle($admin, $org);

        $this->removeMember($admin, $org, $admin);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([$car->getId()], $this->ownedIds($owner, $org));
        self::assertSame([], $this->ownedIds($admin, $org));
    }

    public function testRemovedAdminsPendingInvitationsAreDeletedAndCannotBeAccepted(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        $admin = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $admin, 'organization' => $org, 'role' => OrgRole::ADMIN]);
        // Scenario d'abuso: l'admin invita la propria seconda casella come admin, poi viene rimosso
        $secondMailbox = UserFactory::createOne(['email' => 'second-mailbox@test.it']);
        $rawPending = bin2hex(random_bytes(32));
        $pending = new OrganizationInvitation($org, 'second-mailbox@test.it', OrgRole::ADMIN, hash('sha256', $rawPending), new \DateTimeImmutable('+7 days'), $admin);
        $used = new OrganizationInvitation($org, 'used@test.it', OrgRole::MEMBER, hash('sha256', 'u'), new \DateTimeImmutable('+7 days'), $admin);
        $used->markUsed();
        $ownersInvite = new OrganizationInvitation($org, 'owners-guest@test.it', OrgRole::MEMBER, hash('sha256', 'o'), new \DateTimeImmutable('+7 days'), $owner);
        $otherOrgInvite = new OrganizationInvitation(OrganizationFactory::createOne(), 'x@test.it', OrgRole::MEMBER, hash('sha256', 'x'), new \DateTimeImmutable('+7 days'), $admin);
        foreach ([$pending, $used, $ownersInvite, $otherOrgInvite] as $i) {
            $this->em()->persist($i);
        }
        $this->em()->flush();
        [$pendingId, $usedId, $ownersId, $otherOrgId] = [$pending->getId(), $used->getId(), $ownersInvite->getId(), $otherOrgInvite->getId()];

        $this->removeMember($owner, $org, $admin);
        self::assertResponseStatusCodeSame(204);

        $this->em()->clear();
        self::assertNull($this->em()->find(OrganizationInvitation::class, $pendingId));
        self::assertNotNull($this->em()->find(OrganizationInvitation::class, $usedId), 'L\'invito già accettato è storia');
        self::assertNotNull($this->em()->find(OrganizationInvitation::class, $ownersId), 'Gli inviti di altri restano');
        self::assertNotNull($this->em()->find(OrganizationInvitation::class, $otherOrgId), 'Gli inviti di altre org non si toccano');

        // La seconda casella prova comunque ad accettare il vecchio link
        $this->jsonRequest('POST', '/api/auth/invitation/accept', ['token' => $rawPending], accessToken: $this->tokenFor($secondMailbox, $org));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invitation.invalid', $this->jsonBody()['title']);
        $this->em()->clear();
        $freshOrg = $this->em()->find(\App\Entity\Organization::class, $org->getId());
        self::assertNotNull($freshOrg);
        self::assertNull(static::getContainer()->get(OrganizationMemberRepository::class)->findMembership($secondMailbox, $freshOrg), 'Nessuna membership creata');
    }

    /**
     * Share rimaste a un ex membro (rimozione fatta con codice precedente o dati migrati): rientrando con un invito
     * non deve riprendersi proprietà né accessi. Audit run-2,
     * finding `migrations/Version20261001120000:ownership-backfill-regrants-removed-member-vehicle-ownership`.
     */
    public function testRejoiningByInvitationDropsLeftoverShares(): void
    {
        $owner = UserFactory::createOne();
        $formerMember = UserFactory::createOne(['email' => 'former@test.it']);
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $owner, 'organization' => $org, 'role' => OrgRole::OWNER]);
        $car = VehicleFactory::createOne(['organization' => $org]);
        $van = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $car, 'user' => $formerMember]);
        VehicleShareFactory::createOne(['vehicle' => $van, 'user' => $formerMember]);
        $raw = bin2hex(random_bytes(32));
        $this->em()->persist(new OrganizationInvitation($org, 'former@test.it', OrgRole::MEMBER, hash('sha256', $raw), new \DateTimeImmutable('+7 days'), $owner));
        $this->em()->flush();
        $carId = $car->getId();
        $vanId = $van->getId();

        $this->jsonRequest('POST', '/api/auth/invitation/accept', ['token' => $raw], accessToken: $this->tokenFor($formerMember, $org));
        self::assertResponseStatusCodeSame(200);

        $this->em()->clear();
        $shares = $this->em()->getRepository(VehicleShare::class)->findBy(['user' => $formerMember->getId()]);
        self::assertSame([], $shares, 'Nessuna share sopravvive al rientro');
        self::assertNotNull($this->em()->find(Vehicle::class, $carId));
        self::assertNotNull($this->em()->find(Vehicle::class, $vanId));
    }
}
