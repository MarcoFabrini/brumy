<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FuelPrices;

use App\Enum\FuelType;
use App\Service\FuelPrices\FuelPrices;
use PHPUnit\Framework\TestCase;

final class FuelPricesTest extends TestCase
{
    public function testLabelUsesBrandOrNameAndReadableAddress(): void
    {
        $station = ['id' => 1, 'brand' => 'Q8', 'name' => 'Q8 ROSSI', 'address' => 'VIA  EMILIA EST 1420', 'city' => 'MODENA', 'province' => 'MO', 'latitude' => null, 'longitude' => null];
        self::assertSame('Q8 · Via Emilia Est 1420, Modena', FuelPrices::label($station));

        $unbranded = ['brand' => 'Pompe Bianche', 'name' => 'STOIL SIMPLE'] + $station;
        self::assertSame('Stoil Simple · Via Emilia Est 1420, Modena', FuelPrices::label($unbranded));
    }

    public function testPriceFuelMapsToTheBaseFuel(): void
    {
        self::assertSame(FuelType::GASOLINE, FuelPrices::priceFuel(FuelType::HYBRID));
        self::assertSame(FuelType::LPG, FuelPrices::priceFuel(FuelType::LPG));
        self::assertNull(FuelPrices::priceFuel(FuelType::ELECTRIC));
        self::assertNull(FuelPrices::priceFuel(FuelType::HYDROGEN));
    }

    public function testDistance(): void
    {
        // Modena - Bologna, circa 37 km in linea d'aria
        self::assertEqualsWithDelta(37.0, FuelPrices::distanceKm(44.6471, 10.9252, 44.4949, 11.3426), 1.5);
        self::assertSame(0.0, FuelPrices::distanceKm(44.6, 10.9, 44.6, 10.9));
    }
}
