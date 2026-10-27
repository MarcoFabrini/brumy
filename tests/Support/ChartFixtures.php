<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\ExpenseCategory;
use App\Enum\FuelType;
use App\Enum\RecurringPeriod;
use App\Service\AppClock;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;

/**
 * Helper comuni ai test dei grafici (dashboard e singolo veicolo): date relative al mese corrente
 * di AppClock, veicoli di proprietà e record di rifornimenti/manutenzioni/spese.
 */
trait ChartFixtures
{
    /** Primo giorno (o `$day`-esimo) del mese a `$offset` mesi dal corrente, nel fuso dell'istanza. */
    private function day(int $offset, int $day = 1): \DateTimeImmutable
    {
        return static::getContainer()->get(AppClock::class)->today()
            ->modify('first day of this month')
            ->modify(sprintf('%+d months', $offset))
            ->modify(sprintf('+%d days', $day - 1));
    }

    /** Il giorno di calendario corrente nel fuso dell'istanza. */
    private function today(): \DateTimeImmutable
    {
        return static::getContainer()->get(AppClock::class)->today();
    }

    private function monthKey(int $offset): string
    {
        return $this->day($offset)->format('Y-m');
    }

    /** @param array<string, mixed> $attrs */
    private function ownedVehicle(User $owner, Organization $org, array $attrs = []): Vehicle
    {
        $vehicle = VehicleFactory::createOne($attrs + ['organization' => $org, 'fuelType' => FuelType::DIESEL]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $owner]);

        return $vehicle;
    }


    private function refueling(Vehicle $vehicle, \DateTimeImmutable $on, int $km, string $liters, string $pricePerLiter, bool $full = true, FuelType $fuel = FuelType::DIESEL): void
    {
        RefuelingFactory::createOne([
            'organization' => $vehicle->getOrganization(), 'vehicle' => $vehicle,
            'refueledAt' => $on, 'km' => $km, 'liters' => $liters, 'pricePerLiter' => $pricePerLiter,
            'fuelType' => $fuel, 'fullTank' => $full,
        ]);
    }

    private function maintenance(Vehicle $vehicle, \DateTimeImmutable $on, int $km, ?string $cost): void
    {
        MaintenanceFactory::createOne([
            'organization' => $vehicle->getOrganization(), 'vehicle' => $vehicle,
            'performedAt' => $on, 'km' => $km, 'cost' => $cost,
        ]);
    }

    /** `$category` come ExpenseCategory diventa la voce di partenza nella lista del proprietario del veicolo. */
    private function expense(Vehicle $vehicle, \DateTimeImmutable $on, string $amount, ExpenseCategory|UserValue $category = ExpenseCategory::OTHER): void
    {
        ExpenseFactory::createOne([
            'organization' => $vehicle->getOrganization(), 'vehicle' => $vehicle,
            'occurredAt' => $on, 'amount' => $amount, 'category' => $category,
        ]);
    }

    /** Spesa ricorrente: `$on` è il primo addebito, `$until` l'ultimo possibile (inclusivo, null = in corso). */
    private function recurringExpense(Vehicle $vehicle, \DateTimeImmutable $on, string $amount, RecurringPeriod $period, ExpenseCategory|UserValue $category = ExpenseCategory::OTHER, ?\DateTimeImmutable $until = null): void
    {
        ExpenseFactory::createOne([
            'organization' => $vehicle->getOrganization(), 'vehicle' => $vehicle,
            'occurredAt' => $on, 'amount' => $amount, 'category' => $category,
            'recurring' => true, 'recurringPeriod' => $period, 'recurringUntil' => $until,
        ]);
    }

    /** Voce di partenza `$category` nella lista del proprietario del veicolo (quella usata da expense()). */
    private function starterCategory(Vehicle $vehicle, ExpenseCategory $category): UserValue
    {
        return ExpenseFactory::new()->starterCategory($vehicle, $category);
    }

    /**
     * Fetta attesa di `spendingByCategory` per le spese di una categoria.
     *
     * @return array{category: string, name: string|null, amount: string}
     */
    private function expenseSlice(Vehicle $vehicle, ExpenseCategory|UserValue $category, string $amount): array
    {
        $value = $category instanceof UserValue ? $category : $this->starterCategory($vehicle, $category);

        return ['category' => 'expense:'.$value->getId(), 'name' => $value->getName(), 'amount' => $amount];
    }

    /**
     * Fetta attesa di `spendingByCategory` per rifornimenti (`fuel`) o manutenzioni (`maintenance`).
     *
     * @return array{category: string, name: null, amount: string}
     */
    private function costSlice(string $category, string $amount): array
    {
        return ['category' => $category, 'name' => null, 'amount' => $amount];
    }
}
