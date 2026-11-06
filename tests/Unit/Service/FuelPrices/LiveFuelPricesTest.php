<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FuelPrices;

use App\Enum\FuelType;
use App\Service\FuelPrices\LiveFuelPrices;
use PHPUnit\Framework\TestCase;

/** La risposta dell'interfaccia Osservaprezzi letta con le regole del file giornaliero. */
final class LiveFuelPricesTest extends TestCase
{
    /**
     * @param list<mixed> $fuels
     *
     * @return array{id: int, name: string, fuels: list<mixed>}
     */
    private static function response(array $fuels, int $id = 3464): array
    {
        return ['id' => $id, 'name' => 'PO EST', 'fuels' => $fuels];
    }

    /** @return array<string, mixed> */
    private static function fuel(string $name, float|string $price, bool $self, string $insertDate = '2026-11-05T06:12:08Z'): array
    {
        return ['id' => 1, 'price' => $price, 'name' => $name, 'fuelId' => 1, 'isSelf' => $self, 'serviceAreaId' => 3464, 'insertDate' => $insertDate, 'validityDate' => $insertDate];
    }

    public function testBaseFuelsInLocalTime(): void
    {
        $rows = LiveFuelPrices::parse(self::response([
            self::fuel('Gasolio', 1.759, true),
            self::fuel('Benzina', '1.849', false, '2026-10-20T18:30:00.123Z'),
            self::fuel('Metano', '1.449', false, '2026-09-01T18:30:00Z'), // più di 30 giorni fa: fuori
            self::fuel('Blue Diesel', 1.959, true), // variante di marca: fuori
            self::fuel('GPL', 0.1, false),          // errore di battitura: fuori
        ]), 3464, new \DateTimeImmutable('2026-11-05 10:00'));

        self::assertCount(2, $rows);
        self::assertSame(3464, $rows[0]['stationId']);
        self::assertSame(FuelType::DIESEL, $rows[0]['fuel']);
        self::assertTrue($rows[0]['self']);
        self::assertSame('1.759', $rows[0]['price']);
        // 06:12 UTC d'inverno = 07:12 in Italia; 18:30 UTC con l'ora legale (fino al 25 ottobre) = 20:30
        self::assertSame('2026-11-05 07:12:08', $rows[0]['communicatedAt']->format('Y-m-d H:i:s'));
        self::assertSame(FuelType::GASOLINE, $rows[1]['fuel']);
        self::assertFalse($rows[1]['self']);
        self::assertSame('2026-10-20 20:30:00', $rows[1]['communicatedAt']->format('Y-m-d H:i:s'));
    }

    public function testKeepsTheLatestCommunicationForEachFuelAndService(): void
    {
        $rows = LiveFuelPrices::parse(self::response([
            self::fuel('Gasolio', 1.799, true, '2026-11-05T06:00:00Z'),
            self::fuel('Gasolio', 1.759, true, '2026-11-05T09:00:00Z'),
            self::fuel('Gasolio', 1.779, true, '2026-11-05T07:00:00Z'),
        ]), 3464, new \DateTimeImmutable('2026-11-05 10:00'));

        self::assertCount(1, $rows);
        self::assertSame('1.759', $rows[0]['price']);
    }

    public function testMalformedEntriesAreSkipped(): void
    {
        $rows = LiveFuelPrices::parse(self::response([
            ['name' => 'Gasolio', 'price' => 'n/d', 'isSelf' => true, 'insertDate' => '2026-11-05T06:00:00Z'],
            ['name' => 'Gasolio', 'price' => 1.7, 'isSelf' => true, 'insertDate' => 'ieri'],
            ['name' => 'Gasolio', 'price' => 1.7, 'isSelf' => 1, 'insertDate' => '2026-11-05T06:00:00Z'],
            'rumore',
        ]), 3464, new \DateTimeImmutable('2026-11-05 10:00'));

        self::assertSame([], $rows);
    }

    public function testAnUnexpectedShapeIsAnError(): void
    {
        foreach (['<html>', ['id' => 99, 'fuels' => []], ['id' => 3464], ['id' => '3464', 'fuels' => []]] as $data) {
            try {
                LiveFuelPrices::parse($data, 3464);
                self::fail('Doveva essere un errore');
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
