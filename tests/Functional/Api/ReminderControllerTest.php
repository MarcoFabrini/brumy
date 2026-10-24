<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\OrgRole;
use App\Enum\ReminderType;
use App\Service\AppClock;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReminderControllerTest extends ApiTestCase
{
    public function testCreateReminder(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/reminders', [
            'vehicleId' => $vehicle->getId(),
            'type' => 'inspection',
            'description' => 'Revisione biennale',
            'dueDate' => '2026-12-31',
            'notifyDaysBefore' => 30,
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Revisione biennale', $this->jsonBody()['description']);
        self::assertNull($this->jsonBody()['completedAt']);
    }

    public function testCreateReminderWithAnUnknownTypeIsReportedWithAnI18nKey(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/reminders', [
            'vehicleId' => $vehicle->getId(), 'type' => 'not-a-type', 'description' => 'x', 'dueDate' => '2026-12-31',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'type', 'message' => 'common.invalid_value']], $this->jsonBody()['errors']);
    }

    public function testCreateReminderWithoutAnyDueIsRejected(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/reminders', [
            'vehicleId' => $vehicle->getId(),
            'type' => 'custom',
            'description' => 'Senza scadenza',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCompleteEndpointMarksAsCompleted(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);

        // Come il client storico: POST senza body e senza Content-Type.
        $this->client->request('POST', '/api/reminders/'.$r->getId().'/complete', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_CLIENT_TYPE' => 'mobile',
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertNotNull($body['completed']['completedAt']);
        $today = static::getContainer()->get(AppClock::class)->today()->format('Y-m-d');
        self::assertStringStartsWith($today, $body['completed']['completedOn']);
        self::assertNull($body['completed']['completedKm']);
        self::assertNull($body['next'], 'una tantum: nessun successore');
    }

    public function testCompletingARecurringReminderCreatesTheNextOneFromTheActualValues(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org, 'initialKm' => 1_000]);
        $r = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'type' => ReminderType::SERVICE, 'description' => 'Tagliando',
            'dueDate' => new \DateTimeImmutable('2026-12-15'), 'dueKm' => 10_000,
            'recurrenceMonths' => 24, 'recurrenceKm' => 10_000, 'notifyKmBefore' => 500,
        ]);
        $id = $r->getId();

        $this->jsonRequest('POST', '/api/reminders/'.$id.'/complete', ['completedOn' => '2026-10-01', 'completedKm' => 9_800], accessToken: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame($id, $body['completed']['id']);
        self::assertStringStartsWith('2026-10-01', $body['completed']['completedOn']);
        self::assertSame(9_800, $body['completed']['completedKm']);
        self::assertNotSame($id, $body['next']['id']);
        self::assertSame(19_800, $body['next']['dueKm']);
        self::assertStringStartsWith('2028-10-01', $body['next']['dueDate']);
        self::assertSame(24, $body['next']['recurrenceMonths']);
        self::assertSame(10_000, $body['next']['recurrenceKm']);
        self::assertSame(500, $body['next']['notifyKmBefore']);
        self::assertNull($body['next']['completedAt']);
        self::assertSame($vehicle->getId(), $body['next']['vehicle']['id']);

        // Tra gli attivi c'è solo il successore.
        $this->jsonRequest('GET', '/api/reminders?vehicleId='.$vehicle->getId(), accessToken: $token);
        self::assertSame([$body['next']['id']], array_column($this->jsonBody(), 'id'));
    }

    public function testCompletionWritesTheAuditTrailOfBothReminders(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'dueKm' => 10_000, 'recurrenceKm' => 10_000]);
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM audit_logs');

        $this->jsonRequest('POST', '/api/reminders/'.$r->getId().'/complete', ['completedOn' => '2026-10-01', 'completedKm' => 9_800], accessToken: $token);
        self::assertResponseIsSuccessful();
        $nextId = $this->jsonBody()['next']['id'];

        $rows = $connection->fetchAllAssociative("SELECT entity_id, action, changes FROM audit_logs WHERE entity_class = 'Reminder' ORDER BY id");
        $byAction = array_column($rows, null, 'action');
        self::assertSame((string) $r->getId(), (string) $byAction['updated']['entity_id']);
        $changes = json_decode((string) $byAction['updated']['changes'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([null, 9_800], $changes['completedKm']);
        self::assertArrayHasKey('completedOn', $changes);
        self::assertSame((string) $nextId, (string) $byAction['created']['entity_id']);
    }

    public function testCompletionValidatesDateAndKm(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        $url = '/api/reminders/'.$r->getId().'/complete';
        $tomorrow = static::getContainer()->get(AppClock::class)->today()->modify('+1 day')->format('Y-m-d');

        $this->jsonRequest('POST', $url, ['completedOn' => $tomorrow], accessToken: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertSame([['field' => 'completedOn', 'message' => 'reminder.completed_on_future']], $this->jsonBody()['errors']);

        $this->jsonRequest('POST', $url, ['completedOn' => '30/11/2026'], accessToken: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'completedOn', 'message' => 'common.invalid_date']], $this->jsonBody()['errors']);

        $this->jsonRequest('POST', $url, ['completedKm' => 10_000_000], accessToken: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'completedKm', 'message' => 'common.km_too_large']], $this->jsonBody()['errors']);

        $this->jsonRequest('GET', '/api/reminders/'.$r->getId(), accessToken: $token);
        self::assertNull($this->jsonBody()['completedAt'], 'un completamento rifiutato non tocca il promemoria');
    }

    public function testCompletionKmBelowTheKnownOdometerIsAllowed(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org, 'initialKm' => 0]);
        RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'km' => 10_500]);
        $r = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'dueKm' => 10_000, 'recurrenceKm' => 10_000]);

        // Tagliando registrato in ritardo: nel frattempo è già stato inserito un rifornimento più avanti.
        $this->jsonRequest('POST', '/api/reminders/'.$r->getId().'/complete', ['completedKm' => 9_800], accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(19_800, $this->jsonBody()['next']['dueKm']);
    }

    public function testCompletingTwiceIsAConflictAndCreatesNoSecondSuccessor(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'dueKm' => 10_000, 'recurrenceKm' => 10_000]);
        $url = '/api/reminders/'.$r->getId().'/complete';
        $count = static fn (): int => (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM reminders WHERE vehicle_id = ?', [$vehicle->getId()]);

        $this->jsonRequest('POST', $url, ['completedKm' => 9_800], accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $count());

        $this->jsonRequest('POST', $url, ['completedKm' => 9_900], accessToken: $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['type' => 'about:blank', 'title' => 'reminder.already_completed', 'status' => 409], $this->jsonBody());
        self::assertSame(2, $count());
    }

    public function testCompletingAReminderOfAnotherOrganizationIs404(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        [, $otherOrg] = $this->createAuthenticatedUser();
        $foreign = ReminderFactory::createOne(['organization' => $otherOrg, 'vehicle' => VehicleFactory::createOne(['organization' => $otherOrg])]);

        $this->jsonRequest('POST', '/api/reminders/'.$foreign->getId().'/complete', ['completedKm' => 1], accessToken: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('reminder.not_found', $this->jsonBody()['title']);
    }

    public function testCreateAndUpdateAcceptTheRecurrence(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $base = ['type' => 'service', 'description' => 'Tagliando', 'dueDate' => '2026-12-31', 'dueKm' => 10_000];

        $this->jsonRequest('POST', '/api/reminders', [
            'vehicleId' => $vehicle->getId(), ...$base, 'recurrenceMonths' => 24, 'recurrenceKm' => 10_000, 'notifyKmBefore' => 500,
        ], accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame([24, 10_000, 500], [$body['recurrenceMonths'], $body['recurrenceKm'], $body['notifyKmBefore']]);
        $id = $body['id'];

        $this->jsonRequest('PUT', '/api/reminders/'.$id, [...$base, 'recurrenceMonths' => 12, 'recurrenceKm' => 15_000, 'notifyKmBefore' => 800], accessToken: $token);
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame([12, 15_000, 800], [$body['recurrenceMonths'], $body['recurrenceKm'], $body['notifyKmBefore']]);

        // Campi omessi → una tantum con la soglia di default.
        $this->jsonRequest('PUT', '/api/reminders/'.$id, $base, accessToken: $token);
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame([null, null, 1000], [$body['recurrenceMonths'], $body['recurrenceKm'], $body['notifyKmBefore']]);

        $this->jsonRequest('POST', '/api/reminders', ['vehicleId' => $vehicle->getId(), ...$base], accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame([null, null, 1000], [$body['recurrenceMonths'], $body['recurrenceKm'], $body['notifyKmBefore']]);
        self::assertNull($body['completedOn']);
        self::assertNull($body['completedKm']);
    }

    /** @return iterable<string, array{array<string, mixed>, list<array{field: string, message: string}>}> */
    public static function invalidRecurrences(): iterable
    {
        yield 'ricorrenza a tempo senza data' => [
            ['dueKm' => 10_000, 'recurrenceMonths' => 24],
            [['field' => 'recurrenceMonths', 'message' => 'reminder.recurrence_months_requires_due_date']],
        ];
        yield 'ricorrenza a km senza km' => [
            ['dueDate' => '2026-12-31', 'recurrenceKm' => 10_000],
            [['field' => 'recurrenceKm', 'message' => 'reminder.recurrence_km_requires_due_km']],
        ];
        yield 'zero mesi' => [
            ['dueDate' => '2026-12-31', 'recurrenceMonths' => 0],
            [['field' => 'recurrenceMonths', 'message' => 'common.invalid_value']],
        ];
        yield 'oltre 120 mesi' => [
            ['dueDate' => '2026-12-31', 'recurrenceMonths' => 121],
            [['field' => 'recurrenceMonths', 'message' => 'common.invalid_value']],
        ];
        yield 'km di ricorrenza a zero' => [
            ['dueKm' => 10_000, 'recurrenceKm' => 0],
            [['field' => 'recurrenceKm', 'message' => 'common.must_be_positive']],
        ];
        yield 'km di ricorrenza enormi' => [
            ['dueKm' => 10_000, 'recurrenceKm' => 10_000_000],
            [['field' => 'recurrenceKm', 'message' => 'common.km_too_large']],
        ];
        yield 'anticipo a km negativo' => [
            ['dueKm' => 10_000, 'notifyKmBefore' => -1],
            [['field' => 'notifyKmBefore', 'message' => 'common.invalid_value']],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<array{field: string, message: string}> $errors
     */
    #[DataProvider('invalidRecurrences')]
    public function testInvalidRecurrenceIsRejectedOnCreateAndUpdate(array $fields, array $errors): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        $base = ['type' => 'service', 'description' => 'Tagliando', ...$fields];

        $this->jsonRequest('POST', '/api/reminders', ['vehicleId' => $vehicle->getId(), ...$base], accessToken: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertSame($errors, $this->jsonBody()['errors']);

        $this->jsonRequest('PUT', '/api/reminders/'.$r->getId(), $base, accessToken: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($errors, $this->jsonBody()['errors']);
    }

    public function testListExcludesCompletedByDefault(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        ReminderFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'completedAt' => new \DateTimeImmutable(),
        ]);

        $this->jsonRequest('GET', '/api/reminders?vehicleId='.$vehicle->getId(), accessToken: $token);
        self::assertCount(1, $this->jsonBody());

        $this->jsonRequest('GET', '/api/reminders?vehicleId='.$vehicle->getId().'&onlyActive=false', accessToken: $token);
        self::assertCount(2, $this->jsonBody());
    }

    /** La finestra di preavviso serve al client per calcolare "in scadenza": deve stare anche nelle liste. */
    public function testListAndUpcomingExposeNotifyDaysBefore(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('+5 days'), 'notifyDaysBefore' => 12,
        ]);

        $this->jsonRequest('GET', '/api/reminders?vehicleId='.$vehicle->getId(), accessToken: $token);
        self::assertResponseIsSuccessful();
        /** @var list<array{notifyDaysBefore: int}> $list */
        $list = $this->jsonBody();
        self::assertSame(12, $list[0]['notifyDaysBefore']);

        $this->jsonRequest('GET', '/api/reminders/upcoming', accessToken: $token);
        self::assertResponseIsSuccessful();
        /** @var list<array{notifyDaysBefore: int}> $upcoming */
        $upcoming = $this->jsonBody();
        self::assertSame(12, $upcoming[0]['notifyDaysBefore']);
    }

    /**
     * La tab "Scadenze": tutte le scadenze attive dei veicoli propri, a data e a km, con l'urgenza già calcolata e in
     * ordine (scadute, in scadenza, poi le altre).
     */
    public function testOverviewListsEveryActiveReminderOfTheOwnedVehiclesWithUrgency(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $panda = $this->ownedVehicle($user, $org);
        $golf = VehicleFactory::createOne(['organization' => $org, 'initialKm' => 15_000]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $golf, 'user' => $user]);
        RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $golf, 'km' => 19_600]);

        $later = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $panda, 'dueDate' => new \DateTimeImmutable('+200 days')]);
        $oilSoon = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $golf, 'dueDate' => null, 'dueKm' => 20_000, 'notifyKmBefore' => 500]);
        $overdue = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $panda, 'dueDate' => new \DateTimeImmutable('-3 days')]);
        ReminderFactory::createOne(['organization' => $org, 'vehicle' => $panda, 'dueDate' => new \DateTimeImmutable('-30 days'), 'completedAt' => new \DateTimeImmutable()]);
        // Veicolo condiviso con lui in sola lettura e veicolo archiviato: non sono "suoi" da gestire qui.
        $shared = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::createOne(['vehicle' => $shared, 'user' => $user]);
        ReminderFactory::createOne(['organization' => $org, 'vehicle' => $shared, 'dueDate' => new \DateTimeImmutable('-1 day')]);
        $archived = VehicleFactory::createOne(['organization' => $org, 'archivedAt' => new \DateTimeImmutable('-1 day')]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $archived, 'user' => $user]);
        ReminderFactory::createOne(['organization' => $org, 'vehicle' => $archived, 'dueDate' => new \DateTimeImmutable('-1 day')]);

        $this->jsonRequest('GET', '/api/reminders/overview', accessToken: $token);

        self::assertResponseIsSuccessful();
        /** @var list<array{id: int, urgency: string, currentKm: int, vehicle: array{id: int}}> $body */
        $body = $this->jsonBody();
        self::assertSame([$overdue->getId(), $oilSoon->getId(), $later->getId()], array_column($body, 'id'));
        self::assertSame(['overdue', 'soon', 'ok'], array_column($body, 'urgency'));
        self::assertSame(19_600, $body[1]['currentKm']);
        self::assertSame($golf->getId(), $body[1]['vehicle']['id']);
    }

    public function testUpcomingAggregatesAcrossTheOwnedVehicles(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicleA = $this->ownedVehicle($user, $org);
        $vehicleB = $this->ownedVehicle($user, $org);

        $overdue = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicleA,
            'dueDate' => new \DateTimeImmutable('-2 days'),
        ]);
        $soon = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicleB,
            'dueDate' => new \DateTimeImmutable('+5 days'),
        ]);
        ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicleA,
            'dueDate' => new \DateTimeImmutable('+90 days'), // fuori dalla finestra default (30gg)
        ]);

        $this->jsonRequest('GET', '/api/reminders/upcoming', accessToken: $token);

        self::assertResponseIsSuccessful();
        /** @var list<array{id: int, vehicle: array{id: int}}> $body */
        $body = $this->jsonBody();
        self::assertSame([$overdue->getId(), $soon->getId()], array_column($body, 'id'));
        self::assertSame($vehicleA->getId(), $body[0]['vehicle']['id']);
    }

    public function testUpcomingIsolatesOtherOrganizations(): void
    {
        [, $orgA, $tokenA] = $this->createAuthenticatedUser();
        [, $orgB] = $this->createAuthenticatedUser();
        $vehicleB = VehicleFactory::createOne(['organization' => $orgB]);
        ReminderFactory::createOne([
            'organization' => $orgB, 'vehicle' => $vehicleB,
            'dueDate' => new \DateTimeImmutable('+5 days'),
        ]);

        $this->jsonRequest('GET', '/api/reminders/upcoming', accessToken: $tokenA);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody());
    }

    public function testUpcomingOnlyCoversOwnedVehiclesEvenForTheOrgOwner(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser(); // owner dell'org: vede tutti i veicoli
        $mine = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $this->ownedVehicle($user, $org),
            'dueDate' => new \DateTimeImmutable('+5 days'),
        ]);
        // Veicolo di un altro membro: l'owner dell'org lo vede, ma non è suo.
        $othersVehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $othersVehicle]);
        ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $othersVehicle,
            'dueDate' => new \DateTimeImmutable('+5 days'),
        ]);
        // Veicolo condiviso con lui in sola lettura: nemmeno questo.
        $sharedVehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::createOne(['vehicle' => $sharedVehicle, 'user' => $user]);
        ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $sharedVehicle,
            'dueDate' => new \DateTimeImmutable('+5 days'),
        ]);

        $this->jsonRequest('GET', '/api/reminders/upcoming', accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertSame([$mine->getId()], array_column($this->jsonBody(), 'id'));
    }

    public function testUpcomingRespectsDaysAndLimitParams(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('+50 days'),
        ]);
        ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('+5 days'),
        ]);

        $this->jsonRequest('GET', '/api/reminders/upcoming?days=90&limit=1', accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonBody());
    }

    public function testUpdateChangesTheFieldsAndRearmsTheNotificationOnlyWhenTheDeadlineMoves(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('2026-12-31'), 'dueKm' => null, 'notifyDaysBefore' => 30,
            'description' => 'Tagliando',
        ]);
        $id = $r->getId();
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE reminders SET notified_urgency = 'soon' WHERE id = ?", [$id]);

        // Stessa scadenza, solo la descrizione cambia: il livello già notificato resta (niente doppia notifica)
        $this->jsonRequest('PUT', '/api/reminders/'.$id, [
            'type' => 'service', 'description' => 'Tagliando completo', 'dueDate' => '2026-12-31', 'notifyDaysBefore' => 30,
        ], accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertSame('Tagliando completo', $this->jsonBody()['description']);
        self::assertSame('soon', $connection->fetchOne('SELECT notified_urgency FROM reminders WHERE id = ?', [$id]));

        // La scadenza si sposta: la notifica si riarma
        $this->jsonRequest('PUT', '/api/reminders/'.$id, [
            'type' => 'service', 'description' => 'Tagliando completo', 'dueDate' => '2027-03-01', 'dueKm' => 120000, 'notifyDaysBefore' => 14,
        ], accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertSame(120000, $this->jsonBody()['dueKm']);
        self::assertNull($connection->fetchOne('SELECT notified_urgency FROM reminders WHERE id = ?', [$id]) ?: null);
        self::assertSame('2027-03-01', $connection->fetchOne('SELECT DATE(due_date) FROM reminders WHERE id = ?', [$id]));
    }

    public function testUpdateDoesNotNeedAVehicleId(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'dueDate' => new \DateTimeImmutable('2026-12-31'), 'description' => 'Originale',
        ]);

        $this->jsonRequest('PUT', '/api/reminders/'.$r->getId(), [
            'type' => 'custom', 'description' => 'Senza veicolo', 'dueDate' => '2026-12-31',
        ], accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('Senza veicolo', $this->jsonBody()['description']);
        self::assertSame($vehicle->getId(), $this->jsonBody()['vehicle']['id']);
    }

    public function testUpdateIgnoresAVehicleIdInTheBodyAndNeverMovesTheReminder(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $other = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'dueDate' => new \DateTimeImmutable('2026-12-31'), 'description' => 'Originale',
        ]);
        $id = $r->getId();

        // Il client storico manda ancora vehicleId: non deve far fallire la PUT né spostare il promemoria
        foreach ([$vehicle->getId(), $other->getId(), 0] as $vehicleId) {
            $this->jsonRequest('PUT', '/api/reminders/'.$id, [
                'vehicleId' => $vehicleId, 'type' => 'custom', 'description' => 'Aggiornato', 'dueDate' => '2026-12-31',
            ], accessToken: $token);

            self::assertResponseIsSuccessful();
            self::assertSame($vehicle->getId(), $this->jsonBody()['vehicle']['id']);
        }
        self::assertSame(
            $vehicle->getId(),
            (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT vehicle_id FROM reminders WHERE id = ?', [$id]),
        );
    }

    public function testUpdateWithoutAnyDueIsRejectedAndLeavesTheReminderUntouched(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $r = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'dueDate' => new \DateTimeImmutable('2026-12-31'), 'description' => 'Originale',
        ]);

        $this->jsonRequest('PUT', '/api/reminders/'.$r->getId(), [
            'vehicleId' => $vehicle->getId(), 'type' => 'custom', 'description' => 'Senza scadenza',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertSame([['field' => 'dueDate', 'message' => 'reminder.due_required']], $this->jsonBody()['errors']);

        $this->jsonRequest('GET', '/api/reminders/'.$r->getId(), accessToken: $token);
        self::assertSame('Originale', $this->jsonBody()['description']);
    }

    public function testListRequiresAVehicleIdAndHidesVehiclesOfOtherTenants(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        [, $otherOrg] = $this->createAuthenticatedUser();
        $foreign = VehicleFactory::createOne(['organization' => $otherOrg]);

        $this->jsonRequest('GET', '/api/reminders', accessToken: $token);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('query.vehicle_id_required', $this->jsonBody()['title']);

        $this->jsonRequest('GET', '/api/reminders?vehicleId=-3', accessToken: $token);
        self::assertResponseStatusCodeSame(400);

        $this->jsonRequest('GET', '/api/reminders?vehicleId='.$foreign->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('vehicle.not_found', $this->jsonBody()['title']);
    }

    public function testCreateOnAVehicleOfAnotherOrganizationReturns404AndCreatesNothing(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        [, $otherOrg] = $this->createAuthenticatedUser();
        $foreign = VehicleFactory::createOne(['organization' => $otherOrg]);

        $this->jsonRequest('POST', '/api/reminders', [
            'vehicleId' => $foreign->getId(), 'type' => 'inspection', 'description' => 'Intruso', 'dueDate' => '2026-12-31',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('vehicle.not_found', $this->jsonBody()['title']);
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM reminders'));
    }

    public function testReadingRemindersNeedsAccessToTheVehicle(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $hidden = VehicleFactory::createOne(['organization' => $org]);
        $shared = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::createOne(['vehicle' => $shared, 'user' => $user]); // viewer
        $hiddenReminder = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $hidden]);
        $sharedReminder = ReminderFactory::createOne(['organization' => $org, 'vehicle' => $shared]);

        $this->jsonRequest('GET', '/api/reminders?vehicleId='.$hidden->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(403);
        $this->jsonRequest('GET', '/api/reminders/'.$hiddenReminder->getId(), accessToken: $token);
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('GET', '/api/reminders?vehicleId='.$shared->getId(), accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertSame([$sharedReminder->getId()], array_column($this->jsonBody(), 'id'));
    }

    public function testUpcomingClampsDaysAndLimit(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $inAYear = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'dueDate' => new \DateTimeImmutable('+364 days'),
        ]);
        ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'dueDate' => new \DateTimeImmutable('+400 days'),
        ]);

        // days enorme: il tetto è 365, i promemoria oltre l'anno non escono
        $this->jsonRequest('GET', '/api/reminders/upcoming?days=100000&limit=50', accessToken: $token);
        self::assertSame([$inAYear->getId()], array_column($this->jsonBody(), 'id'));

        // days/limit a 0 o negativi si portano al minimo (1), non producono errori né liste illimitate
        $this->jsonRequest('GET', '/api/reminders/upcoming?days=0&limit=-5', accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody());
    }

    private function ownedVehicle(User $owner, Organization $org): Vehicle
    {
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $owner]);

        return $vehicle;
    }
}
