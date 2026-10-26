<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\UserValue;
use App\Enum\FuelType;
use App\Enum\ShareRole;
use App\Enum\UserValueKind;
use App\Message\ImportFuelPricesMessage;
use App\Repository\FuelStationRepository;
use App\Service\AppClock;
use App\Service\FuelPrices\FuelPrices;
use App\Service\UserValues;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FuelPriceControllerTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['FUEL_PRICES_ENABLED'], $_SERVER['FUEL_PRICES_ENABLED']);
        parent::tearDown();
    }

    /** Centro di Modena. */
    private const LAT = 44.6471;
    private const LNG = 10.9252;

    /** Tre distributori a Modena (uno senza posizione) e uno a Bologna, fuori dal raggio dei vicini. */
    private function seed(?\DateTimeImmutable $importedAt = null): void
    {
        $station = static fn (int $id, string $brand, string $address, string $city, ?float $lat, ?float $lng): array => [
            'id' => $id, 'operator' => 'GESTORE', 'brand' => $brand, 'name' => strtoupper($brand).' '.$id, 'address' => $address,
            'city' => $city, 'province' => 'MO', 'latitude' => $lat, 'longitude' => $lng,
        ];
        $price = static fn (int $id, FuelType $fuel, bool $self, string $value): array => [
            'stationId' => $id, 'fuel' => $fuel, 'self' => $self, 'price' => $value, 'communicatedAt' => new \DateTimeImmutable('2026-10-24 07:30:00'),
        ];
        static::getContainer()->get(FuelStationRepository::class)->replaceAll(
            [
                $station(1, 'Q8', 'VIA EMILIA EST 1420', 'MODENA', 44.6480, 10.9260),
                $station(2, 'Agip Eni', 'VIA GIARDINI 300', 'MODENA', 44.6300, 10.9100),
                $station(3, 'Tamoil', 'VIA NOTA 5', 'MODENA', null, null),
                $station(4, 'IP', 'VIA EMILIA 10', 'BOLOGNA', 44.4949, 11.3426),
            ],
            [
                $price(1, FuelType::DIESEL, true, '1.729'),
                $price(1, FuelType::DIESEL, false, '1.899'),
                $price(1, FuelType::GASOLINE, true, '1.849'),
                $price(2, FuelType::GASOLINE, true, '1.809'),
                $price(99, FuelType::GASOLINE, true, '1.500'), // distributore che non c'è: scartato
            ],
            new \DateTimeImmutable('2026-10-25'),
            $importedAt ?? new \DateTimeImmutable(),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        /** @var list<array<string, mixed>> */
        return $this->jsonBody();
    }

    private function asyncTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport */
        return static::getContainer()->get('messenger.transport.async');
    }

    public function testStatusGivesTheExtractionDate(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->seed();

        $this->jsonRequest('GET', '/api/fuel-prices/status', accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => true, 'extractedOn' => '2026-10-25'], $this->jsonBody());
        self::assertCount(0, $this->asyncTransport()->getSent(), 'dati freschi: nessun import in coda');
    }

    public function testStaleDataQueueOneImport(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->seed(new \DateTimeImmutable('-2 days'));

        $this->jsonRequest('GET', '/api/fuel-prices/status', accessToken: $token);
        $this->jsonRequest('GET', '/api/fuel-prices/status', accessToken: $token);

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent, 'al massimo un import in coda all\'ora');
        self::assertInstanceOf(ImportFuelPricesMessage::class, $sent[0]->getMessage());
    }

    public function testNearbyNearestFirstWithPricesForTheFuel(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->seed();

        $this->jsonRequest('GET', sprintf('/api/fuel-prices/nearby?lat=%F&lng=%F&fuel=diesel', self::LAT, self::LNG), accessToken: $token);

        self::assertResponseIsSuccessful();
        $rows = $this->rows();
        self::assertSame([1, 2], array_column($rows, 'id'), 'Bologna è fuori dal raggio, il distributore senza posizione non c\'è');
        self::assertSame('Q8 · Via Emilia Est 1420, Modena', $rows[0]['label']);
        self::assertLessThan(1.0, $rows[0]['distanceKm']);
        self::assertSame(['price' => '1.729', 'communicatedAt' => '2026-10-24'], $rows[0]['prices']['self']);
        self::assertSame('1.899', $rows[0]['prices']['served']['price']);
        self::assertSame(['self' => null, 'served' => null], $rows[1]['prices'], 'nessun prezzo del gasolio');
    }

    public function testHybridUsesGasolineAndElectricHasNoPrice(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->seed();

        $this->jsonRequest('GET', sprintf('/api/fuel-prices/nearby?lat=%F&lng=%F&fuel=hybrid', self::LAT, self::LNG), accessToken: $token);
        self::assertSame('1.849', $this->rows()[0]['prices']['self']['price']);

        $this->jsonRequest('GET', sprintf('/api/fuel-prices/nearby?lat=%F&lng=%F&fuel=electric', self::LAT, self::LNG), accessToken: $token);
        self::assertSame(['self' => null, 'served' => null], $this->rows()[0]['prices']);
    }

    public function testNearbyRejectsAnInvalidPosition(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/fuel-prices/nearby?lat=abc&lng=10', accessToken: $token);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('fuel_prices.invalid_query', $this->jsonBody()['title']);

        $this->jsonRequest('GET', '/api/fuel-prices/nearby?lat=95&lng=10', accessToken: $token);
        self::assertResponseStatusCodeSame(400);
    }

    public function testSearchMatchesEveryWord(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->seed();

        $this->jsonRequest('GET', '/api/fuel-prices/search?q='.rawurlencode('emilia modena').'&fuel=gasoline', accessToken: $token);

        self::assertResponseIsSuccessful();
        $rows = $this->rows();
        self::assertSame([1], array_column($rows, 'id'));
        self::assertNull($rows[0]['distanceKm']);
        self::assertSame('1.849', $rows[0]['prices']['self']['price']);

        $this->jsonRequest('GET', '/api/fuel-prices/search?q=100%25', accessToken: $token);
        self::assertSame([], $this->jsonBody(), 'i caratteri jolly di LIKE si cercano alla lettera');

        $this->jsonRequest('GET', '/api/fuel-prices/search?q=a', accessToken: $token);
        self::assertResponseStatusCodeSame(400);
    }

    public function testStation(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->seed();

        $this->jsonRequest('GET', '/api/fuel-prices/stations/2?fuel=gasoline', accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertSame('1.809', $this->jsonBody()['prices']['self']['price']);

        $this->jsonRequest('GET', '/api/fuel-prices/stations/99', accessToken: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('fuel_prices.station_not_found', $this->jsonBody()['title']);
    }

    public function testRequiresAuthentication(): void
    {
        $this->jsonRequest('GET', '/api/fuel-prices/status');
        self::assertResponseStatusCodeSame(401);
    }

    public function testDisabledInstance(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->seed();
        // Letta all'avvio di ogni richiesta (il client riavvia il kernel): vale da qui in poi
        $_ENV['FUEL_PRICES_ENABLED'] = $_SERVER['FUEL_PRICES_ENABLED'] = '0';

        $this->jsonRequest('GET', '/api/fuel-prices/status', accessToken: $token);
        self::assertSame(['enabled' => false, 'extractedOn' => null], $this->jsonBody());

        $this->jsonRequest('GET', sprintf('/api/fuel-prices/nearby?lat=%F&lng=%F', self::LAT, self::LNG), accessToken: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('fuel_prices.disabled', $this->jsonBody()['title']);
    }

    public function testLinkAndUnlinkAStationEntry(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $this->seed();
        $entry = static::getContainer()->get(UserValues::class)->findOrCreate($user, $org, UserValueKind::STATION, 'Q8 sotto casa');

        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/fuel-station', ['fuelStationId' => 1], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->jsonBody()['fuelStationId']);

        $this->jsonRequest('GET', '/api/values/station', accessToken: $token);
        $row = array_values(array_filter($this->rows(), static fn (array $r): bool => $r['name'] === 'Q8 sotto casa'))[0];
        self::assertSame(1, $row['fuelStationId']);

        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/fuel-station', ['fuelStationId' => 99], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('fuel_prices.station_not_found', $this->jsonBody()['errors'][0]['message']);

        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/fuel-station', ['fuelStationId' => null], $token);
        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody()['fuelStationId']);
    }

    public function testOnlyStationEntriesCanBeLinked(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $this->seed();
        $shop = static::getContainer()->get(UserValues::class)->findOrCreate($user, $org, UserValueKind::SHOP, 'Ricambi Bianchi');

        $this->jsonRequest('PATCH', '/api/values/'.$shop->getId().'/fuel-station', ['fuelStationId' => 1], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('user_value.not_a_station', $this->jsonBody()['errors'][0]['message']);
    }

    public function testAnotherUsersEntryIsNotFound(): void
    {
        [$owner, $org] = $this->createAuthenticatedUser();
        [, , $otherToken] = $this->createAuthenticatedUser();
        $this->seed();
        $entry = static::getContainer()->get(UserValues::class)->findOrCreate($owner, $org, UserValueKind::STATION, 'Q8');

        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/fuel-station', ['fuelStationId' => 1], $otherToken);
        self::assertResponseStatusCodeSame(404);
    }

    /** @return list<array{day: string, price: string}> */
    private function historyOf(int $stationId, string $fuel): array
    {
        /** @var list<array{day: string, price: string}> */
        return static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT day, price FROM fuel_price_history WHERE station_id = ? AND fuel = ? AND is_self = 1 ORDER BY day',
            [$stationId, $fuel],
        );
    }

    public function testFollowingALinkedEntryKeepsTodaysPrice(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $this->seed();
        $entry = static::getContainer()->get(UserValues::class)->findOrCreate($user, $org, UserValueKind::STATION, 'Q8 sotto casa');

        // Non collegata: non c'è un prezzo da seguire
        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/follow-price', ['follow' => true], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('fuel_prices.not_linked', $this->jsonBody()['errors'][0]['message']);

        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/fuel-station', ['fuelStationId' => 1], $token);
        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/follow-price', ['follow' => true], $token);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['followsPrice']);
        // Il prezzo dell'ultimo import entra subito nello storico, servito compreso
        self::assertSame([['day' => '2026-10-25', 'price' => '1.729']], $this->historyOf(1, 'diesel'));
        self::assertSame([['day' => '2026-10-25', 'price' => '1.849']], $this->historyOf(1, 'gasoline'));

        $this->jsonRequest('GET', '/api/values/station', accessToken: $token);
        $row = array_values(array_filter($this->rows(), static fn (array $r): bool => $r['name'] === 'Q8 sotto casa'))[0];
        self::assertTrue($row['followsPrice']);

        // Scollegata smette di seguire; lo storico resta
        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/fuel-station', ['fuelStationId' => null], $token);
        self::assertFalse($this->jsonBody()['followsPrice']);
        self::assertCount(1, $this->historyOf(1, 'diesel'));

        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/follow-price', ['follow' => false], $token);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->jsonBody()['followsPrice']);
    }

    public function testFollowPriceValidation(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        [, , $otherToken] = $this->createAuthenticatedUser();
        $this->seed();
        $values = static::getContainer()->get(UserValues::class);
        $shop = $values->findOrCreate($user, $org, UserValueKind::SHOP, 'Ricambi Bianchi');
        $entry = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Q8');

        $this->jsonRequest('PATCH', '/api/values/'.$shop->getId().'/follow-price', ['follow' => true], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('user_value.not_a_station', $this->jsonBody()['errors'][0]['message']);

        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/follow-price', [], $token);
        self::assertResponseStatusCodeSame(422);

        $this->jsonRequest('PATCH', '/api/values/'.$entry->getId().'/follow-price', ['follow' => true], $otherToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFollowedTrendGivesTheLastThirtyDaysOfEachFollowedStation(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        [$other, $otherOrg] = $this->createAuthenticatedUser();
        $this->seed();
        $values = static::getContainer()->get(UserValues::class);
        $fuelPrices = static::getContainer()->get(FuelPrices::class);
        foreach ([[$user, $org, 'Q8 sotto casa', 1], [$user, $org, 'Agip lavoro', 2], [$user, $org, 'Tamoil', 3], [$other, $otherOrg, 'Q8', 1]] as [$owner, $organization, $name, $stationId]) {
            $entry = $values->findOrCreate($owner, $organization, UserValueKind::STATION, $name);
            $fuelPrices->link($entry, $stationId);
            if ($name !== 'Tamoil') {
                $fuelPrices->follow($entry, true);
            }
        }
        static::getContainer()->get('doctrine')->getManager()->flush();

        $today = static::getContainer()->get(AppClock::class)->today();
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM fuel_price_history');
        foreach ([[1, 40, '1.500'], [1, 29, '1.700'], [1, 0, '1.720'], [2, 3, '1.690']] as [$stationId, $daysAgo, $price]) {
            $connection->insert('fuel_price_history', [
                'station_id' => $stationId, 'fuel' => 'diesel', 'is_self' => 1, 'price' => $price,
                'day' => $today->modify("-$daysAgo days")->format('Y-m-d'),
            ]);
        }
        $connection->insert('fuel_price_history', ['station_id' => 1, 'fuel' => 'diesel', 'is_self' => 0, 'price' => '1.899', 'day' => $today->format('Y-m-d')]);

        $this->jsonRequest('GET', '/api/fuel-prices/followed?fuel=diesel', accessToken: $token);

        self::assertResponseIsSuccessful();
        $rows = $this->rows();
        // Per nome, solo i seguiti dell'utente; senza prezzi del carburante resta, con la serie vuota
        self::assertSame(['Agip lavoro', 'Q8 sotto casa'], array_column($rows, 'name'));
        self::assertSame([['day' => $today->modify('-3 days')->format('Y-m-d'), 'price' => '1.690']], $rows[0]['points']);
        self::assertSame([
            ['day' => $today->modify('-29 days')->format('Y-m-d'), 'price' => '1.700'],
            ['day' => $today->format('Y-m-d'), 'price' => '1.720'],
        ], $rows[1]['points']);
        self::assertSame(1, $rows[1]['fuelStationId']);

        $this->jsonRequest('GET', '/api/fuel-prices/followed?fuel=gasoline', accessToken: $token);
        self::assertSame([[], []], array_column($this->rows(), 'points'));

        foreach (['', 'hybrid', 'electric', 'kerosene'] as $fuel) {
            $this->jsonRequest('GET', '/api/fuel-prices/followed?fuel='.$fuel, accessToken: $token);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testRefuelingFromANearbyStationLinksTheEntry(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $this->seed();
        $vehicle = VehicleFactory::createOne(['organization' => $org, 'fuelType' => FuelType::DIESEL, 'secondaryFuelType' => null]);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $user, 'role' => ShareRole::ADMIN]);

        $this->jsonRequest('POST', '/api/refuelings', [
            'vehicleId' => $vehicle->getId(),
            'refueledAt' => '2026-10-25',
            'km' => 50000,
            'liters' => '40.000',
            'pricePerLiter' => '1.7290',
            'fuelType' => 'diesel',
            'fullTank' => true,
            'station' => 'Q8 · Via Emilia Est 1420, Modena',
            'fuelStationId' => 1,
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        $entry = static::getContainer()->get('doctrine')->getRepository(UserValue::class)
            ->findOneBy(['name' => 'Q8 · Via Emilia Est 1420, Modena']);
        self::assertInstanceOf(UserValue::class, $entry);
        self::assertSame(1, $entry->getFuelStationId());
    }
}
