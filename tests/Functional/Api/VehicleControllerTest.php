<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\VehicleShare;
use App\Enum\FuelType;
use App\Enum\OrgRole;
use App\Enum\ShareRole;
use App\Repository\VehicleRepository;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class VehicleControllerTest extends ApiTestCase
{
    public function testListReturnsOnlyVehiclesInActiveOrganization(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        VehicleFactory::createMany(3, ['organization' => $org]);

        $this->jsonRequest('GET', '/api/vehicles', session: $token);

        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->jsonBody());
    }

    public function testListExcludesArchivedVehiclesByDefault(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        VehicleFactory::createOne(['organization' => $org]);
        VehicleFactory::createOne(['organization' => $org, 'archivedAt' => new \DateTimeImmutable()]);

        $this->jsonRequest('GET', '/api/vehicles', session: $token);
        self::assertCount(1, $this->jsonBody());
    }

    public function testArchivedListContainsOnlyArchivedVehiclesWithArchivedAt(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $active = VehicleFactory::createOne(['organization' => $org, 'name' => 'Attiva']);
        $archived = VehicleFactory::createOne(['organization' => $org, 'name' => 'Archiviata', 'archivedAt' => new \DateTimeImmutable('2026-01-15 10:00:00')]);
        foreach ([$active, $archived] as $v) {
            VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $v, 'user' => $owner]);
        }

        $this->jsonRequest('GET', '/api/vehicles?archived=1', session: $token);

        self::assertResponseIsSuccessful();
        /** @var list<array<string, mixed>> $list */
        $list = $this->jsonBody();
        self::assertSame(['Archiviata'], array_column($list, 'name'));
        self::assertStringStartsWith('2026-01-15', (string) $list[0]['archivedAt']);
        self::assertSame('owned', $list[0]['ownership']);
        self::assertTrue($list[0]['permissions']['canDelete'], 'Serve per mostrare Ripristina');

        // Il default non cambia: solo gli attivi, con archivedAt null
        $this->jsonRequest('GET', '/api/vehicles', session: $token);
        /** @var list<array<string, mixed>> $default */
        $default = $this->jsonBody();
        self::assertSame(['Attiva'], array_column($default, 'name'));
        self::assertArrayHasKey('archivedAt', $default[0]);
        self::assertNull($default[0]['archivedAt']);

        // Valori diversi da vero non attivano il filtro
        $this->jsonRequest('GET', '/api/vehicles?archived=0', session: $token);
        self::assertSame(['Attiva'], array_column($this->jsonBody(), 'name'));
    }

    public function testArchivedListRespectsAccessRules(): void
    {
        [$owner, $org, $ownerToken] = $this->createAuthenticatedUser();
        $stranger = $this->memberOf($org, 'Senza', 'Share');
        $reader = $this->memberOf($org, 'Solo', 'Lettura');
        $past = new \DateTimeImmutable('-1 month');

        $ownersCar = VehicleFactory::createOne(['organization' => $org, 'name' => 'Archiviata del titolare', 'archivedAt' => $past]);
        $sharedCar = VehicleFactory::createOne(['organization' => $org, 'name' => 'Archiviata condivisa', 'archivedAt' => $past]);
        $hiddenCar = VehicleFactory::createOne(['organization' => $org, 'name' => 'Archiviata nascosta', 'archivedAt' => $past]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $ownersCar, 'user' => $owner]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $sharedCar, 'user' => $owner]);
        VehicleShareFactory::createOne(['vehicle' => $sharedCar, 'user' => $reader]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $hiddenCar, 'user' => $owner]);
        // Altra org: non compare mai, nemmeno per chi è owner dell'org attiva
        VehicleFactory::createOne(['name' => 'Archiviata di un\'altra org', 'archivedAt' => $past]);

        // Member senza share: nessuna archiviata
        $this->jsonRequest('GET', '/api/vehicles?archived=1', session: $this->sessionFor($stranger, $org));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody());

        // Share in sola lettura: vede la sua, marcata shared
        $this->jsonRequest('GET', '/api/vehicles?archived=1', session: $this->sessionFor($reader, $org));
        /** @var list<array<string, mixed>> $readerList */
        $readerList = $this->jsonBody();
        self::assertSame(['Archiviata condivisa'], array_column($readerList, 'name'));
        self::assertSame('shared', $readerList[0]['ownership']);
        self::assertFalse($readerList[0]['permissions']['canDelete']);

        // Owner dell'org: tutte le archiviate della sua org, e nessuna di un'altra
        $this->jsonRequest('GET', '/api/vehicles?archived=1', session: $ownerToken);
        $names = array_column($this->jsonBody(), 'name');
        sort($names);
        self::assertSame(['Archiviata condivisa', 'Archiviata del titolare', 'Archiviata nascosta'], $names);
    }

    public function testGetReturnsVehicleDetail(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org, 'name' => 'La Golf']);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId(), session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('La Golf', $this->jsonBody()['name']);
    }

    public function testGetNonExistentReturns404(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->jsonRequest('GET', '/api/vehicles/999999', session: $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateAsOrgOwnerPersistsVehicle(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/vehicles', [
            'name' => 'La Panda',
            'brand' => 'Fiat',
            'model' => 'Panda',
            'year' => 2019,
            'type' => 'car',
            'fuelType' => 'gasoline',
            'initialKm' => 30000,
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('La Panda', $this->jsonBody()['name']);

        $repo = static::getContainer()->get(VehicleRepository::class);
        self::assertCount(1, $repo->findAll());
    }

    /** @return array<string, mixed> */
    private function newVehiclePayload(string $name = 'Nuova'): array
    {
        return ['name' => $name, 'brand' => 'Fiat', 'model' => 'Panda', 'year' => 2019, 'type' => 'car', 'fuelType' => 'gasoline', 'initialKm' => 0];
    }

    /** Tetto di test: 3 veicoli (vedi `app_vehicles_org_limit_default` in `when@test`). */
    public function testCreateOverTheOrganizationLimitIsRefusedAndPersistsNothing(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        // Due attivi e uno archiviato: l'archiviato conta, quindi l'org è al tetto.
        VehicleFactory::createMany(2, ['organization' => $org]);
        VehicleFactory::createOne(['organization' => $org, 'archivedAt' => new \DateTimeImmutable()]);

        $this->jsonRequest('POST', '/api/vehicles', $this->newVehiclePayload(), session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['type' => 'about:blank', 'title' => 'vehicle.limit_reached', 'status' => 422],
            $this->jsonBody(),
        );
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(3, static::getContainer()->get(VehicleRepository::class)->countByOrganization($org));
        self::assertSame(0, $em->getRepository(VehicleShare::class)->count(['user' => $user]), 'nessuno share di proprietario creato');
    }

    public function testCreateUpToTheOrganizationLimitWorksAndOtherOrganizationsDoNotCount(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        VehicleFactory::createMany(5, ['organization' => OrganizationFactory::createOne()]);
        VehicleFactory::createMany(2, ['organization' => $org]);

        $this->jsonRequest('POST', '/api/vehicles', $this->newVehiclePayload('Il terzo'), session: $token);
        self::assertResponseStatusCodeSame(201, 'Il terzo veicolo è l\'ultimo ammesso');

        $this->jsonRequest('POST', '/api/vehicles', $this->newVehiclePayload('Il quarto'), session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('vehicle.limit_reached', $this->jsonBody()['title']);
    }

    public function testUnarchiveIsNotAffectedByTheLimit(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        VehicleFactory::createMany(2, ['organization' => $org]);
        $archived = VehicleFactory::createOne(['organization' => $org, 'archivedAt' => new \DateTimeImmutable()]);

        $this->jsonRequest('POST', '/api/vehicles/'.$archived->getId().'/unarchive', session: $token);

        self::assertResponseIsSuccessful();
    }

    public function testCreateValidatesRequiredFields(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/vehicles', [
            'name' => '',
            'brand' => '',
            'model' => '',
            'year' => 1500,  // before 1900 → invalid
            'type' => 'car',
            'fuelType' => 'gasoline',
        ], session: $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testInitialKmIsBoundedInsteadOfOverflowingTheColumn(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $payload = ['name' => 'Km', 'brand' => 'Fiat', 'model' => 'Panda', 'year' => 2019, 'type' => 'car', 'fuelType' => 'gasoline'];

        // Prima 3.000.000.000 superava l'INT della colonna e finiva in un 500
        $this->jsonRequest('POST', '/api/vehicles', $payload + ['initialKm' => 3_000_000_000], session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertSame([['field' => 'initialKm', 'message' => 'common.km_too_large']], $this->jsonBody()['errors']);

        $this->jsonRequest('POST', '/api/vehicles', $payload + ['initialKm' => 10_000_000], session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'initialKm', 'message' => 'common.km_too_large']], $this->jsonBody()['errors']);

        // I negativi mantengono la propria chiave
        $this->jsonRequest('POST', '/api/vehicles', $payload + ['initialKm' => -1], session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'initialKm', 'message' => 'common.too_small']], $this->jsonBody()['errors']);

        // Il massimo consentito passa
        $this->jsonRequest('POST', '/api/vehicles', $payload + ['initialKm' => 9_999_999], session: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(9_999_999, $this->jsonBody()['initialKm']);
    }

    public function testUpdateChangesFields(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org, 'name' => 'old']);

        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), [
            'name' => 'new name',
            'brand' => 'BMW',
            'model' => 'X1',
            'year' => 2023,
            'type' => 'car',
            'fuelType' => 'hybrid',
        ], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('new name', $this->jsonBody()['name']);
    }

    /**
     * Veicolo bi-fuel (benzina + GPL) con un rifornimento per ciascun carburante nell'org dell'utente.
     *
     * @return array{0: Vehicle, 1: string}
     */
    private function biFuelVehicleWithRefuelings(bool $archived = false): array
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::GASOLINE,
            'secondaryFuelType' => FuelType::LPG,
            'archivedAt' => $archived ? new \DateTimeImmutable('2026-01-15') : null,
        ]);
        foreach ([FuelType::GASOLINE, FuelType::LPG] as $fuel) {
            RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'fuelType' => $fuel]);
        }

        return [$vehicle, $token];
    }

    /** @return array<string, mixed> */
    private function updatePayload(?string $fuelType, ?string $secondary): array
    {
        return ['name' => 'Auto', 'brand' => 'Fiat', 'model' => 'Panda', 'year' => 2020, 'type' => 'car', 'fuelType' => $fuelType, 'secondaryFuelType' => $secondary];
    }

    public function testUpdateRejectsDroppingASecondaryFuelUsedByRefuelings(): void
    {
        [$vehicle, $token] = $this->biFuelVehicleWithRefuelings();

        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), $this->updatePayload('gasoline', null), session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->jsonBody()['title']);
        self::assertSame([['field' => 'secondaryFuelType', 'message' => 'vehicle.fuel_type_in_use']], $this->jsonBody()['errors']);

        // Nulla è stato modificato
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId(), session: $token);
        self::assertSame('lpg', $this->jsonBody()['secondaryFuelType']);
    }

    public function testUpdateRejectsReplacingAPrimaryFuelUsedByRefuelings(): void
    {
        [$vehicle, $token] = $this->biFuelVehicleWithRefuelings();

        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), $this->updatePayload('diesel', 'lpg'), session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'fuelType', 'message' => 'vehicle.fuel_type_in_use']], $this->jsonBody()['errors']);
    }

    public function testUpdateDroppingAnUnusedFuelWorks(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org, 'fuelType' => FuelType::GASOLINE, 'secondaryFuelType' => FuelType::LPG]);
        // Solo benzina rifornita: il GPL non è mai stato usato
        RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'fuelType' => FuelType::GASOLINE]);

        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), $this->updatePayload('gasoline', null), session: $token);

        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody()['secondaryFuelType']);
    }

    public function testUpdateAddingAFuelAndSwappingFuelsWorkEvenWithRefuelings(): void
    {
        [$vehicle, $token] = $this->biFuelVehicleWithRefuelings();

        // Scambio primario/secondario: nessun carburante tolto
        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), $this->updatePayload('lpg', 'gasoline'), session: $token);
        self::assertResponseIsSuccessful();
        self::assertSame('lpg', $this->jsonBody()['fuelType']);

        // Mono-carburante che diventa bi-fuel: carburante aggiunto
        [, $org, $token2] = $this->createAuthenticatedUser();
        $mono = VehicleFactory::createOne(['organization' => $org, 'fuelType' => FuelType::DIESEL, 'secondaryFuelType' => null]);
        RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $mono, 'fuelType' => FuelType::DIESEL]);
        $this->jsonRequest('PUT', '/api/vehicles/'.$mono->getId(), $this->updatePayload('diesel', 'lpg'), session: $token2);
        self::assertResponseIsSuccessful();
        self::assertSame('lpg', $this->jsonBody()['secondaryFuelType']);
    }

    public function testUpdateWithoutChangingFuelsWorksWithRefuelings(): void
    {
        [$vehicle, $token] = $this->biFuelVehicleWithRefuelings();

        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), ['name' => 'Rinominata'] + $this->updatePayload('gasoline', 'lpg'), session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('Rinominata', $this->jsonBody()['name']);
    }

    public function testArchivedVehicleStaysEditableAndKeepsTheFuelRule(): void
    {
        [$vehicle, $token] = $this->biFuelVehicleWithRefuelings(archived: true);

        // Una modifica che non tocca i carburanti resta consentita sull'archiviato
        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), ['name' => 'Archiviata rinominata'] + $this->updatePayload('gasoline', 'lpg'), session: $token);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->jsonBody()['archivedAt']);

        // Togliere un carburante usato resta vietato anche su un archiviato
        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), $this->updatePayload('gasoline', null), session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('vehicle.fuel_type_in_use', $this->jsonBody()['errors'][0]['message']);
    }

    public function testDeleteRemovesVehicle(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $id = $vehicle->getId();  // salva l'id prima della delete (il proxy lo dimentica)

        $this->jsonRequest('DELETE', '/api/vehicles/'.$id, session: $token);
        self::assertResponseStatusCodeSame(204);

        $repo = static::getContainer()->get(VehicleRepository::class);
        self::assertNull($repo->find($id));
    }

    public function testArchiveSetsArchivedAt(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/archive', session: $token);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->jsonBody()['archivedAt']);
    }

    /** Membro accettato (ruolo member) dell'org, con nome e cognome noti. */
    private function memberOf(Organization $org, string $first, string $last, OrgRole $role = OrgRole::MEMBER): User
    {
        $user = UserFactory::createOne(['firstName' => $first, 'lastName' => $last]);
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $user, 'role' => $role]);

        return $user;
    }

    /** @return list<array<string, mixed>> */
    private function shares(Vehicle $vehicle, string $token): array
    {
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/shares', session: $token);

        return array_values($this->jsonBody());
    }

    public function testCreateShareIsAlwaysReadOnly(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $invitee = $this->memberOf($org, 'Anna', 'Bianchi');

        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/shares', [
            'userIds' => [$invitee->getId()],
            'role' => ShareRole::EDITOR->value, // ignorato: gli share sono sempre in sola lettura
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertCount(1, $body);
        $created = reset($body);
        self::assertIsArray($created);
        self::assertSame('viewer', $created['role']);
        self::assertSame('Anna', $created['user']['firstName'], 'La risposta espone l\'utente condiviso');
        self::assertSame('Bianchi', $created['user']['lastName']);
    }

    public function testShareWithSeveralMembersAtOnce(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $anna = $this->memberOf($org, 'Anna', 'Bianchi');
        $carlo = $this->memberOf($org, 'Carlo', 'Alberti');

        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/shares', [
            'userIds' => [$anna->getId(), $carlo->getId()],
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $this->jsonBody());
        self::assertCount(2, $this->shares($vehicle, $token));
    }

    public function testShareIsIdempotentForAlreadySharedMembers(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $anna = $this->memberOf($org, 'Anna', 'Bianchi');
        $payload = ['userIds' => [$anna->getId()]];

        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/shares', $payload, session: $token);
        self::assertResponseStatusCodeSame(201);

        // Secondo invio (doppio click, richiesta ripetuta): nessun errore, nessun duplicato.
        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/shares', $payload, session: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $this->jsonBody(), 'Niente di nuovo da creare');
        self::assertCount(1, $this->shares($vehicle, $token));
    }

    public function testUnknownAndOutsiderIdsAreIndistinguishable(): void
    {
        // Senza email digitata non c'è modo di sondare quali account esistono:
        // id inesistente e id di un utente reale ma estraneo all'org → stessa risposta.
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $outsider = UserFactory::createOne();
        $url = '/api/vehicles/'.$vehicle->getId().'/shares';

        $this->jsonRequest('POST', $url, ['userIds' => [$outsider->getId()]], session: $token);
        $outsiderStatus = $this->client->getResponse()->getStatusCode();
        $outsiderBody = $this->jsonBody();

        $this->jsonRequest('POST', $url, ['userIds' => [99999999]], session: $token);

        self::assertSame(422, $outsiderStatus);
        self::assertSame('share.not_org_member', $outsiderBody['title']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($outsiderBody, $this->jsonBody());
    }

    public function testCannotShareWithOrgAdminSelfOrPendingMember(): void
    {
        [$me, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $admin = $this->memberOf($org, 'Ada', 'Admin', OrgRole::ADMIN);
        $pending = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $pending, 'acceptedAt' => null]);
        $url = '/api/vehicles/'.$vehicle->getId().'/shares';

        foreach ([$admin->getId(), $me->getId(), $pending->getId()] as $id) {
            $this->jsonRequest('POST', $url, ['userIds' => [$id]], session: $token);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('share.not_org_member', $this->jsonBody()['title']);
        }
        self::assertSame([], $this->shares($vehicle, $token));
    }

    public function testShareIsAtomicWhenOneIdIsInvalid(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $anna = $this->memberOf($org, 'Anna', 'Bianchi');

        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/shares', [
            'userIds' => [$anna->getId(), 99999999],
        ], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->shares($vehicle, $token), 'Nessuno share parziale');
    }

    public function testShareRejectsMissingOrMalformedUserIds(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $url = '/api/vehicles/'.$vehicle->getId().'/shares';

        foreach ([[], ['userIds' => []], ['userIds' => ['anna@test.it']], ['userIds' => [0]], ['userEmail' => 'anna@test.it']] as $body) {
            $this->jsonRequest('POST', $url, $body, session: $token);
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testShareCandidatesListsOnlyShareableMembersByName(): void
    {
        [$me, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $anna = $this->memberOf($org, 'Anna', 'Bianchi');
        $carlo = $this->memberOf($org, 'Carlo', 'Alberti');
        $this->memberOf($org, 'Ada', 'Admin', OrgRole::ADMIN);          // vede già tutto
        $shared = $this->memberOf($org, 'Sara', 'Condivisa');            // già condiviso
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $shared, 'role' => ShareRole::VIEWER]);
        $pending = UserFactory::createOne();                    // invito non accettato
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $pending, 'acceptedAt' => null]);
        UserFactory::createOne();                                        // estraneo all'org

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/share-candidates', session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                ['id' => $carlo->getId(), 'firstName' => 'Carlo', 'lastName' => 'Alberti'],
                ['id' => $anna->getId(), 'firstName' => 'Anna', 'lastName' => 'Bianchi'],
            ],
            $this->jsonBody(),
            'Solo member accettati non ancora condivisi, per cognome, e senza email',
        );
        self::assertNotContains($me->getId(), array_column($this->jsonBody(), 'id'), 'Non include chi condivide');
    }

    public function testSharesShowNamesOnlyNeverEmailsToAPlainMemberOwner(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $peer = $this->memberOf($org, 'Luca', 'Verdi');

        $this->jsonRequest('POST', '/api/vehicles', $this->vehiclePayload(), session: $token);
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody()['id'];

        $this->jsonRequest('POST', '/api/vehicles/'.$id.'/shares', ['userIds' => [$peer->getId()]], session: $token);
        self::assertResponseStatusCodeSame(201);
        $created = array_values($this->jsonBody());

        $this->jsonRequest('GET', '/api/vehicles/'.$id.'/shares', session: $token);
        self::assertResponseIsSuccessful();
        $listed = array_values($this->jsonBody());
        self::assertNotEmpty($listed);

        // Un membro semplice non deve poter ricavare le email dei colleghi dalle share (solo id, nome e cognome).
        foreach ([...$created, ...$listed] as $share) {
            self::assertSame(['id', 'firstName', 'lastName'], array_keys($share['user']));
            if (isset($share['invitedBy'])) {
                self::assertArrayNotHasKey('email', $share['invitedBy']);
            }
        }
        self::assertSame('Luca', $created[0]['user']['firstName']);
        self::assertStringNotContainsString($peer->getEmail(), (string) $this->client->getResponse()->getContent());
    }

    public function testMemberWhoOwnsAVehicleCanListCandidates(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $other = $this->memberOf($org, 'Luca', 'Verdi');

        $this->jsonRequest('POST', '/api/vehicles', $this->vehiclePayload(), session: $token);
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody()['id'];

        $this->jsonRequest('GET', '/api/vehicles/'.$id.'/share-candidates', session: $token);
        self::assertResponseIsSuccessful();
        self::assertSame([$other->getId()], array_column($this->jsonBody(), 'id'));
    }

    public function testShareCandidatesRequireEditPermissionAndOrgScope(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $viewer = $this->memberOf($org, 'Vera', 'Lettrice');
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $viewer, 'role' => ShareRole::VIEWER]);
        $url = '/api/vehicles/'.$vehicle->getId().'/share-candidates';

        // Chi ha solo lo share in lettura non può vedere né gestire le condivisioni.
        $this->jsonRequest('GET', $url, session: $this->sessionFor($viewer, $org));
        self::assertResponseStatusCodeSame(403);

        // Un'altra organizzazione non vede nemmeno che il veicolo esiste.
        [, , $foreignToken] = $this->createAuthenticatedUser();
        $this->jsonRequest('GET', $url, session: $foreignToken);
        self::assertResponseStatusCodeSame(404);

        $this->client->getCookieJar()->clear(); // richiesta anonima
        $this->jsonRequest('GET', $url);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOrgAdminCreatingVehicleBecomesItsOwner(): void
    {
        [$admin, , $token] = $this->createAuthenticatedUser(OrgRole::ADMIN);

        $this->jsonRequest('POST', '/api/vehicles', $this->vehiclePayload(), session: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('owned', $this->jsonBody()['ownership']);
        $id = $this->jsonBody()['id'];

        // Anche owner/admin dell'org: la proprietà decide totali, grafici e notifiche del veicolo.
        $this->jsonRequest('GET', '/api/vehicles/'.$id.'/shares', session: $token);
        $shares = $this->jsonBody();
        self::assertCount(1, $shares);
        $ownerShare = reset($shares);
        self::assertIsArray($ownerShare);
        self::assertSame('admin', $ownerShare['role']);
        self::assertSame($admin->getId(), $ownerShare['user']['id']);
    }

    public function testListTellsOwnedSharedAndOrganizationVehiclesApart(): void
    {
        [$admin, $org, $adminToken] = $this->createAuthenticatedUser(OrgRole::ADMIN);
        $member = $this->memberOf($org, 'Mario', 'Rossi');
        $memberToken = $this->sessionFor($member, $org);

        $adminCar = VehicleFactory::createOne(['organization' => $org, 'name' => 'Auto admin']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $adminCar, 'user' => $admin]);
        $memberCar = VehicleFactory::createOne(['organization' => $org, 'name' => 'Auto membro']);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $memberCar, 'user' => $member]);
        // L'admin condivide la sua auto col membro: per lui è in sola lettura.
        VehicleShareFactory::createOne(['vehicle' => $adminCar, 'user' => $member]);

        $this->jsonRequest('GET', '/api/vehicles', session: $adminToken);
        self::assertResponseIsSuccessful();
        $byName = array_column($this->jsonBody(), null, 'name');
        self::assertSame('owned', $byName['Auto admin']['ownership']);
        self::assertSame('organization', $byName['Auto membro']['ownership'], 'L\'admin la vede, ma non è sua');
        self::assertTrue($byName['Auto membro']['permissions']['canEdit']);

        $this->jsonRequest('GET', '/api/vehicles', session: $memberToken);
        $byName = array_column($this->jsonBody(), null, 'name');
        self::assertSame('owned', $byName['Auto membro']['ownership']);
        self::assertSame(['canEdit' => true, 'canDelete' => true, 'canShare' => true], $byName['Auto membro']['permissions']);
        self::assertSame('shared', $byName['Auto admin']['ownership']);
        self::assertSame(['canEdit' => false, 'canDelete' => false, 'canShare' => false], $byName['Auto admin']['permissions']);

        $this->jsonRequest('GET', '/api/vehicles/'.$adminCar->getId(), session: $memberToken);
        self::assertSame('shared', $this->jsonBody()['ownership']);
    }

    public function testMemberCreatingVehicleBecomesOwnerAndCannotRevokeIt(): void
    {
        [$member, , $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);

        $this->jsonRequest('POST', '/api/vehicles', $this->vehiclePayload(), session: $token);
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody()['id'];

        $this->jsonRequest('GET', '/api/vehicles/'.$id.'/shares', session: $token);
        $shares = $this->jsonBody();
        self::assertCount(1, $shares);
        $ownerShare = reset($shares);
        self::assertIsArray($ownerShare);
        self::assertSame('admin', $ownerShare['role']);
        self::assertSame($member->getId(), $ownerShare['user']['id']);

        $this->jsonRequest('DELETE', '/api/vehicles/'.$id.'/shares/'.$ownerShare['id'], session: $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('share.cannot_remove_owner', $this->jsonBody()['title']);
    }

    public function testViewerShareGetsReadOnlyPermissions(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        [$viewer] = $this->createAuthenticatedUser();
        OrganizationMemberFactory::createOne([
            'organization' => $org,
            'user' => $viewer,
            'role' => OrgRole::MEMBER,
        ]);
        VehicleShareFactory::createOne([
            'vehicle' => $vehicle,
            'user' => $viewer,
            'role' => ShareRole::VIEWER,
        ]);
        $viewerToken = $this->sessionFor($viewer, $org);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId(), session: $viewerToken);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['canEdit' => false, 'canDelete' => false, 'canShare' => false],
            $this->jsonBody()['permissions'],
        );

        $this->jsonRequest('PUT', '/api/vehicles/'.$vehicle->getId(), $this->vehiclePayload(), session: $viewerToken);
        self::assertResponseStatusCodeSame(403);

        $this->jsonRequest('POST', '/api/vehicles/'.$vehicle->getId().'/shares', [
            'userIds' => [$viewer->getId()],
        ], session: $viewerToken);
        self::assertResponseStatusCodeSame(403, 'Chi riceve uno share non può ricondividere');
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->jsonRequest('GET', '/api/vehicles');
        self::assertResponseStatusCodeSame(401);
    }

    // ----- Bi-fuel -----

    public function testCreateBiFuelVehicle(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/vehicles', [
            'name' => 'La Punto GPL',
            'brand' => 'Fiat',
            'model' => 'Punto',
            'year' => 2018,
            'type' => 'car',
            'fuelType' => 'gasoline',
            'secondaryFuelType' => 'lpg',  // bi-fuel benzina + GPL
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame('gasoline', $body['fuelType']);
        self::assertSame('lpg', $body['secondaryFuelType']);
    }

    public function testCreateHybridVehicle(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/vehicles', [
            'name' => 'La Auris',
            'brand' => 'Toyota',
            'model' => 'Auris Hybrid',
            'year' => 2022,
            'type' => 'car',
            'fuelType' => 'gasoline',
            'secondaryFuelType' => 'electric',
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('electric', $this->jsonBody()['secondaryFuelType']);
    }

    public function testBiFuelRejectsDuplicateFuelTypes(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/vehicles', [
            'name' => 'Invalida',
            'brand' => 'X', 'model' => 'Y', 'year' => 2020,
            'type' => 'car',
            'fuelType' => 'gasoline',
            'secondaryFuelType' => 'gasoline',  // duplicato → 422
        ], session: $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateMonoFuelVehicle(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/vehicles', [
            'name' => 'La Diesel',
            'brand' => 'BMW', 'model' => '320d', 'year' => 2020,
            'type' => 'car',
            'fuelType' => 'diesel',
            // secondaryFuelType non passato → null
        ], session: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->jsonBody()['secondaryFuelType']);
    }

    // ----- helpers -----

    /**
     * @return array<string, mixed>
     */
    private function vehiclePayload(): array
    {
        return [
            'name' => 'Panda di casa',
            'brand' => 'Fiat',
            'model' => 'Panda',
            'year' => 2020,
            'type' => 'car',
            'fuelType' => 'diesel',
        ];
    }

}
