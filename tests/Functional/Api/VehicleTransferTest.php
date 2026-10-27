<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\AttachmentEntityType;
use App\Enum\ExpenseCategory;
use App\Enum\OrgRole;
use App\Enum\PushPlatform;
use App\Enum\RecurringPeriod;
use App\Enum\ReminderUrgency;
use App\Enum\ShareRole;
use App\Enum\UserValueKind;
use App\Repository\OrganizationMemberRepository;
use App\Repository\ReminderRepository;
use App\Repository\VehicleRepository;
use App\Service\AppMailer;
use App\Service\MailBuilder;
use App\Service\MemberNotifier;
use App\Service\Push\FakePushNotifier;
use App\Service\Push\PushDeliveryResult;
use App\Service\Push\PushDispatcher;
use App\Service\Push\PushNotifierInterface;
use App\Service\Push\PushPayload;
use App\Service\UserValues;
use App\Repository\PushSubscriptionRepository;
use App\Tests\Factory\AttachmentFactory;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
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
use App\Tests\Support\SpyLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * GET /api/vehicles/{id}/transfer-candidates e POST /api/vehicles/{id}/transfer: autorizzazione (SHARE),
 * isolamento tra org, validazione del destinatario, share risultanti, letture che seguono il
 * proprietario, dati del veicolo intatti, promemoria, audit e notifiche.
 */
final class VehicleTransferTest extends ApiTestCase
{
    use ChartFixtures;
    use MailerAssertionsTrait;

