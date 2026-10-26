<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Enum\UserValueKind;
use App\Repository\FuelStationRepository;
use App\Service\FuelPrices\FuelPriceCsv;
use App\Service\FuelPrices\FuelPriceImporter;
use App\Service\FuelPrices\FuelPriceImportException;
use App\Service\UserValues;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\ResetDatabase;

/** Import dall'open data MIMIT con un download finto: dati nuovi al posto dei vecchi, o i vecchi intatti se va male. */
final class FuelPriceImporterTest extends KernelTestCase
{
    use ResetDatabase;

    /** Anagrafica con abbastanza distributori da superare il controllo dei file troncati. */
    private static function stationsCsv(int $count = FuelPriceImporter::MIN_STATIONS): string
    {
        $rows = '';
        for ($id = 1; $id <= $count; ++$id) {
            $rows .= "$id|GESTORE|Q8|Stradale|Q8 $id|VIA ROMA $id|MODENA|MO|44.6|10.9\n";
        }

        return "Estrazione del 2026-10-25\n".FuelPriceCsv::STATIONS_HEADER."\n".$rows;
    }

    private static function pricesCsv(): string
    {
        return "Estrazione del 2026-10-25\n".FuelPriceCsv::PRICES_HEADER."\n1|Gasolio|1.729|1|24/10/2026 07:30:00\n2|Benzina|1.849|1|24/10/2026 07:30:00\n";
    }

    /** @param list<MockResponse> $responses */
    private function importer(array $responses): FuelPriceImporter
    {
        $container = static::getContainer();

        return new FuelPriceImporter(
            new MockHttpClient($responses),
            $container->get(FuelStationRepository::class),
            $container->get(Connection::class),
            new NullLogger(),
        );
    }

    private function rowsIn(string $table): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM $table");
    }

    public function testReplacesStationsAndPrices(): void
    {
        self::bootKernel();

        $result = $this->importer([new MockResponse(self::stationsCsv()), new MockResponse(self::pricesCsv())])->import();

        self::assertNotNull($result);
        self::assertSame(FuelPriceImporter::MIN_STATIONS, $result['stations']);
        self::assertSame(2, $result['prices']);
        self::assertSame('2026-10-25', $result['extractedOn']->format('Y-m-d'));
        self::assertSame(FuelPriceImporter::MIN_STATIONS, $this->rowsIn('fuel_stations'));

        // Il giorno dopo un distributore chiude: sparisce, e i prezzi si riscrivono
        $this->importer([new MockResponse(self::stationsCsv(FuelPriceImporter::MIN_STATIONS + 5)), new MockResponse(self::pricesCsv())])->import();
        self::assertSame(FuelPriceImporter::MIN_STATIONS + 5, $this->rowsIn('fuel_stations'));
        self::assertSame(2, $this->rowsIn('fuel_prices'));
    }

    public function testKeepsTheDailyPriceOfFollowedStationsOnly(): void
    {
        self::bootKernel();
        $this->importer([new MockResponse(self::stationsCsv()), new MockResponse(self::pricesCsv())])->import();
        $values = static::getContainer()->get(UserValues::class);
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $followed = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Q8 1');
        $followed->linkFuelStation(1);
        $followed->followPrice(true);
        $deleted = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Q8 2');
        $deleted->linkFuelStation(2);
        $deleted->followPrice(true);
        $deleted->softDelete();
        static::getContainer()->get('doctrine')->getManager()->flush();

        // Lo stesso giorno due volte: una riga sola, col prezzo dell'ultimo import
        $this->importer([new MockResponse(self::stationsCsv()), new MockResponse(self::pricesCsv())])->import();
        $this->importer([new MockResponse(self::stationsCsv()), new MockResponse(str_replace('1.729', '1.719', self::pricesCsv()))])->import();

        self::assertEquals(
            [['station_id' => 1, 'day' => '2026-10-25', 'price' => '1.719']],
            static::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT station_id, day, price FROM fuel_price_history'),
        );
    }

    public function testATruncatedOrBrokenDownloadKeepsThePreviousData(): void
    {
        self::bootKernel();
        $this->importer([new MockResponse(self::stationsCsv()), new MockResponse(self::pricesCsv())])->import();

        foreach ([
            [new MockResponse(self::stationsCsv(10)), new MockResponse(self::pricesCsv())],
            [new MockResponse('<html>Manutenzione</html>'), new MockResponse(self::pricesCsv())],
            [new MockResponse('', ['http_code' => 503])],
        ] as $responses) {
            try {
                $this->importer($responses)->import();
                self::fail('L\'import doveva fallire');
            } catch (FuelPriceImportException) {
            }
            self::assertSame(FuelPriceImporter::MIN_STATIONS, $this->rowsIn('fuel_stations'));
            self::assertSame(2, $this->rowsIn('fuel_prices'));
        }
    }
}
