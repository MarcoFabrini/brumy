<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\ExpenseCategory;
use App\Enum\ExpenseSection;
use App\Enum\FuelType;
use App\Enum\MaintenanceCategory;
use App\Enum\MaintenanceType;
use App\Enum\OrgRole;
use App\Enum\RecurringPeriod;
use App\Enum\ShareRole;
use App\Enum\UserValueKind;
use App\Service\UserValues;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ChartFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/dashboard/charts: contratto, aggregazioni mensili e soprattutto isolamento
 * (solo i veicoli di proprietà dell'utente nell'org attiva, archiviati esclusi).
 *
 * Le date si ricavano da AppClock, così i test non dipendono dal giorno di esecuzione né dal fuso.
 * Importi distinti per veicolo (10.00 il proprio, 1000.00 gli altri) rendono evidente ogni fuga.
 */
final class DashboardChartsTest extends ApiTestCase
{
    use ChartFixtures;

    // ---------------- contratto ----------------

    public function testRequiresAuthentication(): void
    {
        $this->jsonRequest('GET', '/api/dashboard/charts');

        self::assertResponseStatusCodeSame(401);
    }

    public function testDefaultWindowIsTwelveMonthsEndingWithTheCurrentOne(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $body = $this->charts($token);

        self::assertCount(12, $body['months']);
        self::assertSame($this->monthKey(0), $body['to']);
        self::assertSame($this->monthKey(-11), $body['from']);
        $expected = array_map(fn (int $offset): string => $this->monthKey($offset), range(-11, 0));
        self::assertSame($expected, array_column($body['months'], 'month'));
    }

    #[DataProvider('monthsParam')]
    public function testMonthsParamIsClamped(string $param, int $expectedCount): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $body = $this->charts($token, '?months='.$param);