    private Organization $org;
    /** Owner dell'org (ruolo `owner`): non possiede nessun veicolo di default. */
    private User $orgOwner;
    /** Membro semplice, proprietario del veicolo del test. */
    private User $alice;
    /** Membro semplice, destinatario tipico. */
    private User $bob;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->orgOwner, $this->org] = $this->createAuthenticatedUser();
        $this->alice = $this->addMember(OrgRole::MEMBER, ['firstName' => 'Alice', 'lastName' => 'Rossi', 'email' => 'alice@test.it']);
        $this->bob = $this->addMember(OrgRole::MEMBER, ['firstName' => 'Bob', 'lastName' => 'Bianchi', 'email' => 'bob@test.it']);
        $this->vehicle = $this->ownedVehicle($this->alice, $this->org, ['name' => 'Panda']);
    }

    // ---------------- helpers ----------------

    private function uid(User $user): int
    {
        return (int) $user->getId();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param array<string, mixed> $attrs */
    private function addMember(OrgRole $role, array $attrs = [], ?Organization $org = null): User
    {
        $user = UserFactory::createOne($attrs);
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org ?? $this->org, 'role' => $role]);

        return $user;
    }

    /** @param array<string, mixed> $body */
    private function transfer(User $actor, array $body, ?Vehicle $vehicle = null, ?Organization $org = null): void
    {
        $vehicle ??= $this->vehicle;
        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/transfer', $body, $this->sessionFor($actor, $org ?? $this->org));
    }

    private function candidates(User $actor, ?Vehicle $vehicle = null): void
    {
        $vehicle ??= $this->vehicle;
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/transfer-candidates', session: $this->sessionFor($actor, $this->org));
    }

    /** @return array<int, array{role: string, accepted: bool, id: int}> userId => dati dello share, riletti dal DB */
    private function shares(?Vehicle $vehicle = null): array
    {
        $vehicle ??= $this->vehicle;
        /** @var list<array{id: int|string, user_id: int|string, role: string, accepted_at: string|null}> $rows */
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT id, user_id, role, accepted_at FROM vehicle_shares WHERE vehicle_id = ?', [$vehicle->getId()]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['user_id']] = ['role' => $row['role'], 'accepted' => $row['accepted_at'] !== null, 'id' => (int) $row['id']];
        }

        return $out;
    }

    private function assertOwnerIs(User $owner, ?Vehicle $vehicle = null): void
    {
        $admins = array_filter($this->shares($vehicle), static fn (array $s): bool => $s['role'] === 'admin' && $s['accepted']);
        self::assertSame([$this->uid($owner)], array_keys($admins), 'Esattamente uno share admin accettato, del nuovo proprietario');
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

    // ---------------- autorizzazione ----------------

    /** @return iterable<string, array{string, int}> tipo di chiamante, status atteso (sia candidati sia trasferimento) */
    public static function callers(): iterable
    {
        yield 'proprietario del veicolo (member)' => ['owner', 200];
        yield 'owner dell\'org' => ['org_owner', 200];
        yield 'admin dell\'org' => ['org_admin', 200];
        yield 'member senza share' => ['no_share', 403];
        yield 'viewer' => ['viewer', 403];
        yield 'editor legacy' => ['editor', 403];
        yield 'share admin non accettato' => ['pending_admin', 403];
    }

    private function callerOf(string $kind): User
    {
        return match ($kind) {
            'owner' => $this->alice,
            'org_owner' => $this->orgOwner,
            'org_admin' => $this->addMember(OrgRole::ADMIN),
            'no_share' => $this->addMember(OrgRole::MEMBER),
            'viewer' => $this->withShare(ShareRole::VIEWER, true),
            'editor' => $this->withShare(ShareRole::EDITOR, true),
            'pending_admin' => $this->withShare(ShareRole::ADMIN, false),
            default => throw new \LogicException($kind),
        };
    }

    private function withShare(ShareRole $role, bool $accepted): User
    {
        $user = $this->addMember(OrgRole::MEMBER);
        VehicleShareFactory::createOne([
            'vehicle' => $this->vehicle, 'user' => $user, 'role' => $role,
            'acceptedAt' => $accepted ? new \DateTimeImmutable() : null,
        ]);

        return $user;
    }

    #[DataProvider('callers')]
    public function testAuthorizationMatrix(string $kind, int $readStatus): void
    {
        $caller = $this->callerOf($kind);

        $this->candidates($caller);
        self::assertResponseStatusCodeSame($readStatus);

        $this->transfer($caller, ['userId' => $this->bob->getId()]);
        self::assertResponseStatusCodeSame($readStatus === 200 ? 204 : 403);
        if ($readStatus === 403) {
            self::assertSame('http.403', $this->jsonBody()['title']);
            $this->assertOwnerIs($this->alice);
        } else {
            $this->assertOwnerIs($this->bob);
        }
    }

    public function testAnonymousGets401(): void
    {
        $this->jsonRequest('GET', '/api/vehicles/'.$this->vehicle->getId().'/transfer-candidates');
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('POST', '/api/vehicles/'.$this->vehicle->getId().'/transfer', ['userId' => $this->bob->getId()]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testVehicleOfAnotherOrganizationIs404ForBothRoutes(): void
    {
        $otherOrg = OrganizationFactory::createOne();
        $stranger = $this->addMember(OrgRole::OWNER, [], $otherOrg);
        $foreign = $this->ownedVehicle($stranger, $otherOrg);

        // L'owner di questa org non vede il veicolo dell'altra: stesso 404 di un veicolo inesistente
        $this->candidates($this->orgOwner, $foreign);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('vehicle.not_found', $this->jsonBody()['title']);
        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId()], $foreign);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('vehicle.not_found', $this->jsonBody()['title']);
        $this->assertOwnerIs($stranger, $foreign);

        // E un membro di entrambe le org non può usare il token di una per toccare l'altra
        $this->jsonRequest('POST', '/api/vehicles/999999/transfer', ['userId' => $this->bob->getId()], $this->sessionFor($this->orgOwner, $this->org));
        self::assertResponseStatusCodeSame(404);
    }

    // ---------------- candidati ----------------

    public function testCandidatesAreNamesOnlyOrderedAndExcludeTheOwner(): void
    {
        $zed = $this->addMember(OrgRole::ADMIN, ['firstName' => 'Zoe', 'lastName' => 'Alfieri']);
        $dup = $this->addMember(OrgRole::MEMBER, ['firstName' => 'Anna', 'lastName' => 'Bianchi']);
        // Esclusi: membership pendente, account anonimizzato, membro di un'altra org
        $pending = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $this->org, 'user' => $pending, 'acceptedAt' => null]);
        $this->addMember(OrgRole::MEMBER, ['email' => 'deleted-1'.User::ANONYMIZED_EMAIL_SUFFIX]);
        $this->addMember(OrgRole::MEMBER, [], OrganizationFactory::createOne());

        $this->candidates($this->orgOwner);

        self::assertResponseIsSuccessful();
        $rows = $this->jsonBody();
        foreach ($rows as $row) {
            self::assertSame(['firstName', 'id', 'lastName'], $this->sortedKeys($row), 'Solo nomi: niente email');
        }
        // cognome poi nome; l'owner dell'org (attore, non proprietario) è incluso; Alice (proprietaria) no
        // L'ordine atteso si ricava dai nomi reali: bob e l'owner hanno cognomi casuali (Faker)
        $expected = [$zed, $dup, $this->bob, $this->orgOwner];
        usort($expected, static fn (User $a, User $b): int => [$a->getLastName(), $a->getFirstName()] <=> [$b->getLastName(), $b->getFirstName()]);
        self::assertSame(
            array_map(static fn (User $u): ?int => $u->getId(), $expected),
            array_column($rows, 'id'),
        );
        self::assertNotContains($this->alice->getId(), array_column($rows, 'id'));
        self::assertNotContains($pending->getId(), array_column($rows, 'id'));
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }

    public function testCandidatesOfAnOrphanVehicleIncludeEveryone(): void
    {
        $orphan = VehicleFactory::createOne(['organization' => $this->org]);

        $this->candidates($this->orgOwner, $orphan);

        self::assertResponseIsSuccessful();
        self::assertContains($this->alice->getId(), array_column($this->jsonBody(), 'id'));
    }

    // ---------------- destinatario ----------------

    /** @return iterable<string, array{string}> */
    public static function invalidRecipients(): iterable
    {
        yield 'id sconosciuto' => ['unknown'];
        yield 'utente di un\'altra org' => ['other_org'];
        yield 'membership non accettata' => ['pending'];
        yield 'account anonimizzato' => ['anonymized'];
    }

    #[DataProvider('invalidRecipients')]
    public function testInvalidRecipientGetsTheSameNonEnumerating422(string $kind): void
    {
        $userId = match ($kind) {
            'unknown' => 987654,
            'other_org' => $this->addMember(OrgRole::MEMBER, [], OrganizationFactory::createOne())->getId(),
            'pending' => (function (): int {
                $u = UserFactory::createOne();
                OrganizationMemberFactory::createOne(['organization' => $this->org, 'user' => $u, 'acceptedAt' => null]);

                return (int) $u->getId();
            })(),
            'anonymized' => $this->addMember(OrgRole::MEMBER, ['email' => 'deleted-7'.User::ANONYMIZED_EMAIL_SUFFIX])->getId(),
            default => throw new \LogicException($kind),
        };

        $this->transfer($this->alice, ['userId' => $userId]);

        self::assertResponseStatusCodeSame(422);
        $body = $this->jsonBody();
        self::assertSame('transfer.recipient_invalid', $body['title']);
        self::assertArrayNotHasKey('errors', $body);
        $this->assertOwnerIs($this->alice);
    }

    public function testRecipientAlreadyOwnerIs409(): void
    {
        $this->transfer($this->alice, ['userId' => $this->alice->getId()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('transfer.same_owner', $this->jsonBody()['title']);
        $this->assertOwnerIs($this->alice);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'userId mancante' => [[], 'userId'];
        yield 'userId stringa' => [['userId' => 'bob'], 'userId'];
        yield 'userId zero' => [['userId' => 0], 'userId'];
        yield 'keepAccess non booleano' => [['userId' => 1, 'keepAccess' => 'forse'], 'keepAccess'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testInvalidPayloadIs422WithFieldErrors(array $body, string $field): void
    {
        $this->transfer($this->alice, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertContains($field, array_column($this->jsonBody()['errors'], 'field'));
        $this->assertOwnerIs($this->alice);
    }

    // ---------------- share risultanti ----------------

    public function testKeepAccessDefaultsToTrueAndDemotesThePreviousOwnerInPlace(): void
    {
        $viewer = $this->withShare(ShareRole::VIEWER, true);
        $before = $this->shares();
        $viewerShareBefore = $before[$this->uid($viewer)];

        $this->transfer($this->alice, ['userId' => $this->bob->getId()]);

        self::assertResponseStatusCodeSame(204);
        $after = $this->shares();
        $this->assertOwnerIs($this->bob);
        self::assertSame('viewer', $after[$this->uid($this->alice)]['role']);
        self::assertSame($before[$this->uid($this->alice)]['id'], $after[$this->uid($this->alice)]['id'], 'Declassato sul posto, non ricreato');
        self::assertSame($viewerShareBefore, $after[$this->uid($viewer)], 'Gli share degli altri utenti non si toccano');
        self::assertCount(3, $after);
    }

    public function testKeepAccessFalseDeletesThePreviousOwnerShare(): void
    {
        $viewer = $this->withShare(ShareRole::VIEWER, true);
        $viewerBefore = $this->shares()[$this->uid($viewer)];

        $this->transfer($this->alice, ['userId' => $this->bob->getId(), 'keepAccess' => false]);

        self::assertResponseStatusCodeSame(204);
        $after = $this->shares();
        $this->assertOwnerIs($this->bob);
        self::assertArrayNotHasKey($this->alice->getId(), $after);
        self::assertSame($viewerBefore, $after[$this->uid($viewer)]);
        // il precedente owner (member) non vede più il veicolo
        $this->jsonRequest('GET', '/api/vehicles/'.$this->vehicle->getId(), session: $this->sessionFor($this->alice, $this->org));
        self::assertResponseStatusCodeSame(403);
    }

    public function testExistingViewerShareOfTheRecipientIsPromotedInPlace(): void
    {
        VehicleShareFactory::createOne(['vehicle' => $this->vehicle, 'user' => $this->bob, 'acceptedAt' => null]);
        $viewerShareId = $this->shares()[$this->uid($this->bob)]['id'];

        $this->transfer($this->alice, ['userId' => $this->bob->getId()]);

        self::assertResponseStatusCodeSame(204);
        $after = $this->shares();
        self::assertSame($viewerShareId, $after[$this->uid($this->bob)]['id'], 'Nessuna riga duplicata');
        self::assertTrue($after[$this->uid($this->bob)]['accepted'], 'acceptedAt impostato se mancava');
        $this->assertOwnerIs($this->bob);
        self::assertCount(2, $after);
    }

    public function testLegacyDuplicateAdminSharesAreCollapsedToOneOwner(): void
    {
        $legacy = $this->addMember(OrgRole::MEMBER);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $this->vehicle, 'user' => $legacy]);

        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId()]);

        self::assertResponseStatusCodeSame(204);
        $this->assertOwnerIs($this->bob);
        self::assertSame('viewer', $this->shares()[$this->uid($legacy)]['role']);
        self::assertSame('viewer', $this->shares()[$this->uid($this->alice)]['role']);
    }

    public function testOrganizationAdminAdoptsAnOrphanVehicle(): void
    {
        $admin = $this->addMember(OrgRole::ADMIN);
        $orphan = VehicleFactory::createOne(['organization' => $this->org]);

        $this->transfer($admin, ['userId' => $this->bob->getId()], $orphan);

        self::assertResponseStatusCodeSame(204);
        $this->assertOwnerIs($this->bob, $orphan);
        self::assertCount(1, $this->shares($orphan));
    }

    public function testPlainMemberCannotAdoptAnOrphanVehicle(): void
    {
        $orphan = VehicleFactory::createOne(['organization' => $this->org]);

        $this->transfer($this->bob, ['userId' => $this->bob->getId()], $orphan);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->shares($orphan));
    }

    public function testAdminAdoptsAMembersVehicleForThemselves(): void
    {
        $admin = $this->addMember(OrgRole::ADMIN);

        $this->transfer($admin, ['userId' => $admin->getId()]);

        self::assertResponseStatusCodeSame(204);
        $this->assertOwnerIs($admin);
    }

    // ---------------- letture che seguono il proprietario ----------------

    public function testOwnershipDependentReadsFollowTheNewOwnerOnly(): void
    {
        $admin = $this->addMember(OrgRole::ADMIN);
        // Alice: V (con storico, anche ricorrente) e W; Bob: il suo veicolo X
        $w = $this->ownedVehicle($this->alice, $this->org, ['name' => 'Seconda']);
        $x = $this->ownedVehicle($this->bob, $this->org, ['name' => 'Di Bob']);
        $this->refueling($this->vehicle, $this->day(-5), 1000, '10.000', '2.0000');   // 20.00 fuori dalla finestra di 2 mesi
        $this->refueling($this->vehicle, $this->day(0), 1100, '10.000', '2.0000');    // 20.00
        $this->maintenance($this->vehicle, $this->day(-1), 1050, '30.00');
        $this->recurringExpense($this->vehicle, $this->day(-4), '5.00', RecurringPeriod::MONTHLY);
        $this->expense($w, $this->day(0), '7.00');
        $this->expense($x, $this->day(0), '9.00');
        $reminder = ReminderFactory::createOne([
            'organization' => $this->org, 'vehicle' => $this->vehicle,
            'dueDate' => $this->today()->modify('+3 days'),
        ]);

        $aliceToken = $this->sessionFor($this->alice, $this->org);
        $bobToken = $this->sessionFor($this->bob, $this->org);
        $total = function (string $token): float {
            $this->jsonRequest('GET', '/api/dashboard/charts?months=12', session: $token);
            self::assertResponseIsSuccessful();

            return (float) $this->jsonBody()['totals']['spending'];
        };
        $this->jsonRequest('GET', '/api/vehicles/'.$this->vehicle->getId().'/charts?months=12', session: $aliceToken);
        $vehicleSpending = (float) $this->jsonBody()['totals']['spending'];
        self::assertGreaterThan(50.0, $vehicleSpending, 'Lo storico del veicolo comprende rifornimenti, manutenzione e spese ricorrenti');
        $aliceBefore = $total($aliceToken);
        $bobBefore = $total($bobToken);
        self::assertEqualsWithDelta($vehicleSpending + 7.0, $aliceBefore, 0.001);
        self::assertEqualsWithDelta(9.0, $bobBefore, 0.001);

        $this->transfer($this->alice, ['userId' => $this->bob->getId()]);
        self::assertResponseStatusCodeSame(204);

        // totali e grafici: l'intero storico passa a Bob, Alice (ora viewer) non lo conta più
        self::assertEqualsWithDelta(7.0, $total($aliceToken), 0.001);
        self::assertEqualsWithDelta($bobBefore + $vehicleSpending, $total($bobToken), 0.001);
        // l'admin dell'org non è mai stato proprietario di nulla
        self::assertEqualsWithDelta(0.0, $total($this->sessionFor($admin, $this->org)), 0.001);

        $this->em()->clear();
        $vehicles = static::getContainer()->get(VehicleRepository::class);
        $names = static fn (array $list): array => array_map(static fn (Vehicle $v): string => $v->getName(), $list);
        $aliceOwned = $names($vehicles->findOwnedByUserInOrganization($this->alice, $this->org));
        $bobOwned = $names($vehicles->findOwnedByUserInOrganization($this->bob, $this->org));
        self::assertSame(['Seconda'], $aliceOwned);
        self::assertEqualsCanonicalizing(['Panda', 'Di Bob'], $bobOwned);

        // promemoria in arrivo
        $reminders = static::getContainer()->get(ReminderRepository::class);
        self::assertSame([], $reminders->findUpcomingForOwner($this->org, $this->alice, 30, 10));
        self::assertSame([$reminder->getId()], array_map(static fn ($r) => $r->getId(), $reminders->findUpcomingForOwner($this->org, $this->bob, 30, 10)));

        // destinatari delle notifiche: solo il nuovo owner
        $members = static::getContainer()->get(OrganizationMemberRepository::class);
        $vehicle = $this->em()->find(Vehicle::class, $this->vehicle->getId());
        self::assertNotNull($vehicle);
        self::assertSame(
            [$this->uid($this->bob)],
            array_map(static fn ($m) => $m->getUser()->getId(), $members->findNotificationRecipients($this->org, $vehicle)),
        );

        // ownership e permessi per ciascuno
        $this->jsonRequest('GET', '/api/vehicles/'.$this->vehicle->getId(), session: $aliceToken);
        self::assertSame('shared', $this->jsonBody()['ownership']);
        self::assertSame(['canEdit' => false, 'canDelete' => false, 'canShare' => false], $this->jsonBody()['permissions']);
        $this->jsonRequest('GET', '/api/vehicles/'.$this->vehicle->getId(), session: $bobToken);
        self::assertSame('owned', $this->jsonBody()['ownership']);
        self::assertSame(['canEdit' => true, 'canDelete' => true, 'canShare' => true], $this->jsonBody()['permissions']);
        $this->jsonRequest('GET', '/api/vehicles', session: $this->sessionFor($admin, $this->org));
        self::assertSame('organization', array_column($this->jsonBody(), 'ownership', 'name')['Panda'], 'L\'admin dell\'org mantiene `organization`');
        $this->jsonRequest('GET', '/api/vehicles', session: $aliceToken);
        self::assertSame('shared', array_column($this->jsonBody(), 'ownership', 'name')['Panda']);
        self::assertSame('owned', array_column($this->jsonBody(), 'ownership', 'name')['Seconda']);
    }

    public function testWithoutKeepAccessThePreviousOwnerLosesTheVehicleFromTheList(): void
    {
        $this->transfer($this->alice, ['userId' => $this->bob->getId(), 'keepAccess' => false]);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/vehicles', session: $this->sessionFor($this->alice, $this->org));
        self::assertSame([], $this->jsonBody());
    }

    // ---------------- i dati del veicolo non cambiano ----------------

    public function testNothingOfTheVehicleDataIsRewritten(): void
    {
        MaintenanceFactory::createMany(2, ['organization' => $this->org, 'vehicle' => $this->vehicle]);
        $this->refueling($this->vehicle, $this->day(0), 1100, '10.000', '2.0000');
        $this->expense($this->vehicle, $this->day(0), '5.00');
        ReminderFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle]);
        $uploader = $this->addMember(OrgRole::MEMBER);
        AttachmentFactory::createOne([
            'organization' => $this->org, 'entityType' => AttachmentEntityType::VEHICLE,
            'entityId' => (string) $this->vehicle->getId(), 'uploadedBy' => $uploader, 'sizeBytes' => 12345,
        ]);

        $conn = $this->em()->getConnection();
        // Solo il collegamento alle voci (distributore, officina, categoria) passa alla lista del nuovo proprietario:
        // lo copre testTransferCopiesValuesToRecipientList, qui conta che nient'altro cambi.
        $withoutValues = static fn (array $rows): array => array_map(
            static fn (array $row): array => array_diff_key($row, ['station_id' => true, 'workshop_id' => true, 'category_id' => true]),
            $rows,
        );
        $snapshot = fn (): array => [
            'vehicle' => $conn->fetchAssociative('SELECT * FROM vehicles WHERE id = ?', [$this->vehicle->getId()]),
            'maintenances' => $withoutValues($conn->fetchAllAssociative('SELECT * FROM maintenances ORDER BY id')),
            'refuelings' => $withoutValues($conn->fetchAllAssociative('SELECT * FROM refuelings ORDER BY id')),
            'expenses' => $withoutValues($conn->fetchAllAssociative('SELECT * FROM expenses ORDER BY id')),
            'reminders' => $conn->fetchAllAssociative('SELECT id, vehicle_id, due_date, completed_at FROM reminders ORDER BY id'),
            'attachments' => $conn->fetchAllAssociative('SELECT * FROM attachments ORDER BY id'),
            'vehicleCount' => $conn->fetchOne('SELECT COUNT(*) FROM vehicles WHERE organization_id = ?', [$this->org->getId()]),
        ];
        $before = $snapshot();

        $this->transfer($this->alice, ['userId' => $this->bob->getId()]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame($before, $snapshot());
        self::assertSame((string) $uploader->getId(), (string) $before['attachments'][0]['uploaded_by']);
    }

    public function testAnArchivedVehicleCanBeTransferredAndStaysArchived(): void
    {
        $archived = $this->ownedVehicle($this->alice, $this->org, ['name' => 'Vecchia', 'archivedAt' => new \DateTimeImmutable('-1 month')]);

        $this->transfer($this->alice, ['userId' => $this->bob->getId()], $archived);

        self::assertResponseStatusCodeSame(204);
        $this->assertOwnerIs($this->bob, $archived);
        $this->jsonRequest('GET', '/api/vehicles?archived=1', session: $this->sessionFor($this->bob, $this->org));
        self::assertSame(['Vecchia'], array_column($this->jsonBody(), 'name'));
        self::assertNotNull(array_values($this->jsonBody())[0]['archivedAt']);
    }

    // ---------------- concorrenza (simulazione sequenziale) ----------------

    public function testTheSameRequestSentTwiceGets409TheSecondTime(): void
    {
        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId()]);
        self::assertResponseStatusCodeSame(204);

        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('transfer.same_owner', $this->jsonBody()['title']);
        $this->assertOwnerIs($this->bob);
    }

    public function testManyMixedSequentialTransfersAlwaysLeaveExactlyOneOwner(): void
    {
        $carol = $this->addMember(OrgRole::ADMIN);
        $sequence = [
            [$this->bob, true], [$carol, false], [$this->alice, true], [$this->bob, false],
            [$this->alice, true], [$carol, true], [$this->bob, true], [$this->alice, false],
        ];

        foreach ($sequence as [$recipient, $keep]) {
            $this->transfer($this->orgOwner, ['userId' => $recipient->getId(), 'keepAccess' => $keep]);
            self::assertResponseStatusCodeSame(204);
            $this->assertOwnerIs($recipient);
            foreach ($this->shares() as $share) {
                self::assertContains($share['role'], ['admin', 'viewer']);
            }
        }
    }

    // ---------------- promemoria ----------------

    public function testNotificationStateOfOpenRemindersIsResetOnTransfer(): void
    {
        $open = ReminderFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle]);
        $done = ReminderFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle, 'completedAt' => new \DateTimeImmutable('-1 day')]);
        $otherVehicle = $this->ownedVehicle($this->alice, $this->org);
        $foreign = ReminderFactory::createOne(['organization' => $this->org, 'vehicle' => $otherVehicle]);
        foreach ([$open, $done, $foreign] as $reminder) {
            $reminder->markNotified(ReminderUrgency::SOON);
        }
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('DELETE FROM audit_logs');
        $lastNotified = $this->em()->getConnection()->fetchOne('SELECT last_notified_at FROM reminders WHERE id = ?', [$open->getId()]);

        $this->transfer($this->alice, ['userId' => $this->bob->getId()]);

        self::assertResponseStatusCodeSame(204);
        $conn = $this->em()->getConnection();
        $urgency = static fn (int $id): mixed => $conn->fetchOne('SELECT notified_urgency FROM reminders WHERE id = ?', [$id]);
        self::assertNull($urgency((int) $open->getId()));
        self::assertSame('soon', $urgency((int) $done->getId()), 'I completati non si toccano');
        self::assertSame('soon', $urgency((int) $foreign->getId()), 'Quelli di altri veicoli neppure');
        self::assertSame($lastNotified, $conn->fetchOne('SELECT last_notified_at FROM reminders WHERE id = ?', [$open->getId()]));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE entity_class = 'Reminder'"), 'Aggiornamento di massa: nessun audit per singolo promemoria');
    }

    // ---------------- audit ----------------

    public function testTransferIsAuditedWithOrganizationAndActorAndVisibleInTheAuditLog(): void
    {
        $admin = $this->addMember(OrgRole::ADMIN);
        $this->em()->getConnection()->executeStatement('DELETE FROM audit_logs');
        $aliceShareId = $this->shares()[$this->uid($this->alice)]['id'];

        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId()]);
        self::assertResponseStatusCodeSame(204);

        $rows = $this->em()->getConnection()->fetchAllAssociative("SELECT * FROM audit_logs WHERE entity_class = 'VehicleShare' ORDER BY id");
        self::assertCount(2, $rows, 'Vecchio owner declassato + share del nuovo owner creato');
        foreach ($rows as $row) {
            self::assertSame((int) $this->org->getId(), (int) $row['organization_id']);
            self::assertSame((int) $this->orgOwner->getId(), (int) $row['user_id'], 'L\'attore');
        }
        $updated = array_values(array_filter($rows, static fn (array $r): bool => $r['action'] === 'updated'));
        self::assertSame((string) $aliceShareId, $updated[0]['entity_id']);
        self::assertSame(['role' => ['admin', 'viewer']], json_decode((string) $updated[0]['changes'], true));

        foreach ([$this->sessionFor($this->orgOwner, $this->org), $this->sessionFor($admin, $this->org)] as $token) {
            $this->jsonRequest('GET', '/api/audit-logs', session: $token);
            self::assertResponseIsSuccessful();
            $shareEntries = array_filter($this->jsonBody(), static fn (array $l): bool => $l['entityClass'] === 'VehicleShare');
            self::assertCount(2, $shareEntries);
        }
    }

    public function testExistingShareCreateAndRevokeRowsAreNowVisibleInTheAuditLog(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM audit_logs');
        $aliceToken = $this->sessionFor($this->alice, $this->org);

        $this->jsonRequest('POST', '/api/vehicles/'.$this->vehicle->getId().'/shares', ['userIds' => [$this->uid($this->bob)]], $aliceToken);
        self::assertResponseStatusCodeSame(201);
        $shareId = array_values($this->jsonBody())[0]['id'];
        $this->jsonRequest('DELETE', '/api/vehicles/'.$this->vehicle->getId().'/shares/'.$shareId, session: $aliceToken);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/audit-logs', session: $this->sessionFor($this->orgOwner, $this->org));
        $actions = array_column(array_filter($this->jsonBody(), static fn (array $l): bool => $l['entityClass'] === 'VehicleShare'), 'action');
        sort($actions);
        self::assertSame(['created', 'deleted'], $actions);
    }

    // ---------------- notifiche ----------------

    public function testNewAndPreviousOwnerAreNotifiedInTheirLanguageAndNobodyElse(): void
    {
        $this->alice->setLocale('it');
        $this->bob->setLocale('en');
        $bystander = $this->withShare(ShareRole::VIEWER, true);
        foreach ([$this->alice, $this->bob, $bystander, $this->orgOwner] as $user) {
            $this->registerDevice($user);
        }
        $this->em()->flush();
        $this->orgOwner->setFirstName('Mario')->setLastName('Verdi');
        $this->em()->flush();
        $this->client->disableReboot();
        $push = static::getContainer()->get(FakePushNotifier::class);

        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId(), 'keepAccess' => true]);

        self::assertResponseStatusCodeSame(204);
        $mails = self::getMailerMessages();
        self::assertCount(2, $mails);
        $byTo = [];
        foreach ($mails as $mail) {
            self::assertInstanceOf(Email::class, $mail);
            $byTo[$mail->getTo()[0]->getAddress()] = $mail;
        }
        self::assertEqualsCanonicalizing(['alice@test.it', 'bob@test.it'], array_keys($byTo));

        $toBob = $byTo['bob@test.it'];
        self::assertStringContainsString('Panda', (string) $toBob->getSubject());
        self::assertStringContainsString('is now yours', (string) $toBob->getSubject());
        $bobText = (string) $toBob->getTextBody();
        self::assertStringContainsString('Mario Verdi transferred the ownership of the vehicle "Panda"', $bobText);
        self::assertStringContainsString('Previous owner: Alice Rossi', $bobText);
        self::assertStringContainsString('history included, are now yours', $bobText);
        self::assertStringContainsString('/vehicles/'.$this->vehicle->getId(), $bobText);

        $toAlice = $byTo['alice@test.it'];
        $aliceText = (string) $toAlice->getTextBody();
        self::assertStringContainsString('ha un nuovo proprietario', (string) $toAlice->getSubject());
        self::assertStringContainsString('Mario Verdi ha trasferito la proprietà del veicolo "Panda"', $aliceText);
        self::assertStringContainsString('a Bob Bianchi', $aliceText);
        self::assertStringContainsString('Mantieni l\'accesso al veicolo in sola lettura', $aliceText);

        $sent = $push->sent();
        self::assertCount(2, $sent, 'Né l\'attore né lo spettatore ricevono push');
        $pushByUser = array_column($sent, 'payload', 'userId');
        $bobPush = $pushByUser[$this->uid($this->bob)];
        self::assertSame('You now own a vehicle', $bobPush->title);
        self::assertStringContainsString('Mario Verdi transferred "Panda" to you', $bobPush->body);
        self::assertSame('/vehicles/'.$this->vehicle->getId(), $bobPush->url);
        $alicePush = $pushByUser[$this->uid($this->alice)];
        self::assertSame('Proprietà del veicolo trasferita', $alicePush->title);
        self::assertStringContainsString('Bob Bianchi', $alicePush->body);
        self::assertStringContainsString('sola lettura', $alicePush->body);
        self::assertSame('/vehicles/'.$this->vehicle->getId(), $alicePush->url);
    }

    public function testPreviousOwnerWhoIsTheActorIsNotNotifiedAndLosingAccessIsStated(): void
    {
        $this->registerDevice($this->alice);
        $this->registerDevice($this->bob);
        $this->client->disableReboot();
        $push = static::getContainer()->get(FakePushNotifier::class);

        $this->transfer($this->alice, ['userId' => $this->bob->getId(), 'keepAccess' => false]);

        self::assertResponseStatusCodeSame(204);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('bob@test.it', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('Alice Rossi ti ha trasferito la proprietà', (string) $mail->getTextBody());
        self::assertSame([$this->uid($this->bob)], array_column($push->sent(), 'userId'));

        // Un'altra tornata con un admin che cede il veicolo senza accesso: il testo lo dice
        $admin = $this->addMember(OrgRole::ADMIN);
        $this->transfer($admin, ['userId' => $admin->getId(), 'keepAccess' => false]);
        self::assertResponseStatusCodeSame(204);
        $messages = self::getMailerMessages();
        $toBob = array_values(array_filter($messages, static fn ($m): bool => $m instanceof Email && $m->getTo()[0]->getAddress() === 'bob@test.it'));
        self::assertCount(1, $toBob);
        self::assertInstanceOf(Email::class, $toBob[0]);
        self::assertStringContainsString('Non hai più un accesso diretto', (string) $toBob[0]->getTextBody());
    }

    public function testAnOrphanVehicleNotifiesOnlyTheNewOwner(): void
    {
        $orphan = VehicleFactory::createOne(['organization' => $this->org]);
        $this->registerDevice($this->bob);
        $this->client->disableReboot();
        $push = static::getContainer()->get(FakePushNotifier::class);

        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId()], $orphan);

        self::assertResponseStatusCodeSame(204);
        self::assertEmailCount(1);
        self::assertSame([$this->uid($this->bob)], array_column($push->sent(), 'userId'));
    }

    public function testAnonymizedPreviousOwnerIsNotNotified(): void
    {
        $ghost = $this->addMember(OrgRole::MEMBER, ['email' => 'deleted-3'.User::ANONYMIZED_EMAIL_SUFFIX]);
        $vehicle = $this->ownedVehicle($ghost, $this->org);
        $this->registerDevice($ghost);
        $this->client->disableReboot();
        $push = static::getContainer()->get(FakePushNotifier::class);

        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId()], $vehicle);

        self::assertResponseStatusCodeSame(204);
        self::assertEmailCount(1);
        self::assertSame([], array_column($push->sent(), 'userId'));
    }

    public function testMailerAndPushFailuresAreLoggedAndTheTransferStillSucceeds(): void
    {
        $this->registerDevice($this->bob);
        $this->registerDevice($this->alice);
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

        $this->transfer($this->orgOwner, ['userId' => $this->bob->getId()]);

        self::assertResponseStatusCodeSame(204);
        $this->assertOwnerIs($this->bob);
        $messages = array_column($logger->records, 'message');
        self::assertContains('Member notification email failed', $messages);
        self::assertContains('Member notification push failed', $messages);
        // un canale che cade non ferma gli altri: entrambi i destinatari hanno provato entrambi i canali
        self::assertCount(2, array_filter($messages, static fn (string $m): bool => $m === 'Member notification email failed'));
    }

    // ---------------- le voci delle liste seguono il veicolo ----------------

    public function testTransferCopiesValuesToRecipientList(): void
    {
        $eni = UserValueFactory::createOne(['user' => $this->alice, 'organization' => $this->org, 'kind' => UserValueKind::STATION, 'name' => 'Eni']);
        $camper = static::getContainer()->get(UserValues::class)->findOrCreate($this->alice, $this->org, UserValueKind::EXPENSE_CATEGORY, 'Revisione camper');
        $refueling = RefuelingFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle, 'station' => $eni]);
        // La prima spesa che passa a Bob ha una categoria fatta a mano: la sua lista parte comunque dalle categorie di partenza
        $expense = ExpenseFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle, 'category' => $camper]);
        $toll = ExpenseFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle, 'category' => ExpenseCategory::TOLL]);
        $eniId = $eni->getId();

        $this->transfer($this->alice, ['userId' => $this->bob->getId()]);
        self::assertResponseStatusCodeSame(204);

        $bobToken = $this->sessionFor($this->bob, $this->org);
        $this->jsonRequest('GET', '/api/refuelings/'.$refueling->getId(), session: $bobToken);
        self::assertResponseIsSuccessful();
        $station = $this->jsonBody()['station'];
        self::assertSame('Eni', $station['name']);
        self::assertNotSame($eniId, $station['id']);
        $this->assertInListOf($this->bob, $station['id'], UserValueKind::STATION);

        $this->jsonRequest('GET', '/api/expenses/'.$expense->getId(), session: $bobToken);
        self::assertResponseIsSuccessful();
        $category = $this->jsonBody()['category'];
        self::assertSame('Revisione camper', $category['name']);
        $this->assertInListOf($this->bob, $category['id'], UserValueKind::EXPENSE_CATEGORY);

        $this->jsonRequest('GET', '/api/expenses/'.$toll->getId(), session: $bobToken);
        self::assertResponseIsSuccessful();
        $tollValue = $this->assertInListOf($this->bob, $this->jsonBody()['category']['id'], UserValueKind::EXPENSE_CATEGORY);
        self::assertSame(ExpenseCategory::TOLL->value, $tollValue->getStarterKey());

        $bobCategories = $this->em()->getRepository(UserValue::class)->findBy(['user' => $this->bob, 'organization' => $this->org, 'kind' => UserValueKind::EXPENSE_CATEGORY]);
        self::assertCount(\count(ExpenseCategory::starters()) + 1, $bobCategories, 'categorie di partenza + "Revisione camper"');
        self::assertCount(\count(ExpenseCategory::starters()), array_filter($bobCategories, static fn (UserValue $v): bool => $v->getStarterKey() !== null));

        // La lista di Alice non perde niente
        $aliceEni = $this->em()->find(UserValue::class, $eniId);
        self::assertNotNull($aliceEni);
        self::assertSame('Eni', $aliceEni->getName());
        self::assertSame($this->uid($this->alice), $aliceEni->getUser()->getId());
    }

    public function testTransferMapsStarterByKey(): void
    {
        $this->bob->setLocale('en');
        $this->em()->flush();
        $expense = ExpenseFactory::createOne(['organization' => $this->org, 'vehicle' => $this->vehicle, 'category' => ExpenseCategory::TOLL]);
        self::assertSame('Pedaggio', $expense->getCategory()?->getName());

        $this->transfer($this->alice, ['userId' => $this->bob->getId()]);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/expenses/'.$expense->getId(), session: $this->sessionFor($this->bob, $this->org));
        self::assertResponseIsSuccessful();
        $category = $this->jsonBody()['category'];
        self::assertSame('Toll', $category['name']);
        $value = $this->assertInListOf($this->bob, $category['id'], UserValueKind::EXPENSE_CATEGORY);
        self::assertSame(ExpenseCategory::TOLL->value, $value->getStarterKey());
        self::assertNull(
            $this->em()->getRepository(UserValue::class)->findOneBy(['user' => $this->bob, 'organization' => $this->org, 'nameKey' => 'pedaggio']),
            'Nessun "Pedaggio" duplicato nella lista di Bob',
        );
    }

    private function assertInListOf(User $owner, mixed $valueId, UserValueKind $kind): UserValue
    {
        self::assertIsInt($valueId);
        $this->em()->clear();
        $value = $this->em()->find(UserValue::class, $valueId);
        self::assertNotNull($value);
        self::assertSame($this->uid($owner), $value->getUser()->getId());
        self::assertSame($this->org->getId(), $value->getOrganization()->getId());
        self::assertSame($kind, $value->getKind());

        return $value;
    }

    public function testTheNewOwnerCanTransferTheVehicleBack(): void
    {
        // Reversibile: il nuovo proprietario ha SHARE e può restituirlo
        $this->transfer($this->alice, ['userId' => $this->bob->getId()]);
        self::assertResponseStatusCodeSame(204);

        $this->transfer($this->bob, ['userId' => $this->alice->getId()]);

        self::assertResponseStatusCodeSame(204);
        $this->assertOwnerIs($this->alice);
        self::assertSame('viewer', $this->shares()[$this->uid($this->bob)]['role']);
    }
}
