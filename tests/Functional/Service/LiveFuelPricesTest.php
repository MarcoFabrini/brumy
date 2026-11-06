<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Command\RefreshFollowedFuelPricesCommand;
use App\Enum\FuelType;
use App\Enum\UserValueKind;
use App\Repository\FuelStationRepository;
use App\Service\AppClock;
use App\Service\FuelPrices\FuelPriceCsv;
use App\Service\FuelPrices\FuelPriceImporter;
use App\Service\FuelPrices\LiveFuelPrices;
use App\Service\UserValues;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\ResetDatabase;

/** Prezzi del giorno stesso con una risposta finta del ministero: vince sempre la comunicazione più recente. */
final class LiveFuelPricesTest extends KernelTestCase
{
    use ResetDatabase;

    /** @var list<string> */
    private array $requested = [];
    /** Prezzi del file, in ora italiana: relativi a oggi, perché il ministero scarta quelli di più di 30 giorni fa. */
    private string $dailyAt1;
    private string $dailyAt2;
    /** Quando il ministero dice che è arrivato il prezzo in tempo reale, in ora italiana. */
    private \DateTimeImmutable $anHourAgo;

    protected function setUp(): void
    {
        self::bootKernel();
        $rome = new \DateTimeZone('Europe/Rome');
        $this->dailyAt1 = (new \DateTimeImmutable('-2 days 07:30', $rome))->format('Y-m-d H:i:s');
        $this->dailyAt2 = (new \DateTimeImmutable('yesterday 20:00', $rome))->format('Y-m-d H:i:s');
        $this->anHourAgo = new \DateTimeImmutable((new \DateTimeImmutable('-1 hour', $rome))->format('Y-m-d H:i:s'), $rome);
        $price = static fn (int $id, FuelType $fuel, bool $self, string $value, string $at): array => [
            'stationId' => $id, 'fuel' => $fuel, 'self' => $self, 'price' => $value, 'communicatedAt' => new \DateTimeImmutable($at),
        ];
        $this->repo()->replaceAll(
            [self::station(1), self::station(2)],
            [
                $price(1, FuelType::DIESEL, true, '1.799', $this->dailyAt1),
                $price(2, FuelType::DIESEL, true, '1.749', $this->dailyAt2),
            ],
            new \DateTimeImmutable('2026-11-04'),
            new \DateTimeImmutable(),
        );
    }

    /** @return array{id: int, operator: string, brand: string, name: string, address: string, city: string, province: string, latitude: float, longitude: float} */
    private static function station(int $id): array
    {
        return ['id' => $id, 'operator' => 'GESTORE', 'brand' => 'Q8', 'name' => "Q8 $id", 'address' => "VIA ROMA $id", 'city' => 'MODENA', 'province' => 'MO', 'latitude' => 44.6, 'longitude' => 10.9];
    }

    private function repo(): FuelStationRepository
    {
        return static::getContainer()->get(FuelStationRepository::class);
    }

    /** Il ministero risponde col gasolio self a $price, comunicato a $insertDate (UTC; di default un'ora fa), per ogni distributore chiesto. */
    private function live(string $price = '1.709', ?string $insertDate = null, bool $fail = false, ?ArrayAdapter $cache = null): LiveFuelPrices
    {
        $insertDate ??= $this->anHourAgo->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $client = new MockHttpClient(function (string $method, string $url) use ($price, $insertDate, $fail): MockResponse {
            $this->requested[] = $url;
            if ($fail) {
                return new MockResponse('<html>Manutenzione</html>', ['http_code' => 503]);
            }
            $id = (int) basename($url);

            return new MockResponse((string) json_encode(['id' => $id, 'fuels' => [
                ['price' => (float) $price, 'name' => 'Gasolio', 'isSelf' => true, 'insertDate' => $insertDate],
            ]]));
        });

        return new LiveFuelPrices($client, $this->repo(), $cache ?? new ArrayAdapter(), new NullLogger(), true, true);
    }

    /** @return array<int, array{price: string, communicated_at: string}> */
    private function dieselSelf(): array
    {
        /** @var list<array{station_id: int, price: string, communicated_at: string}> $rows */
        $rows = static::getContainer()->get(Connection::class)->fetchAllAssociative(
            "SELECT station_id, price, communicated_at FROM fuel_prices WHERE fuel = 'diesel' AND is_self = 1 ORDER BY station_id",
        );

        return array_column(array_map(static fn (array $r): array => ['id' => (int) $r['station_id'], 'price' => $r['price'], 'communicated_at' => $r['communicated_at']], $rows), null, 'id');
    }

    public function testANewerPriceReplacesTheDailyOne(): void
    {
        self::assertSame(2, $this->live()->refresh([1, 2, 1, 999]));

        self::assertCount(2, $this->requested, 'Una richiesta per distributore esistente');
        $prices = $this->dieselSelf();
        self::assertSame('1.709', $prices[1]['price']);
        self::assertSame($this->anHourAgo->format('Y-m-d H:i:s'), $prices[1]['communicated_at']);
    }

