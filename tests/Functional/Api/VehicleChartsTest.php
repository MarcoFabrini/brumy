<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\ExpenseCategory;
use App\Enum\FuelType;
use App\Enum\OrgRole;
use App\Enum\RecurringPeriod;
use App\Enum\ShareRole;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ChartFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/vehicles/{id}/charts: accesso (chi vede il veicolo, 404 fuori org, 403 senza VIEW),
 * contratto uguale alla dashboard e isolamento (solo i dati del veicolo richiesto).
 *
 * Importi distinti (10.00 il veicolo richiesto, 1000.00 gli altri) rendono evidente ogni fuga.
 */
final class VehicleChartsTest extends ApiTestCase
{
    use ChartFixtures;

    // ---------------- accesso ----------------

    public function testRequiresAuthentication(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/charts');

        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerGetsTheVehicleSeries(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->ownData($vehicle);

        $this->assertOnlyOwnData($this->charts($token, $vehicle));
    }

    public function testOrgAdminSeesAnotherMembersVehicle(): void
    {
        [, $org, $adminToken] = $this->createAuthenticatedUser(OrgRole::ADMIN);
        $vehicle = $this->ownedVehicle($this->member($org), $org, ['initialKm' => 1000]);
        $this->ownData($vehicle);

        $this->assertOnlyOwnData($this->charts($adminToken, $vehicle));
    }

    #[DataProvider('readOnlyShares')]
    public function testReadOnlyShareSeesTheVehicleCharts(ShareRole $role): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($this->member($org), $org, ['initialKm' => 1000]);
        $this->ownData($vehicle);
        $viewer = $this->member($org);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $viewer, 'role' => $role]);

        $this->assertOnlyOwnData($this->charts($this->tokenFor($viewer, $org), $vehicle));
    }

    /** @return iterable<string, array{ShareRole}> */
    public static function readOnlyShares(): iterable
    {
        yield 'viewer' => [ShareRole::VIEWER];
        yield 'vecchio editor' => [ShareRole::EDITOR];
    }

    public function testMemberWithoutShareIsForbidden(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($this->member($org), $org);
        $stranger = $this->member($org);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/charts', accessToken: $this->tokenFor($stranger, $org));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnacceptedShareIsForbidden(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($this->member($org), $org);
        $pending = $this->member($org);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $pending, 'acceptedAt' => null]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/charts', accessToken: $this->tokenFor($pending, $org));

        self::assertResponseStatusCodeSame(403);
    }

    public function testVehicleOfAnotherOrganizationIsNotFoundEvenIfOwnedThere(): void
    {
        [$user, , $token] = $this->createAuthenticatedUser();
        $otherOrg = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $otherOrg, 'user' => $user, 'role' => OrgRole::OWNER]);
        $elsewhere = $this->ownedVehicle($user, $otherOrg);

        $this->jsonRequest('GET', '/api/vehicles/'.$elsewhere->getId().'/charts', accessToken: $token);
        self::assertResponseStatusCodeSame(404);

        $this->jsonRequest('GET', '/api/vehicles/999999/charts', accessToken: $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testArchivedVehicleKeepsItsSeries(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000, 'archivedAt' => new \DateTimeImmutable('-1 day')]);
        $this->ownData($vehicle);

        $this->assertOnlyOwnData($this->charts($token, $vehicle));
    }

    // ---------------- contratto ----------------

    #[DataProvider('monthsParam')]
    public function testMonthsParamIsClampedLikeTheDashboard(string $param, int $expectedCount): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org);

        $body = $this->charts($token, $vehicle, $param);

        self::assertCount($expectedCount, $body['months']);
        self::assertSame($this->monthKey(0), $body['to']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function monthsParam(): iterable
    {
        yield 'default' => ['', 12];
        yield 'tre' => ['?months=3', 3];
        yield 'zero' => ['?months=0', 1];
        yield 'non numerico' => ['?months=abc', 1];
        yield 'oltre il massimo' => ['?months=999', 24];
    }

    public function testFuelTypesAreOnlyTheVehicleOwnInOrder(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $biFuel = $this->ownedVehicle($owner, $org, ['fuelType' => FuelType::GASOLINE, 'secondaryFuelType' => FuelType::LPG]);
        $this->ownedVehicle($owner, $org, ['fuelType' => FuelType::DIESEL]);

        $body = $this->charts($token, $biFuel, '?months=2');

        self::assertSame(['gasoline', 'lpg'], $body['fuelTypes']);
        self::assertSame([['gasoline' => null, 'lpg' => null], ['gasoline' => null, 'lpg' => null]], array_column($body['months'], 'consumption'));
        self::assertSame(['0.00', '0.00'], array_map(static fn (array $p): string => $p['spending']['total'], $body['months']));
    }

    // ---------------- isolamento ----------------

    public function testOnlyTheRequestedVehicleContributes(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->ownData($vehicle);

        // Un altro veicolo dello stesso proprietario (carburante diverso), di un altro membro e di un'altra org.
        $this->foreignData($this->ownedVehicle($owner, $org, ['fuelType' => FuelType::GASOLINE]));
        $this->foreignData($this->ownedVehicle($this->member($org), $org, ['fuelType' => FuelType::GASOLINE]));
        $this->foreignData($this->ownedVehicle(UserFactory::createOne(), OrganizationFactory::createOne(), ['fuelType' => FuelType::GASOLINE]));

        $this->assertOnlyOwnData($this->charts($token, $vehicle));
    }

    public function testSingleOwnedVehicleMatchesTheDashboard(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->ownData($vehicle);
        $this->expense($vehicle, $this->day(-1, 10), '35.50', ExpenseCategory::INSURANCE);

        $this->jsonRequest('GET', '/api/dashboard/charts?months=6', accessToken: $token);
        self::assertResponseIsSuccessful();
        $dashboard = $this->jsonBody();

        self::assertSame($dashboard, $this->charts($token, $vehicle, '?months=6'));
    }

    // ---------------- spese ricorrenti ----------------

    public function testRecurringExpenseCountsEveryChargeAlreadyDueInTheVehicleSeries(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org);
        $this->recurringExpense($vehicle, $this->day(-3), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION);
        $this->expense($vehicle, $this->day(-2, 10), '30.00', ExpenseCategory::SUBSCRIPTION);

        $body = $this->charts($token, $vehicle, '?months=4');

        self::assertSame(['50.00', '80.00', '50.00', '50.00'], array_map(static fn (array $p): string => $p['spending']['expenses'], $body['months']));
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::SUBSCRIPTION, '230.00')], $body['spendingByCategory']);
        self::assertSame('230.00', $body['totals']['spending']);
    }

    public function testRecurringUntilStopsTheChargesAndFutureOnesAreNotCounted(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org);
        $this->recurringExpense($vehicle, $this->day(-4), '20.00', RecurringPeriod::MONTHLY, ExpenseCategory::PARKING, $this->day(-2, 15));
        $this->recurringExpense($vehicle, $this->day(1), '999.00', RecurringPeriod::WEEKLY, ExpenseCategory::PARKING);

        $body = $this->charts($token, $vehicle, '?months=5');

        self::assertSame(['20.00', '20.00', '20.00', '0.00', '0.00'], array_map(static fn (array $p): string => $p['spending']['expenses'], $body['months']));
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::PARKING, '60.00')], $body['spendingByCategory']);
    }

    public function testArchivedVehicleKeepsItsRecurringCharges(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['archivedAt' => new \DateTimeImmutable('-1 day')]);
        $this->recurringExpense($vehicle, $this->day(-1), '40.00', RecurringPeriod::MONTHLY, ExpenseCategory::INSURANCE);

        $body = $this->charts($token, $vehicle, '?months=2');

        self::assertSame(['40.00', '40.00'], array_map(static fn (array $p): string => $p['spending']['total'], $body['months']));
        self::assertSame('80.00', $body['totals']['spending']);
    }

    public function testRecurringChargesOfOtherVehiclesDoNotLeak(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org);
        $this->recurringExpense($vehicle, $this->day(-1), '10.00', RecurringPeriod::MONTHLY, ExpenseCategory::INSURANCE);
        $this->recurringExpense($this->ownedVehicle($owner, $org), $this->day(-1), '1000.00', RecurringPeriod::WEEKLY, ExpenseCategory::INSURANCE);
        $this->recurringExpense($this->ownedVehicle(UserFactory::createOne(), OrganizationFactory::createOne()), $this->day(-1), '1000.00', RecurringPeriod::WEEKLY, ExpenseCategory::INSURANCE);

        $body = $this->charts($token, $vehicle, '?months=2');

        self::assertSame(['10.00', '10.00'], array_map(static fn (array $p): string => $p['spending']['total'], $body['months']));
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::INSURANCE, '20.00')], $body['spendingByCategory']);
        self::assertSame('20.00', $body['totals']['spending']);
    }

    public function testMonthlyTotalsTotalSpendingAndCategoriesAgreeWithRecurringExpenses(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->ownData($vehicle);
        $this->maintenance($vehicle, $this->day(-3), 1050, '99.99');
        $this->recurringExpense($vehicle, $this->day(-7, 20), '33.33', RecurringPeriod::WEEKLY, ExpenseCategory::PARKING);
        $this->recurringExpense($vehicle, $this->day(-9, 31), '8.88', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION, $this->day(-1, 10));

        $body = $this->charts($token, $vehicle, '?months=6');

        $monthly = array_sum(array_map(static fn (array $p): int => (int) round((float) $p['spending']['total'] * 100), $body['months']));
        $categories = array_sum(array_map(static fn (array $c): int => (int) round((float) $c['amount'] * 100), $body['spendingByCategory']));
        self::assertGreaterThan(0, $monthly);
        self::assertSame((int) round((float) $body['totals']['spending'] * 100), $monthly);
        self::assertSame($monthly, $categories);
    }

    // ---------------- helper ----------------

    /** Due pieni (1000 → 1125 km, 10 l), 10.00 + 10.00 di rifornimenti, negli ultimi due mesi (km iniziali 1000). */
    private function ownData(Vehicle $vehicle): void
    {
        $this->refueling($vehicle, $this->day(-1), 1000, '10.000', '1.0000');
        $this->refueling($vehicle, $this->day(0), 1125, '10.000', '1.0000');
    }

    /** Dati da 1000.00 a benzina su tutte le sorgenti, nella finestra. */
    private function foreignData(Vehicle $vehicle): void
    {
        $this->refueling($vehicle, $this->day(-1), 50000, '500.000', '2.0000', fuel: FuelType::GASOLINE);
        $this->refueling($vehicle, $this->day(0), 50600, '500.000', '2.0000', fuel: FuelType::GASOLINE);
        $this->maintenance($vehicle, $this->day(0), 51000, '1000.00');
        $this->expense($vehicle, $this->day(0), '1000.00', ExpenseCategory::TOLL);
        // Anche una spesa ricorrente (addebiti nella finestra) non deve filtrare nei grafici altrui.
        $this->recurringExpense($vehicle, $this->day(-1), '1000.00', RecurringPeriod::MONTHLY, ExpenseCategory::INSURANCE);
    }

    /** @param array<string, mixed> $body */
    private function assertOnlyOwnData(array $body): void
    {
        self::assertSame(['diesel'], $body['fuelTypes']);
        $lastTwo = array_slice($body['months'], -2);
        self::assertSame(['10.00', '10.00'], array_map(static fn (array $p): string => $p['spending']['total'], $lastTwo));
        self::assertSame([0, 125], array_column($lastTwo, 'kmDriven'));
        self::assertSame([['diesel' => null], ['diesel' => 12.5]], array_column($lastTwo, 'consumption'));
        self::assertSame([$this->costSlice('fuel', '20.00')], $body['spendingByCategory']);
        self::assertSame(['spending' => '20.00', 'kmDriven' => 125], $body['totals']);
    }

    /** @return array<string, mixed> */
    private function charts(string $token, Vehicle $vehicle, string $query = ''): array
    {
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/charts'.$query, accessToken: $token);
        self::assertResponseIsSuccessful();

        return $this->jsonBody();
    }

    private function member(Organization $org): User
    {
        $user = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $user, 'role' => OrgRole::MEMBER]);

        return $user;
    }
}
