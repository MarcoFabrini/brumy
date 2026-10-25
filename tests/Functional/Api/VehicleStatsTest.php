<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\ExpenseCategory;
use App\Enum\FuelType;
use App\Enum\RecurringPeriod;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ChartFixtures;

/**
 * Test del calcolo statistico, con focus su:
 * - consumo per fuel (un veicolo bi-fuel deve avere 2 chiavi separate)
 * - solo rifornimenti full_tank usati nel calcolo
 * - edge cases: 0 / 1 rifornimento → null consumption
 * - costPerKm = somma costi / km percorsi
 */
final class VehicleStatsTest extends ApiTestCase
{
    use ChartFixtures;

    public function testStatsReturnsZerosWhenNoData(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::DIESEL,
            'initialKm' => 0,
        ]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertNull($body['consumption']['diesel']);
        self::assertSame('0.00', $body['totals']['cost']);
        self::assertSame(0, $body['currentKm']);
        self::assertSame(0, $body['kmDriven']);
        self::assertNull($body['costPerKm']);
    }

    public function testCurrentKmIsHighestOdometerAcrossTables(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::DIESEL,
            'initialKm' => 10000,
        ]);

        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-01'),
            'km' => 12000, 'liters' => '40.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);
        MaintenanceFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'km' => 15000, 'cost' => '50.00',
        ]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        self::assertResponseIsSuccessful();
        // Max tra rifornimento (12000) e manutenzione (15000).
        self::assertSame(15000, $this->jsonBody()['currentKm']);
    }

    public function testCurrentKmNeverBelowInitialKm(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::DIESEL,
            'initialKm' => 20000,
        ]);

        // Record con km più basso dell'iniziale (dato sporco): currentKm resta initialKm.
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-01'),
            'km' => 12000, 'liters' => '40.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(20000, $this->jsonBody()['currentKm']);
    }

    public function testConsumptionComputedFromFullTankPairs(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::DIESEL,
            'initialKm' => 50000,
        ]);

        // Pair 1: 50000 → 50500 km, 35 L benzina → 500/35 = 14.29 km/l
        // Pair 2: 50500 → 51000 km, 30 L            → 500/30 = 16.67 km/l
        // Media: (500+500) / (35+30) = 1000/65 = 15.38 km/l
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-01'),
            'km' => 50000, 'liters' => '40.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-15'),
            'km' => 50500, 'liters' => '35.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-02-01'),
            'km' => 51000, 'liters' => '30.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertEqualsWithDelta(15.38, $body['consumption']['diesel'], 0.05);
        self::assertSame(51000, $body['currentKm']); // max odometro inserito
        self::assertSame(1000, $body['kmDriven']);  // 51000 - 50000 (initialKm)
        // 3 refueling × ~circa 1.80/l × media volumi
        self::assertGreaterThan(0, (float) $body['totals']['cost']);
    }

    public function testBiFuelVehicleHasTwoConsumptionEntries(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::GASOLINE,
            'secondaryFuelType' => FuelType::LPG,
            'initialKm' => 30000,
        ]);

        // Rifornimenti benzina: 30000 → 30300 km, 25 L → 12 km/l
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-01'),
            'km' => 30000, 'liters' => '30.000', 'pricePerLiter' => '1.9000',
            'fuelType' => FuelType::GASOLINE, 'fullTank' => true,
        ]);
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-20'),
            'km' => 30300, 'liters' => '25.000', 'pricePerLiter' => '1.9000',
            'fuelType' => FuelType::GASOLINE, 'fullTank' => true,
        ]);

        // Rifornimenti GPL: 30300 → 30700 km, 40 L → 10 km/l
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-02-01'),
            'km' => 30300, 'liters' => '45.000', 'pricePerLiter' => '0.8500',
            'fuelType' => FuelType::LPG, 'fullTank' => true,
        ]);
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-02-15'),
            'km' => 30700, 'liters' => '40.000', 'pricePerLiter' => '0.8500',
            'fuelType' => FuelType::LPG, 'fullTank' => true,
        ]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();

        // Devono esserci ESATTAMENTE 2 chiavi consumption, una per fuel
        self::assertArrayHasKey('gasoline', $body['consumption']);
        self::assertArrayHasKey('lpg', $body['consumption']);
        self::assertEqualsWithDelta(12.0, $body['consumption']['gasoline'], 0.1);
        self::assertEqualsWithDelta(10.0, $body['consumption']['lpg'], 0.1);
    }

    public function testConsumptionNullWithOnlyOneFullTank(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::DIESEL,
            'initialKm' => 0,
        ]);

        // Solo 1 full tank → consumption deve essere null (servono almeno 2 pieni)
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-01'),
            'km' => 1000, 'liters' => '40.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-10'),
            'km' => 1500, 'liters' => '20.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => false,  // PARZIALE
        ]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        $body = $this->jsonBody();
        self::assertNull($body['consumption']['diesel'], 'Con un solo full tank, consumption deve essere null');
    }

    public function testBiFuelIntervalsWithTheOtherFuelInBetweenAreDiscarded(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::GASOLINE,
            'secondaryFuelType' => FuelType::LPG,
            'initialKm' => 10000,
        ]);
        $fill = static fn (string $date, int $km, string $liters, FuelType $fuel) => RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable($date),
            'km' => $km, 'liters' => $liters, 'pricePerLiter' => '1.5000',
            'fuelType' => $fuel, 'fullTank' => true,
        ]);

        $fill('2026-01-01', 10000, '30.000', FuelType::GASOLINE);
        $fill('2026-01-10', 10100, '20.000', FuelType::LPG);       // GPL in mezzo: intervallo benzina "sporco"
        $fill('2026-01-20', 10500, '30.000', FuelType::GASOLINE);  // 500 km NON tutti a benzina
        $fill('2026-02-01', 10900, '40.000', FuelType::GASOLINE);  // intervallo pulito: 400 km / 40 L = 10 km/l

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        $body = $this->jsonBody();
        // Senza lo scarto: (500+400)/(30+40) = 12,86. Con lo scarto conta solo l'ultimo intervallo.
        self::assertEqualsWithDelta(10.0, $body['consumption']['gasoline'], 0.05);
        self::assertNull($body['consumption']['lpg'], 'un solo pieno GPL: nessun intervallo');
    }

    public function testConsumptionAccountsForPartialRefuelingsBetweenFullTanks(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::DIESEL,
            'initialKm' => 1000,
        ]);

        // Pieno: 1000 km, 40 L (baseline, non conta nel consumo)
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-01'),
            'km' => 1000, 'liters' => '40.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);
        // Parziale in mezzo: 15 L, deve contribuire al consumo del pair successivo
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-10'),
            'km' => 1300, 'liters' => '15.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => false,
        ]);
        // Pieno: 1600 km, 25 L → chiude il pair
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-20'),
            'km' => 1600, 'liters' => '25.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        $body = $this->jsonBody();
        // (1600-1000) km / (15+25) L = 600/40 = 15 km/l.
        // Se il parziale venisse ignorato (bug), risulterebbe 600/25 = 24 km/l.
        self::assertEqualsWithDelta(15.0, $body['consumption']['diesel'], 0.05);
    }

    public function testTotalsAggregateAllSources(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne([
            'organization' => $org,
            'fuelType' => FuelType::DIESEL,
            'initialKm' => 100000,
        ]);

        // 1 refueling: 40 × 1.80 = 72.00
        RefuelingFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable('2026-01-01'),
            'km' => 100200, 'liters' => '40.000', 'pricePerLiter' => '1.8000',
            'fuelType' => FuelType::DIESEL, 'fullTank' => true,
        ]);
        // 1 maintenance: 120.50
        MaintenanceFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'cost' => '120.50',
        ]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        $body = $this->jsonBody();
        self::assertSame(1, $body['totals']['refuelings']);
        self::assertSame(1, $body['totals']['maintenances']);
        self::assertEqualsWithDelta(192.50, (float) $body['totals']['cost'], 0.01);
    }

    public function testStatsCountRecordsPerSection(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        foreach ([['oil_change', 'scheduled'], ['oil_change', 'scheduled'], ['brakes', 'unscheduled'], ['inspection', 'inspection']] as [$type, $category]) {
            $this->jsonRequest('POST', '/api/maintenances', [
                'vehicleId' => $vehicle->getId(), 'performedAt' => '2026-05-01', 'km' => 1000, 'type' => $type, 'category' => $category,
                'description' => 'Lavoro', 'components' => $category === 'unscheduled' ? ['Pastiglie', 'Dischi'] : [],
            ], accessToken: $token);
        }
        foreach ([['tax', 'Bollo', null], ['insurance', '', 'Allianz'], ['other', 'Pedaggio', null], ['other', 'Parcheggio', null]] as [$section, $category, $insurer]) {
            $this->jsonRequest('POST', '/api/expenses', [
                'vehicleId' => $vehicle->getId(), 'occurredAt' => '2026-05-01', 'section' => $section, 'category' => $category,
                'insurer' => $insurer, 'description' => 'Spesa', 'amount' => '10.00',
            ], accessToken: $token);
        }
        $this->jsonRequest('GET', '/api/components?vehicleId='.$vehicle->getId(), accessToken: $token);
        $this->jsonRequest('DELETE', '/api/components/'.array_column($this->jsonBody(), 'id')[0], accessToken: $token);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/stats', accessToken: $token);

        self::assertSame(
            ['scheduled' => 2, 'unscheduled' => 1, 'inspection' => 1, 'tax' => 1, 'insurance' => 1, 'expense' => 2, 'component' => 1],
            $this->jsonBody()['totals']['sections'],
        );
    }

    public function testStatsCrossTenantAccessDenied(): void
    {
        [, $orgA, $tokenA] = $this->createAuthenticatedUser();
        [, $orgB] = $this->createAuthenticatedUser();
        $vehicleB = VehicleFactory::createOne(['organization' => $orgB]);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicleB->getId().'/stats', accessToken: $tokenA);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMonthlyRecurringExpenseCountsEveryChargeAlreadyDue(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->refueling($vehicle, $this->day(-1), 1100, '10.000', '1.0000'); // 10.00, 100 km percorsi
        // Quattro addebiti da 50.00 (-3, -2, -1, 0) + una spesa singola da 5.00.
        $this->recurringExpense($vehicle, $this->day(-3), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION);
        $this->expense($vehicle, $this->day(-1, 5), '5.00');

        $body = $this->stats($token, $vehicle->getId());

        self::assertSame('215.00', $body['totals']['cost']);
        self::assertSame(100, $body['kmDriven']);
        self::assertSame(2.15, $body['costPerKm']);
        // `expenses` conta i record, non gli addebiti.
        self::assertSame(2, $body['totals']['expenses']);
    }

    public function testYearlyAndWeeklyRecurringExpenses(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->recurringExpense($vehicle, $this->day(-12), '300.00', RecurringPeriod::YEARLY, ExpenseCategory::INSURANCE); // 2 addebiti
        $this->recurringExpense($vehicle, $this->today()->modify('-2 weeks'), '10.00', RecurringPeriod::WEEKLY, ExpenseCategory::PARKING); // 3 addebiti

        $body = $this->stats($token, $vehicle->getId());

        self::assertSame('630.00', $body['totals']['cost']);
        self::assertSame(2, $body['totals']['expenses']);
    }

    public function testRecurringUntilIsInclusiveAndStopsTheCharges(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->recurringExpense($vehicle, $this->day(-5), '50.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION, $this->day(-3, 10)); // -5, -4, -3
        $this->recurringExpense($vehicle, $this->day(-5), '7.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION, $this->day(-3));    // fine sul terzo addebito: ancora incluso
        $this->recurringExpense($vehicle, $this->day(-5), '1000.00', RecurringPeriod::MONTHLY, ExpenseCategory::SUBSCRIPTION, $this->day(-5)->modify('-1 day')); // fine prima dell'inizio: nessun addebito

        $body = $this->stats($token, $vehicle->getId());

        self::assertSame('171.00', $body['totals']['cost']);
    }

    public function testFutureChargesAndFutureStartAreNotCounted(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->recurringExpense($vehicle, $this->day(-1), '90.00', RecurringPeriod::QUARTERLY, ExpenseCategory::INSURANCE); // solo il primo
        $this->recurringExpense($vehicle, $this->day(1), '500.00', RecurringPeriod::MONTHLY, ExpenseCategory::INSURANCE);   // parte il mese prossimo
        $this->recurringExpense($vehicle, $this->today(), '3.00', RecurringPeriod::YEARLY, ExpenseCategory::INSURANCE);     // il primo addebito è oggi

        $body = $this->stats($token, $vehicle->getId());

        self::assertSame('93.00', $body['totals']['cost']);
    }

    public function testOtherVehiclesRecurringExpensesAreNotCounted(): void
    {
        [$owner, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($owner, $org, ['initialKm' => 1000]);
        $this->recurringExpense($vehicle, $this->day(-1), '10.00', RecurringPeriod::MONTHLY, ExpenseCategory::INSURANCE);
        $this->recurringExpense($this->ownedVehicle($owner, $org), $this->day(-1), '1000.00', RecurringPeriod::WEEKLY, ExpenseCategory::INSURANCE);

        $body = $this->stats($token, $vehicle->getId());

        self::assertSame('20.00', $body['totals']['cost']);
    }

    /** @return array<string, mixed> */
    private function stats(string $token, ?int $vehicleId): array
    {
        $this->jsonRequest('GET', '/api/vehicles/'.$vehicleId.'/stats', accessToken: $token);
        self::assertResponseIsSuccessful();

        return $this->jsonBody();
    }
}
