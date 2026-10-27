<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\ExpenseCategory;
use App\Enum\OrgRole;
use App\Enum\ShareRole;
use App\Enum\UserValueKind;
use App\Repository\UserValueRepository;
use App\Service\UserValues;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;

final class UserValueControllerTest extends ApiTestCase
{
    private function values(): UserValues
    {
        return static::getContainer()->get(UserValues::class);
    }

    private function repo(): UserValueRepository
    {
        return static::getContainer()->get(UserValueRepository::class);
    }

    private function station(User $user, Organization $org, string $name, UserValueKind $kind = UserValueKind::STATION): UserValue
    {
        return $this->values()->findOrCreate($user, $org, $kind, $name);
    }

    private function refuelAt(Vehicle $vehicle, UserValue $station, string $date = '2026-03-01'): void
    {
        RefuelingFactory::createOne([
            'vehicle' => $vehicle,
            'organization' => $vehicle->getOrganization(),
            'station' => $station,
            'refueledAt' => new \DateTimeImmutable($date),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->jsonBody();
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_column($this->jsonBody(), 'name');
    }

    public function testSuggestionsOrderedByUsage(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->repoOwner($vehicle, $user);
        $eni = $this->station($user, $org, 'Eni');
        $q8 = $this->station($user, $org, 'Q8');
        $this->station($user, $org, 'Agip');
        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $d) {
            $this->refuelAt($vehicle, $eni, $d);
        }
        $this->refuelAt($vehicle, $q8);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/station', session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['Eni', 'Q8', 'Agip'], $this->names());
        self::assertSame(['id', 'name', 'starterKey', 'fuelStationId'], array_keys($this->rows()[0]));
    }

    public function testSuggestionsTieBrokenByMostRecentUseThenName(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->repoOwner($vehicle, $user);
        $old = $this->station($user, $org, 'Vecchio');
        $recent = $this->station($user, $org, 'Recente');
        $this->station($user, $org, 'Bianco');
        $this->station($user, $org, 'Alfa');
        $this->refuelAt($vehicle, $old, '2025-01-01');
        $this->refuelAt($vehicle, $recent, '2026-06-01');

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/station', session: $token);

        self::assertSame(['Recente', 'Vecchio', 'Alfa', 'Bianco'], $this->names());
    }

    public function testSuggestionsForWorkshopsAndCategories(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->repoOwner($vehicle, $user);
        $shop = $this->station($user, $org, 'Rossi', UserValueKind::WORKSHOP);
        MaintenanceFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'workshop' => $shop]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/workshop', session: $token);
        self::assertSame(['Rossi'], $this->names());

        ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'category' => ExpenseCategory::TOLL]);
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/expense_category', session: $token);
        self::assertCount(\count(ExpenseCategory::starters()), $this->jsonBody());
        self::assertSame('Pedaggio', $this->names()[0]);
    }

    public function testSuggestionsComeFromVehicleOwnerList(): void
    {
        [$admin, $org, $token] = $this->createAuthenticatedUser();
        $anna = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $anna, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->repoOwner($vehicle, $anna);
        $this->station($anna, $org, 'Distributore di Anna');
        $this->station($admin, $org, 'Distributore admin');

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/station', session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['Distributore di Anna'], $this->names());
    }

    public function testSuggestionsForOrphanVehicleUseTheCallersList(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->station($user, $org, 'Mio');

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/station', session: $token);

        self::assertSame(['Mio'], $this->names());
    }

    public function testSuggestionsVehicleOutsideOrgIs404(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => OrganizationFactory::createOne()]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/station', session: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('vehicle.not_found', $this->jsonBody()['title']);
    }

    public function testSuggestionsWithoutEditRightIs403(): void
    {
        [$viewer, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $viewer]); // viewer

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/station', session: $token);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListHasUsageCountByNameAndStarters(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->repoOwner($vehicle, $user);
        $eni = $this->station($user, $org, 'Eni');
        $this->station($user, $org, 'Agip');
        $this->refuelAt($vehicle, $eni);
        $this->refuelAt($vehicle, $eni, '2026-04-01');

        $this->jsonRequest('GET', '/api/values/station', session: $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['Agip', 'Eni'], $this->names());
        self::assertSame([0, 2], array_column($this->jsonBody(), 'usageCount'));

        $this->jsonRequest('GET', '/api/values/expense_category', session: $token);
        self::assertCount(\count(ExpenseCategory::starters()), $this->jsonBody());
    }

    public function testListIsScopedToTheAuthenticatedUser(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $other = UserFactory::createOne();
        $this->station($other, $org, 'Altrui');
        $this->station($user, $org, 'Mio');
        $this->station($user, OrganizationFactory::createOne(), 'Altra org');

        $this->jsonRequest('GET', '/api/values/station', session: $token);

        self::assertSame(['Mio'], $this->names());
    }

    public function testInvalidKindIs404(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/values/colour', session: $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRequiresAuthentication(): void
    {
        $this->jsonRequest('GET', '/api/values/station');

        self::assertResponseStatusCodeSame(401);
    }

    public function testCreate(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/values/station', ['name' => '  Q8   Via Roma '], session: $token);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame('Q8 Via Roma', $body['name']);
        self::assertSame(0, $body['usageCount']);
        self::assertSame($body['id'], $this->repo()->findOneInList($user, $org, UserValueKind::STATION, 'q8 via roma')?->getId());
    }

    public function testCreateCategoryInAnEmptyListKeepsTheStarters(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/values/expense_category', ['name' => 'Revisione camper'], session: $token);

        self::assertResponseStatusCodeSame(201);
        $categories = $this->repo()->findBy(['user' => $user, 'organization' => $org, 'kind' => UserValueKind::EXPENSE_CATEGORY]);
        self::assertCount(\count(ExpenseCategory::starters()) + 1, $categories);
        self::assertCount(\count(ExpenseCategory::starters()), array_filter($categories, static fn (UserValue $v): bool => $v->getStarterKey() !== null));
    }

    public function testCreateStarterNameInAnEmptyCategoryListIs422(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/values/expense_category', ['name' => 'pedaggio'], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'name', 'message' => 'user_value.name_taken']], $this->jsonBody()['errors']);
        self::assertCount(\count(ExpenseCategory::starters()), $this->repo()->findBy(['user' => $user, 'organization' => $org, 'kind' => UserValueKind::EXPENSE_CATEGORY]));
    }

    public function testCreateExistingNameIs422(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $this->station($user, $org, 'Eni');

        $this->jsonRequest('POST', '/api/values/station', ['name' => ' eni'], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'name', 'message' => 'user_value.name_taken']], $this->jsonBody()['errors']);
        self::assertCount(1, $this->repo()->findBy(['user' => $user, 'kind' => UserValueKind::STATION]));
    }

    public function testCreateRejectsBlankAndTooLongNames(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/values/station', ['name' => '   '], session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('name', $this->jsonBody()['errors'][0]['field']);

        $this->jsonRequest('POST', '/api/values/station', ['name' => str_repeat('a', 201)], session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('name', $this->jsonBody()['errors'][0]['field']);
    }

    public function testUnicodeBlankNamesAreRejectedOnCreateAndRename(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $eni = $this->station($user, $org, 'Eni');
        $nbsp = "\u{00A0}\u{3000}\u{00A0}";

        $this->jsonRequest('POST', '/api/values/station', ['name' => $nbsp], session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('name', $this->jsonBody()['errors'][0]['field']);

        $this->jsonRequest('PATCH', '/api/values/'.$eni->getId(), ['name' => $nbsp], session: $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('name', $this->jsonBody()['errors'][0]['field']);

        $this->jsonRequest('GET', '/api/values/station', session: $token);
        self::assertSame(['Eni'], $this->names());
    }

    public function testRenameReturnsUsageCount(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $eni = $this->station($user, $org, 'Eni');
        $this->refuelAt($vehicle, $eni);

        $this->jsonRequest('PATCH', '/api/values/'.$eni->getId(), ['name' => 'Eni Station'], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $eni->getId(), 'name' => 'Eni Station', 'usageCount' => 1, 'fuelStationId' => null, 'followsPrice' => false], $this->jsonBody());
    }

    public function testRenameCaseVariant(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $value = $this->station($user, $org, 'q8 via roma');

        $this->jsonRequest('PATCH', '/api/values/'.$value->getId(), ['name' => 'Q8 Via Roma'], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('Q8 Via Roma', $this->jsonBody()['name']);
    }

    public function testRenameCollisionIs422(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $this->station($user, $org, 'Eni');
        $q8 = $this->station($user, $org, 'Q8');

        $this->jsonRequest('PATCH', '/api/values/'.$q8->getId(), ['name' => 'ENI'], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'name', 'message' => 'user_value.name_taken']], $this->jsonBody()['errors']);
    }

    public function testMergeMovesRecordsAndRemovesSource(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $a = $this->station($user, $org, 'Eni Via Roma');
        $b = $this->station($user, $org, 'Eni');
        $aId = $a->getId();
        $this->refuelAt($vehicle, $a);

        $this->jsonRequest('POST', '/api/values/'.$aId.'/merge', ['intoId' => $b->getId()], session: $token);

        self::assertResponseStatusCodeSame(204);
        $this->jsonRequest('GET', '/api/values/station', session: $token);
        self::assertSame([['id' => $b->getId(), 'name' => 'Eni', 'usageCount' => 1, 'fuelStationId' => null, 'followsPrice' => false]], $this->jsonBody());
    }

    public function testMergeIntoUnknownIs404(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $a = $this->station($user, $org, 'Eni');

        $this->jsonRequest('POST', '/api/values/'.$a->getId().'/merge', ['intoId' => 999999], session: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('user_value.not_found', $this->jsonBody()['title']);
    }

    public function testMergeIntoItselfIs422(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $a = $this->station($user, $org, 'Eni');

        $this->jsonRequest('POST', '/api/values/'.$a->getId().'/merge', ['intoId' => $a->getId()], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'intoId', 'message' => 'user_value.merge_invalid']], $this->jsonBody()['errors']);
    }

    public function testMergeIntoOtherKindIs422(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $station = $this->station($user, $org, 'Rossi');
        $workshop = $this->station($user, $org, 'Rossi', UserValueKind::WORKSHOP);
        $this->refuelAt($vehicle, $station);

        $this->jsonRequest('POST', '/api/values/'.$station->getId().'/merge', ['intoId' => $workshop->getId()], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('intoId', $this->jsonBody()['errors'][0]['field']);
        self::assertSame('user_value.merge_invalid', $this->jsonBody()['errors'][0]['message']);
        $this->jsonRequest('GET', '/api/values/station', session: $token);
        self::assertSame(1, $this->rows()[0]['usageCount'], 'nessun record spostato');
    }

    public function testDeleteUnused(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $a = $this->station($user, $org, 'Eni');

        $this->jsonRequest('DELETE', '/api/values/'.$a->getId(), session: $token);

        self::assertResponseStatusCodeSame(204);
        $this->jsonRequest('GET', '/api/values/station', session: $token);
        self::assertSame([], $this->jsonBody());
    }

    public function testDeleteUsedValueHidesItButRecordsKeepShowingIt(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->repoOwner($vehicle, $user);
        $eni = $this->station($user, $org, 'Eni');
        $eniId = $eni->getId();
        $refueling = RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => $eni]);

        $this->jsonRequest('DELETE', '/api/values/'.$eniId, session: $token);
        self::assertResponseStatusCodeSame(204);

        $this->jsonRequest('GET', '/api/values/station', session: $token);
        self::assertSame([], $this->jsonBody(), 'non è più nella lista');
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/station', session: $token);
        self::assertSame([], $this->jsonBody(), 'non è più suggerita');
        $this->jsonRequest('GET', '/api/refuelings/'.$refueling->getId(), session: $token);
        self::assertSame(['id' => $eniId, 'name' => 'Eni'], $this->jsonBody()['station'], 'il record la mostra ancora');

        $this->jsonRequest('PATCH', '/api/values/'.$eniId, ['name' => 'Altro'], session: $token);
        self::assertResponseStatusCodeSame(404, 'rinomina di una voce eliminata');
        $this->jsonRequest('DELETE', '/api/values/'.$eniId, session: $token);
        self::assertResponseStatusCodeSame(404, 'seconda eliminazione');
        $other = $this->station($user, $org, 'Q8');
        $this->jsonRequest('POST', '/api/values/'.$other->getId().'/merge', ['intoId' => $eniId], session: $token);
        self::assertResponseStatusCodeSame(404, 'unione in una voce eliminata');

        // Il nome torna libero: riscriverlo crea una voce nuova, i vecchi record restano su quella eliminata
        $this->jsonRequest('POST', '/api/values/station', ['name' => 'Eni'], session: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($eniId, $this->jsonBody()['id']);
        self::assertSame(0, $this->jsonBody()['usageCount']);
    }

    public function testEditingARecordKeepsItsDeletedValue(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->repoOwner($vehicle, $user);
        $eni = $this->station($user, $org, 'Eni');
        $eniId = $eni->getId();
        $refueling = RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => $eni]);
        $this->jsonRequest('DELETE', '/api/values/'.$eniId, session: $token);
        $payload = [
            'vehicleId' => $vehicle->getId(),
            'refueledAt' => '2026-05-01', 'km' => 60000,
            'liters' => '50.000', 'pricePerLiter' => '2.0000',
            'fuelType' => 'diesel', 'fullTank' => true,
        ];

        $this->jsonRequest('PUT', '/api/refuelings/'.$refueling->getId(), [...$payload, 'station' => ' eni '], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame($eniId, $this->jsonBody()['station']['id'], 'stesso nome: resta la voce eliminata');
        $this->jsonRequest('GET', '/api/values/station', session: $token);
        self::assertSame([], $this->jsonBody(), 'e non torna nella lista');

        $this->jsonRequest('PUT', '/api/refuelings/'.$refueling->getId(), [...$payload, 'station' => 'Q8'], session: $token);
        self::assertSame('Q8', $this->jsonBody()['station']['name']);
        $this->jsonRequest('GET', '/api/values/station', session: $token);
        self::assertSame(['Q8'], $this->names());
    }

    public function testDeletingEveryCategoryDoesNotBringBackTheStarters(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->jsonRequest('GET', '/api/values/expense_category', session: $token);
        $ids = array_column($this->rows(), 'id');
        self::assertCount(\count(ExpenseCategory::starters()), $ids);

        foreach ($ids as $id) {
            $this->jsonRequest('DELETE', '/api/values/'.$id, session: $token);
            self::assertResponseStatusCodeSame(204);
        }

        $this->jsonRequest('GET', '/api/values/expense_category', session: $token);
        self::assertSame([], $this->jsonBody());
    }

    public function testCannotTouchOtherUsersValue(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $mine = $this->station($user, $org, 'Mio');
        $mario = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $mario, 'organization' => $org, 'role' => OrgRole::MEMBER]);
        $theirs = $this->station($mario, $org, 'Suo');
        $theirsId = $theirs->getId();

        $this->jsonRequest('PATCH', '/api/values/'.$theirsId, ['name' => 'Rubato'], session: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('user_value.not_found', $this->jsonBody()['title']);

        $this->jsonRequest('POST', '/api/values/'.$theirsId.'/merge', ['intoId' => $mine->getId()], session: $token);
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest('POST', '/api/values/'.$mine->getId().'/merge', ['intoId' => $theirsId], session: $token);
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest('DELETE', '/api/values/'.$theirsId, session: $token);
        self::assertResponseStatusCodeSame(404);

        $still = $this->repo()->find($theirsId);
        self::assertSame('Suo', $still?->getName());
        self::assertNotNull($this->repo()->find($mine->getId()));
    }

    public function testCannotTouchValueOfAnotherOrganization(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $otherOrg = OrganizationFactory::createOne();
        $foreign = $this->station($user, $otherOrg, 'Altrove');

        $this->jsonRequest('DELETE', '/api/values/'.$foreign->getId(), session: $token);

        self::assertResponseStatusCodeSame(404);
    }

    /** Fa di $owner il proprietario (share admin accettato) del veicolo. */
    private function repoOwner(Vehicle $vehicle, User $owner): void
    {
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $owner, 'role' => ShareRole::ADMIN]);
    }
}