    public function testAnOlderPriceNeverReplacesANewerOne(): void
    {
        // Il ministero ha per il 2 una comunicazione di un'ora prima di quella salvata
        $older = (new \DateTimeImmutable($this->dailyAt2, new \DateTimeZone('Europe/Rome')))->modify('-1 hour')->setTimezone(new \DateTimeZone('UTC'));
        $this->live('1.999', $older->format('Y-m-d\TH:i:s\Z'))->refresh([2]);

        self::assertSame(['id' => 2, 'price' => '1.749', 'communicated_at' => $this->dailyAt2], $this->dieselSelf()[2]);
    }

    public function testEachStationIsAskedAtMostOnceEveryFifteenMinutes(): void
    {
        $cache = new ArrayAdapter();
        $this->live(cache: $cache)->refresh([1]);
        self::assertSame(0, $this->live(cache: $cache)->refresh([1]));
        self::assertCount(1, $this->requested);
    }

    public function testAFailureKeepsTheDailyPrices(): void
    {
        $this->live(fail: true)->refresh([1, 2]);

        self::assertSame('1.799', $this->dieselSelf()[1]['price']);
        self::assertSame('1.749', $this->dieselSelf()[2]['price']);
    }

    public function testSwitchedOffAsksNothing(): void
    {
        $live = new LiveFuelPrices(new MockHttpClient(function (): MockResponse {
            self::fail('Nessuna richiesta col servizio spento');
        }), $this->repo(), new ArrayAdapter(), new NullLogger(), true, false);

        self::assertFalse($live->isEnabled());
        self::assertSame(0, $live->refresh([1]));
    }

    public function testTheMorningImportKeepsNewerLivePricesButTheHistoryOfTheExtractionDayUsesTheFile(): void
    {
        $this->followStation(1);
        $this->live()->refresh([1]);

        $yesterday = new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Rome'));
        $extraction = $yesterday->format('Y-m-d');
        $day = $yesterday->format('d/m/Y');
        $stations = '';
        for ($id = 1; $id <= FuelPriceImporter::MIN_STATIONS; ++$id) {
            $stations .= "$id|GESTORE|Q8|Stradale|Q8 $id|VIA ROMA $id|MODENA|MO|44.6|10.9\n";
        }
        $importer = new FuelPriceImporter(
            new MockHttpClient([
                new MockResponse("Estrazione del $extraction\n".FuelPriceCsv::STATIONS_HEADER."\n".$stations),
                // Il 2 ha nel file una comunicazione più nuova di quella salvata: vince il file
                new MockResponse("Estrazione del $extraction\n".FuelPriceCsv::PRICES_HEADER."\n1|Gasolio|1.789|1|$day 07:30:00\n2|Gasolio|1.739|1|$day 21:00:00\n"),
            ]),
            $this->repo(),
            static::getContainer()->get(Connection::class),
            new NullLogger(),
        );
        $importer->import();

        $prices = $this->dieselSelf();
        self::assertSame('1.709', $prices[1]['price'], 'Il prezzo di stamattina resta');
        self::assertSame('1.739', $prices[2]['price'], 'Senza prezzo in tempo reale vale il file');
        self::assertSame(
            [['day' => $extraction, 'price' => '1.789']],
            static::getContainer()->get(Connection::class)->fetchAllAssociative("SELECT day, price FROM fuel_price_history WHERE station_id = 1 AND fuel = 'diesel' AND is_self = 1"),
        );
    }

    public function testTheHourlyJobRefreshesFollowedStationsAndWritesTodaysPoint(): void
    {
        $this->followStation(2);
        $clock = new AppClock('Europe/Rome');
        $tester = new CommandTester(new RefreshFollowedFuelPricesCommand($this->live(), $this->repo(), $clock));

        self::assertSame(0, $tester->execute([]));

        self::assertSame(['https://carburanti.mise.gov.it/ospzApi/registry/servicearea/2'], $this->requested);
        self::assertEquals(
            [['day' => $clock->today()->format('Y-m-d'), 'price' => '1.709']],
            static::getContainer()->get(Connection::class)->fetchAllAssociative("SELECT day, price FROM fuel_price_history WHERE station_id = 2 AND fuel = 'diesel' AND is_self = 1"),
        );
    }

    private function followStation(int $id): void
    {
        $value = static::getContainer()->get(UserValues::class)->findOrCreate(UserFactory::createOne(), OrganizationFactory::createOne(), UserValueKind::STATION, "Q8 $id");
        $value->linkFuelStation($id);
        $value->followPrice(true);
        static::getContainer()->get('doctrine')->getManager()->flush();
    }
}