        self::assertCount($expectedCount, $body['months']);
        self::assertSame($this->monthKey(-($expectedCount - 1)), $body['from']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function monthsParam(): iterable
    {
        yield 'nella finestra' => ['3', 3];
        yield 'zero' => ['0', 1];
        yield 'non numerico' => ['abc', 1];
        yield 'oltre il massimo' => ['999', 24];
    }

    public function testEmptyMonthsHaveZerosAndNullConsumptionForEveryFuel(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $this->ownedVehicle($user, $org, ['fuelType' => FuelType::GASOLINE, 'secondaryFuelType' => FuelType::LPG]);

        $body = $this->charts($token, '?months=2');

        self::assertSame(['gasoline', 'lpg'], $body['fuelTypes']);
        foreach ($body['months'] as $point) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $point['month']);
            self::assertSame(['refuelings' => '0.00', 'scheduled' => '0.00', 'unscheduled' => '0.00', 'inspection' => '0.00', 'taxes' => '0.00', 'insurance' => '0.00', 'expenses' => '0.00', 'total' => '0.00'], $point['spending']);
            self::assertSame(0, $point['kmDriven']);
            self::assertSame(['gasoline' => null, 'lpg' => null], $point['consumption']);
        }
        self::assertSame([], $body['spendingByCategory']);
        self::assertSame(['spending' => '0.00', 'kmDriven' => 0], $body['totals']);
    }

    public function testUserWithoutOwnedVehiclesGetsZeroedSeriesAndAnEmptyConsumptionObject(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        // Un veicolo dell'org con dati, che l'owner dell'org "vede" ma non possiede.
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->expense($vehicle, $this->day(0), '1000.00');

        $body = $this->charts($token, '?months=3');

        self::assertCount(3, $body['months']);
        self::assertSame([], $body['fuelTypes']);
        self::assertSame([], $body['spendingByCategory']);
        self::assertSame(['spending' => '0.00', 'kmDriven' => 0], $body['totals']);
        foreach ($body['months'] as $point) {
            self::assertSame('0.00', $point['spending']['total']);
            self::assertSame(0, $point['kmDriven']);
        }
        // Mappa vuota serializzata come oggetto, non come lista.
        self::assertStringContainsString('"consumption":{}', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('"consumption":[]', (string) $this->client->getResponse()->getContent());
    }

    // ---------------- aggregazione ----------------

    public function testMonthlySpendingSumsEverySourceAndIgnoresMaintenanceWithoutCost(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->refueling($vehicle, $this->day(-1), 1000, '40.000', '2.0000'); // 80.00
        $this->maintenance($vehicle, $this->day(-1, 5), 1100, '120.50');
        $this->maintenance($vehicle, $this->day(-1, 6), 1200, null);
        $this->expense($vehicle, $this->day(-1, 7), '30.25');
        $this->expense($vehicle, $this->day(0), '5.00');

        $body = $this->charts($token, '?months=2');

        self::assertSame(
            ['refuelings' => '80.00', 'scheduled' => '120.50', 'unscheduled' => '0.00', 'inspection' => '0.00', 'taxes' => '0.00', 'insurance' => '0.00', 'expenses' => '30.25', 'total' => '230.75'],
            $body['months'][0]['spending'],
        );
        self::assertSame(
            ['refuelings' => '0.00', 'scheduled' => '0.00', 'unscheduled' => '0.00', 'inspection' => '0.00', 'taxes' => '0.00', 'insurance' => '0.00', 'expenses' => '5.00', 'total' => '5.00'],
            $body['months'][1]['spending'],
        );
        self::assertSame('235.75', $body['totals']['spending']);
    }

    public function testWindowBoundariesAreInclusiveOfTheOldestMonthAndExcludeTheFuture(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->expense($vehicle, $this->day(-2), '10.00');                              // primo giorno del mese più vecchio: incluso
        $this->expense($vehicle, $this->day(-2)->modify('-1 day'), '1000.00');          // giorno prima: escluso
        $this->expense($vehicle, $this->day(0)->modify('last day of this month'), '1.00'); // ultimo giorno del mese corrente: incluso
        $this->expense($vehicle, $this->day(1), '1000.00');                             // mese successivo: escluso

        $body = $this->charts($token, '?months=3');

        self::assertSame(['10.00', '0.00', '1.00'], array_map(
            static fn (array $point): string => $point['spending']['expenses'],
            $body['months'],
        ));
        self::assertSame('11.00', $body['totals']['spending']);
    }

    public function testSpendingByCategoryCoversTheWholeWindowSortedByAmount(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->refueling($vehicle, $this->day(-2), 1000, '10.000', '1.0000'); // fuel 10.00
        $this->refueling($vehicle, $this->day(0), 1500, '20.000', '1.0000');  // fuel 20.00 → 30.00
        $this->maintenance($vehicle, $this->day(-1), 1200, '30.00');          // pari a fuel: ordine per chiave
        $this->maintenance($vehicle, $this->day(-1, 2), 1300, null);          // non conta
        $this->expense($vehicle, $this->day(-2), '400.00', ExpenseCategory::INSURANCE);
        $this->expense($vehicle, $this->day(0), '100.00', ExpenseCategory::INSURANCE);
        $this->expense($vehicle, $this->day(-1), '7.50', ExpenseCategory::TOLL);
        $this->expense($vehicle, $this->day(-5), '999.00', ExpenseCategory::FINE); // fuori finestra

        $body = $this->charts($token, '?months=3');

        self::assertSame([
            $this->expenseSlice($vehicle, ExpenseCategory::INSURANCE, '500.00'),
            $this->costSlice('fuel', '30.00'),
            $this->costSlice('scheduled', '30.00'),
            $this->expenseSlice($vehicle, ExpenseCategory::TOLL, '7.50'),
        ], $body['spendingByCategory']);
    }

    public function testEverySectionHasItsSeriesAndSlice(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $values = static::getContainer()->get(UserValues::class);
        $roadTax = $values->findOrCreate($user, $org, UserValueKind::TAX_TYPE, 'Bollo');
        $allianz = $values->findOrCreate($user, $org, UserValueKind::INSURER, 'Allianz');
        ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'occurredAt' => $this->day(-1), 'amount' => '300.00', 'section' => ExpenseSection::INSURANCE, 'category' => null, 'insurer' => $allianz]);
        ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'occurredAt' => $this->day(0), 'amount' => '50.00', 'section' => ExpenseSection::INSURANCE, 'category' => null, 'insurer' => null]);
        ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'occurredAt' => $this->day(0), 'amount' => '200.00', 'section' => ExpenseSection::TAX, 'category' => $roadTax]);

        $this->maintenance($vehicle, $this->day(0), 1000, '80.00');
        MaintenanceFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'performedAt' => $this->day(0), 'km' => 1100, 'cost' => '60.00', 'category' => MaintenanceCategory::UNSCHEDULED]);
        MaintenanceFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'performedAt' => $this->day(0), 'km' => 1200, 'cost' => '70.00', 'type' => MaintenanceType::INSPECTION, 'category' => MaintenanceCategory::INSPECTION]);

        $body = $this->charts($token, '?months=3');

        self::assertSame([
            ['category' => 'insurance', 'name' => null, 'amount' => '350.00'],
            ['category' => 'tax', 'name' => null, 'amount' => '200.00'],
            ['category' => 'scheduled', 'name' => null, 'amount' => '80.00'],
            ['category' => 'inspection', 'name' => null, 'amount' => '70.00'],
            ['category' => 'unscheduled', 'name' => null, 'amount' => '60.00'],
        ], $body['spendingByCategory']);
        self::assertSame(
            ['refuelings' => '0.00', 'scheduled' => '80.00', 'unscheduled' => '60.00', 'inspection' => '70.00', 'taxes' => '200.00', 'insurance' => '50.00', 'expenses' => '0.00', 'total' => '460.00'],
            $body['months'][2]['spending'],
        );
    }

    public function testSpendingByCategoryUsesValueNames(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $values = static::getContainer()->get(UserValues::class);
        $insurance = $this->starterCategory($vehicle, ExpenseCategory::INSURANCE);
        $values->rename($insurance, 'Polizza Kasko');
        $camper = $values->findOrCreate($user, $org, UserValueKind::EXPENSE_CATEGORY, 'Revisione camper');
        $this->expense($vehicle, $this->day(-1, 3), '40.00', $insurance);
        $this->expense($vehicle, $this->day(0), '25.00', $camper);
        // Ricorrente sulla stessa voce: addebiti a -1 e 0, nella stessa fetta della spesa singola
        $this->recurringExpense($vehicle, $this->day(-1), '10.00', RecurringPeriod::MONTHLY, $insurance);

        $body = $this->charts($token, '?months=3');

        self::assertSame([
            ['category' => 'expense:'.$insurance->getId(), 'name' => 'Polizza Kasko', 'amount' => '60.00'],
            ['category' => 'expense:'.$camper->getId(), 'name' => 'Revisione camper', 'amount' => '25.00'],
        ], $body['spendingByCategory']);
    }

    public function testKmDrivenUsesOdometerReadingsWithAPreWindowBaseAndNeverGoesNegative(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        // Base = lettura pre-finestra (5000) perché più alta dei km iniziali.
        $a = $this->ownedVehicle($user, $org, ['initialKm' => 1000]);
        $this->refueling($a, $this->day(-4), 5000, '10.000', '1.0000');
        $this->maintenance($a, $this->day(-2, 10), 5600, '10.00');
        $this->maintenance($a, $this->day(-1, 3), 4000, '10.00'); // refuso: più basso, il mese resta a 0
        $this->refueling($a, $this->day(0), 6500, '10.000', '1.0000');
        // Base = km iniziali (20000) perché più alti della lettura pre-finestra (dato sporco).
        $b = $this->ownedVehicle($user, $org, ['initialKm' => 20000]);
        $this->refueling($b, $this->day(-3), 15000, '10.000', '1.0000');
        $this->refueling($b, $this->day(-1), 20500, '10.000', '1.0000');

        $body = $this->charts($token, '?months=3');

        self::assertSame([600, 500, 900], array_column($body['months'], 'kmDriven'));
        self::assertSame(2000, $body['totals']['kmDriven']);
    }

    public function testKmDrivenOverTheWholeHistoryMatchesTheVehicleStats(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org, ['initialKm' => 30000]);
        $this->refueling($vehicle, $this->day(-10), 30400, '10.000', '1.0000');
        $this->maintenance($vehicle, $this->day(-7), 31250, '10.00');
        $this->refueling($vehicle, $this->day(-7, 20), 31100, '10.000', '1.0000');
        $this->refueling($vehicle, $this->day(-2), 33000, '10.000', '1.0000');

        $charts = $this->charts($token, '?months=24');
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', session: $token);
        $stats = $this->jsonBody();

        self::assertSame(3000, $stats['kmDriven']);
        self::assertSame($stats['kmDriven'], $charts['totals']['kmDriven']);
    }

    public function testConsumptionIsAttributedToTheMonthOfTheClosingFullTank(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org, ['initialKm' => 0]);
        // Pieno di partenza prima della finestra, parziale e pieno di chiusura dentro.
        $this->refueling($vehicle, $this->day(-3, 20), 10000, '40.000', '1.0000');
        $this->refueling($vehicle, $this->day(-2, 5), 10300, '10.000', '1.0000', full: false);
        $this->refueling($vehicle, $this->day(-1, 2), 10650, '30.000', '1.0000');

        $body = $this->charts($token, '?months=3');

        // (10650 - 10000) / (10 + 30) = 16.25 km/l, nel mese del pieno di chiusura.
        self::assertSame([null, 16.25, null], array_map(
            static fn (array $point): ?float => $point['consumption']['diesel'],
            $body['months'],
        ));
    }

    public function testConsumptionDiscardsMixedBiFuelIntervals(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org, ['fuelType' => FuelType::GASOLINE, 'secondaryFuelType' => FuelType::LPG]);
        $this->refueling($vehicle, $this->day(-1), 10000, '40.000', '1.0000', fuel: FuelType::GASOLINE);
        $this->refueling($vehicle, $this->day(-1, 5), 10200, '20.000', '1.0000', fuel: FuelType::LPG);
        $this->refueling($vehicle, $this->day(-1, 10), 10500, '30.000', '1.0000', fuel: FuelType::GASOLINE); // misto: scartato
        $this->refueling($vehicle, $this->day(0), 10900, '25.000', '1.0000', fuel: FuelType::LPG);            // misto: scartato

        $body = $this->charts($token, '?months=2');

        foreach ($body['months'] as $point) {
            self::assertSame(['gasoline' => null, 'lpg' => null], $point['consumption']);
        }
    }

    public function testConsumptionNeverPairsRefuelingsOfDifferentVehiclesAndAggregatesAcrossThem(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $a = $this->ownedVehicle($user, $org, ['initialKm' => 0]);
        $b = $this->ownedVehicle($user, $org, ['initialKm' => 0]);
        // Date alternate: a1, b1, a2, b2. Un pairing tra veicoli darebbe km assurdi.
        $this->refueling($a, $this->day(-1), 10000, '50.000', '1.0000');
        $this->refueling($b, $this->day(-1, 2), 50000, '50.000', '1.0000');
        $this->refueling($a, $this->day(-1, 3), 10500, '40.000', '1.0000'); // a: 500 km / 40 l
        $this->refueling($b, $this->day(-1, 4), 50300, '20.000', '1.0000'); // b: 300 km / 20 l

        $body = $this->charts($token, '?months=2');

        // (500 + 300) / (40 + 20) = 13.33 km/l, non la media delle medie (12.5 e 15).
        self::assertSame(13.33, $body['months'][0]['consumption']['diesel']);
        self::assertNull($body['months'][1]['consumption']['diesel']);
    }

    // ---------------- spese ricorrenti ----------------

    public function testMonthlyRecurringExpenseCountsEveryChargeAlreadyDue(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        // Primo addebito tre mesi fa, il 1: addebiti a -3, -2, -1 e 0 (oggi è comunque >= 1 del mese).
        $this->recurringExpense($vehicle, $this->day(-3), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION);

        $body = $this->charts($token, '?months=6');

        self::assertSame(['0.00', '0.00', '50.00', '50.00', '50.00', '50.00'], $this->expensesPerMonth($body));
        self::assertSame(['0.00', '0.00', '50.00', '50.00', '50.00', '50.00'], array_map(
            static fn (array $point): string => $point['spending']['total'],
            $body['months'],
        ));
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::SUBSCRIPTION, '200.00')], $body['spendingByCategory']);
        self::assertSame('200.00', $body['totals']['spending']);
    }

    public function testRecurringStartedBeforeTheWindowCountsOnlyItsInWindowCharges(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->recurringExpense($vehicle, $this->day(-10), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION);

        $body = $this->charts($token, '?months=3');

        self::assertSame(['50.00', '50.00', '50.00'], $this->expensesPerMonth($body));
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::SUBSCRIPTION, '150.00')], $body['spendingByCategory']);
        self::assertSame('150.00', $body['totals']['spending']);
    }

    public function testYearlyRecurringLandsInTheMonthOfEachAnniversary(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->recurringExpense($vehicle, $this->day(-12), '300.00', RecurringPeriod::YEARLY, ExpenseCategory::INSURANCE);

        $body = $this->charts($token, '?months=24');

        $perMonth = array_combine(array_column($body['months'], 'month'), $this->expensesPerMonth($body));
        self::assertSame('300.00', $perMonth[$this->monthKey(-12)]);
        self::assertSame('300.00', $perMonth[$this->monthKey(0)]);
        unset($perMonth[$this->monthKey(-12)], $perMonth[$this->monthKey(0)]);
        self::assertSame(['0.00'], array_values(array_unique($perMonth)), 'Negli altri mesi non cade nessun addebito');
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::INSURANCE, '600.00')], $body['spendingByCategory']);
    }

    public function testWeeklyRecurringCountsOneChargePerWeekInTheRightMonths(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $today = $this->today();
        $this->recurringExpense($vehicle, $today->modify('-3 weeks'), '10.00', RecurringPeriod::WEEKLY, ExpenseCategory::PARKING);

        $body = $this->charts($token, '?months=3');

        // Quattro addebiti: oggi e le tre settimane precedenti, ognuno nel suo mese.
        $expected = [];
        foreach (range(-2, 0) as $offset) {
            $expected[$this->monthKey($offset)] = 0;
        }
        foreach ([3, 2, 1, 0] as $weeksAgo) {
            $expected[$today->modify("-{$weeksAgo} weeks")->format('Y-m')] += 1000;
        }
        self::assertSame(
            array_map(static fn (int $cents): string => number_format($cents / 100, 2, '.', ''), array_values($expected)),
            $this->expensesPerMonth($body),
        );
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::PARKING, '40.00')], $body['spendingByCategory']);
    }

    public function testStartingTodayCountsTheFirstCharge(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->recurringExpense($vehicle, $this->today(), '25.00', RecurringPeriod::MONTHLY, ExpenseCategory::TOLL);

        $body = $this->charts($token, '?months=2');

        self::assertSame(['0.00', '25.00'], $this->expensesPerMonth($body));
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::TOLL, '25.00')], $body['spendingByCategory']);
    }

    public function testRecurringUntilInTheMiddleOfTheWindowStopsTheCharges(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        // Addebiti il 1 di -5, -4, -3, -2…; il 10 di -3 chiude: l'ultimo è quello del 1 di -3 (finestra: da -5 a 0).
        $this->recurringExpense($vehicle, $this->day(-5), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION, $this->day(-3, 10));

        $body = $this->charts($token, '?months=6');

        self::assertSame(['50.00', '50.00', '50.00', '0.00', '0.00', '0.00'], $this->expensesPerMonth($body));
        self::assertSame('150.00', $body['totals']['spending']);
    }

    public function testRecurringUntilOnAChargeDateIncludesThatCharge(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->recurringExpense($vehicle, $this->day(-5), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION, $this->day(-3));

        $body = $this->charts($token, '?months=6');

        self::assertSame(['50.00', '50.00', '50.00', '0.00', '0.00', '0.00'], $this->expensesPerMonth($body));
    }

    public function testRecurringEndedBeforeTheWindowContributesNothing(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->recurringExpense($vehicle, $this->day(-10), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION, $this->day(-4, 28));

        $body = $this->charts($token, '?months=3');

        self::assertSame(['0.00', '0.00', '0.00'], $this->expensesPerMonth($body));
        self::assertSame([], $body['spendingByCategory']);
        self::assertSame('0.00', $body['totals']['spending']);
    }

    public function testFutureChargesAreNotCounted(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        // Trimestrale dal 1 di -1: il prossimo addebito è a +2 mesi, quindi conta solo il primo.
        $this->recurringExpense($vehicle, $this->day(-1), '90.00', RecurringPeriod::QUARTERLY, ExpenseCategory::INSURANCE);
        // Mensile che parte il mese prossimo: nessun addebito ancora.
        $this->recurringExpense($vehicle, $this->day(1), '999.00', RecurringPeriod::MONTHLY, ExpenseCategory::INSURANCE);

        $body = $this->charts($token, '?months=3');

        self::assertSame(['0.00', '90.00', '0.00'], $this->expensesPerMonth($body));
        self::assertSame([$this->expenseSlice($vehicle, ExpenseCategory::INSURANCE, '90.00')], $body['spendingByCategory']);
        self::assertSame('90.00', $body['totals']['spending']);
    }

    public function testRecurringAndOneOffExpensesShareMonthsAndCategories(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $this->recurringExpense($vehicle, $this->day(-1), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::INSURANCE); // -1 e 0
        $this->expense($vehicle, $this->day(-1, 15), '30.25', ExpenseCategory::INSURANCE);
        $this->expense($vehicle, $this->day(0), '7.50', ExpenseCategory::TOLL);

        $body = $this->charts($token, '?months=2');

        self::assertSame(['80.25', '57.50'], $this->expensesPerMonth($body));
        self::assertSame([
            $this->expenseSlice($vehicle, ExpenseCategory::INSURANCE, '130.25'),
            $this->expenseSlice($vehicle, ExpenseCategory::TOLL, '7.50'),
        ], $body['spendingByCategory']);
    }

    public function testMonthlyTotalsTotalSpendingAndCategoriesAlwaysAgree(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org, ['initialKm' => 1000]);
        $other = $this->ownedVehicle($user, $org);
        $this->refueling($vehicle, $this->day(-1), 1000, '10.000', '1.3300');
        $this->maintenance($vehicle, $this->day(0), 1100, '120.10');
        $this->expense($vehicle, $this->day(-2, 3), '19.99', ExpenseCategory::PARKING);
        $this->recurringExpense($vehicle, $this->day(-9), '12.34', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION);
        $this->recurringExpense($vehicle, $this->day(-6, 20), '7.77', RecurringPeriod::WEEKLY, ExpenseCategory::PARKING, $this->day(-1, 5));
        $this->recurringExpense($other, $this->day(-4), '300.10', RecurringPeriod::QUARTERLY, ExpenseCategory::INSURANCE);
        $this->recurringExpense($other, $this->day(-30), '1.01', RecurringPeriod::BIENNIAL, ExpenseCategory::TAX);

        $body = $this->charts($token, '?months=6');

        $monthlyCents = array_sum(array_map(static fn (array $p): int => self::toCents($p['spending']['total']), $body['months']));
        $categoryCents = array_sum(array_map(static fn (array $c): int => self::toCents($c['amount']), $body['spendingByCategory']));
        self::assertGreaterThan(0, $monthlyCents);
        self::assertSame(self::toCents($body['totals']['spending']), $monthlyCents);
        self::assertSame($monthlyCents, $categoryCents);
        foreach ($body['months'] as $point) {
            $spending = $point['spending'];
            self::assertSame(
                self::toCents($spending['total']),
                array_sum(array_map(self::toCents(...), array_diff_key($spending, ['total' => true]))),
            );
        }
    }

    // ---------------- isolamento ----------------

    /**
     * Un veicolo di proprietà con dati da 10.00 e un veicolo escluso con dati da 1000.00, a benzina
     * (carburante diverso, per vedere anche le fughe in `fuelTypes`), tutti nella finestra.
     */
    #[DataProvider('excludedVehicles')]
    public function testOnlyOwnedVehiclesOfTheActiveOrganizationContribute(string $case): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser(); // owner dell'org: "vede" tutti i veicoli
        $own = $this->ownedVehicle($user, $org, ['initialKm' => 1000]);
        $this->refueling($own, $this->day(-1), 1000, '10.000', '1.0000');
        $this->refueling($own, $this->day(0), 1125, '10.000', '1.0000');

        $excluded = match ($case) {
            'viewer' => $this->sharedVehicle($user, $org, ShareRole::VIEWER),
            'editor' => $this->sharedVehicle($user, $org, ShareRole::EDITOR),
            'other_member' => $this->otherMembersVehicle($org),
            'other_org' => $this->ownedVehicleInAnotherOrg($user),
            'archived' => $this->ownedVehicle($user, $org, ['archivedAt' => new \DateTimeImmutable('-1 day'), 'fuelType' => FuelType::GASOLINE]),
            'admin_not_accepted' => $this->pendingAdminShareVehicle($user, $org),
            'no_share' => $this->foreignVehicle($org),
            default => throw new \LogicException($case),
        };
        $this->foreignData($excluded);

        $body = $this->charts($token, '?months=2');

        $this->assertOnlyOwnData($body);
    }

    /** @return iterable<string, array{string}> */
    public static function excludedVehicles(): iterable
    {
        yield 'condiviso in sola lettura (viewer)' => ['viewer'];
        yield 'condiviso con il vecchio ruolo editor' => ['editor'];
        yield 'di un altro membro, utente owner dell\'org' => ['other_member'];
        yield 'di un\'altra org, anche se proprietario lì' => ['other_org'];
        yield 'proprio ma archiviato' => ['archived'];
        yield 'share admin non accettato' => ['admin_not_accepted'];
        yield 'dell\'org senza share dell\'utente' => ['no_share'];
    }

    public function testPlainMemberSeesOnlyTheOwnedVehicleNotTheSharedOne(): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $member = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $member, 'role' => OrgRole::MEMBER]);
        $own = $this->ownedVehicle($member, $org, ['initialKm' => 1000]);
        $this->refueling($own, $this->day(-1), 1000, '10.000', '1.0000');
        $this->refueling($own, $this->day(0), 1125, '10.000', '1.0000');
        $this->foreignData($this->sharedVehicle($member, $org, ShareRole::VIEWER));

        $body = $this->charts($this->sessionFor($member, $org), '?months=2');

        $this->assertOnlyOwnData($body);
    }

    // ---------------- helper ----------------

    /**
     * Dati del veicolo di proprietà: due pieni (1000 → 1125 km, 10 l), 10.00 + 10.00 di rifornimenti.
     *
     * @param array<string, mixed> $body
     */
    private function assertOnlyOwnData(array $body): void
    {
        self::assertSame(['diesel'], $body['fuelTypes']);
        self::assertSame(['10.00', '10.00'], array_map(static fn (array $p): string => $p['spending']['total'], $body['months']));
        self::assertSame([0, 125], array_column($body['months'], 'kmDriven'));
        self::assertSame([['diesel' => null], ['diesel' => 12.5]], array_column($body['months'], 'consumption'));
        self::assertSame([$this->costSlice('fuel', '20.00')], $body['spendingByCategory']);
        self::assertSame(['spending' => '20.00', 'kmDriven' => 125], $body['totals']);
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

    /**
     * Spese di ogni mese della finestra (stringhe a 2 decimali), in ordine.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function expensesPerMonth(array $body): array
    {
        return array_values(array_map(static fn (array $point): string => $point['spending']['expenses'], $body['months']));
    }

    private static function toCents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * @return array<string, mixed>
     */
    private function charts(string $token, string $query = ''): array
    {
        $this->jsonRequest('GET', '/api/dashboard/charts'.$query, session: $token);
        self::assertResponseIsSuccessful();

        return $this->jsonBody();
    }

    private function sharedVehicle(User $user, Organization $org, ShareRole $role): Vehicle
    {
        $vehicle = $this->otherMembersVehicle($org);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $user, 'role' => $role]);

        return $vehicle;
    }

    private function otherMembersVehicle(Organization $org): Vehicle
    {
        $other = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $other, 'role' => OrgRole::MEMBER]);

        return $this->ownedVehicle($other, $org, ['fuelType' => FuelType::GASOLINE]);
    }

    private function ownedVehicleInAnotherOrg(User $user): Vehicle
    {
        $otherOrg = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['organization' => $otherOrg, 'user' => $user, 'role' => OrgRole::OWNER]);

        return $this->ownedVehicle($user, $otherOrg, ['fuelType' => FuelType::GASOLINE]);
    }

    private function pendingAdminShareVehicle(User $user, Organization $org): Vehicle
    {
        $vehicle = $this->foreignVehicle($org);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user, 'acceptedAt' => null]);

        return $vehicle;
    }

    private function foreignVehicle(Organization $org): Vehicle
    {
        return VehicleFactory::createOne(['organization' => $org, 'fuelType' => FuelType::GASOLINE]);
    }
}
