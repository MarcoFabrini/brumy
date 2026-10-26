<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FuelPrices;

use App\Enum\FuelType;
use App\Service\FuelPrices\FuelPriceCsv;
use PHPUnit\Framework\TestCase;

final class FuelPriceCsvTest extends TestCase
{
    private const STATIONS = "Estrazione del 2026-10-25\n"
        .FuelPriceCsv::STATIONS_HEADER."\n"
        ."59183|ENIMOOV S.P.A.|Agip Eni|Stradale|19829 AGRIGENTO|SS.189 KM. 64+649  - C.DA SAN MICHELE |AGRIGENTO|AG|37.333935|13.595533\n"
        ."40820|STOIL SIMPLE|Pompe Bianche|Stradale|STOIL SIMPLE | gestori.prezzibenzina.it|STR. PROV.LE 82 SPINETTA|ALESSANDRIA|AL|44.91704718250436|8.70067298412323\n"
        ."123|ROSSI SRL|Q8|Stradale|Q8 ROSSI|VIA ROMA 1|MODENA|MO|0|0\n"
        ."riga rotta|senza|campi\n"
        ."\n";

    public function testReadsStationsAndTheirPosition(): void
    {
        $parsed = FuelPriceCsv::parseStations(self::STATIONS);

        self::assertSame('2026-10-25', $parsed['extractedOn']->format('Y-m-d'));
        self::assertCount(3, $parsed['stations']);
        [$eni, $stoil, $q8] = $parsed['stations'];
        self::assertSame(59183, $eni['id']);
        self::assertSame('Agip Eni', $eni['brand']);
        self::assertSame('SS.189 KM. 64+649 - C.DA SAN MICHELE', $eni['address'], 'spazi normalizzati');
        self::assertSame(37.333935, $eni['latitude']);
        // Un "|" dentro il nome non sposta indirizzo e coordinate
        self::assertSame('STOIL SIMPLE | gestori.prezzibenzina.it', $stoil['name']);
        self::assertSame('ALESSANDRIA', $stoil['city']);
        self::assertSame(8.70067298412323, $stoil['longitude']);
        // Coordinate 0,0: il distributore resta, senza posizione
        self::assertNull($q8['latitude']);
        self::assertNull($q8['longitude']);
    }

    public function testRejectsAFileWithAnotherShape(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        FuelPriceCsv::parseStations("<html>Pagina non trovata</html>\n");
    }

    public function testRejectsAChangedHeader(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        FuelPriceCsv::parsePrices("Estrazione del 2026-10-25\nidImpianto|carburante|prezzo\n1|Benzina|1.8\n");
    }

    public function testKeepsOnlyValidRecentBasePrices(): void
    {
        $csv = "Estrazione del 2026-10-25\r\n"
            .FuelPriceCsv::PRICES_HEADER."\r\n"
            ."10|Benzina|1.849|1|24/10/2026 07:30:00\r\n"
            ."10|Benzina|1.959|0|24/10/2026 07:30:00\r\n"
            ."10|Gasolio|1.749|1|20/10/2026 07:30:00\r\n"
            ."10|Gasolio|1.729|1|23/10/2026 07:30:00\r\n"  // più recente: vince
            ."10|Blue Diesel|1.899|1|24/10/2026 07:30:00\r\n" // variante di marca: ignorata
            ."11|GPL|0.100|1|24/10/2026 07:30:00\r\n"       // prezzo assurdo
            ."11|Metano|1.4|1|01/01/2025 07:30:00\r\n"      // vecchio di quasi due anni
            ."11|Metano|1.39|0|24/10/2026 07:30:00\r\n";

        $prices = FuelPriceCsv::parsePrices($csv);

        $byKey = [];
        foreach ($prices as $p) {
            $byKey[$p['stationId'].'-'.$p['fuel']->value.'-'.($p['self'] ? 'self' : 'served')] = $p['price'];
        }
        self::assertSame([
            '10-gasoline-self' => '1.849',
            '10-gasoline-served' => '1.959',
            '10-diesel-self' => '1.729',
            '11-cng-served' => '1.390',
        ], $byKey);
        self::assertSame(FuelType::CNG, $prices[3]['fuel']);
        self::assertSame('2026-10-24 07:30:00', $prices[3]['communicatedAt']->format('Y-m-d H:i:s'));
    }
}
