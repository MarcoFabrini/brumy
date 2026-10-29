<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\ExpenseSection;
use App\Enum\FuelType;
use App\Enum\MaintenanceCategory;
use App\Enum\MaintenanceType;
use App\Enum\OrgRole;
use App\Enum\RecurringPeriod;
use App\Enum\ReminderType;
use App\Enum\UserValueKind;
use App\Enum\VehicleType;
use App\Tests\Factory\ComponentFactory;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\UserValueFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ricerca negli elenchi (`?q=` + `hint`): per ogni elenco testi, nomi collegati, valori tradotti, importi, date, km,
 * più parole in AND, maiuscole/accenti, `%`/`_` letterali, combinazione con i filtri esistenti e scoping invariato.
 * Le regole di interpretazione della parola sono in `tests/Unit/Service/Search/ListSearchTest.php`.
 */
final class ListSearchTest extends ApiTestCase
{
    public function testMaintenances(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $workshop = $this->value($user, $org, UserValueKind::WORKSHOP, 'Officina Rossi');
        $shop = $this->value($user, $org, UserValueKind::SHOP, 'Ricambi_Bianchi');
        $pads = ComponentFactory::createOne(['vehicle' => $vehicle, 'name' => 'Pastiglie Brembo']);
        $discs = ComponentFactory::createOne(['vehicle' => $vehicle, 'name' => 'Dischi Brembo']);

        $ids = [
            'tagliando' => $this->maintenance($vehicle, '2024-05-10', 'Tagliando 30.000 km', ['type' => MaintenanceType::OIL_CHANGE, 'cost' => '80.00', 'km' => 30000]),
            'revisione' => $this->maintenance($vehicle, '2025-03-12', 'Controllo', ['type' => MaintenanceType::INSPECTION, 'category' => MaintenanceCategory::INSPECTION, 'cost' => '120.50', 'km' => 125000, 'notes' => 'perché faceva rumore']),
            'riparazione' => $this->maintenance($vehicle, '2025-03-20', 'Riparazione', ['type' => MaintenanceType::REPAIR, 'category' => MaintenanceCategory::UNSCHEDULED, 'cost' => '120.00', 'km' => 140000, 'workshop' => $workshop, 'components' => [$pads, $discs]]),
            'sconto' => $this->maintenance($vehicle, '2025-07-01', 'Sconto 50% sul lavoro', ['type' => MaintenanceType::OTHER, 'cost' => '12.00', 'km' => 150000, 'shop' => $shop]),
            'lavaggio' => $this->maintenance($vehicle, '2023-03-05', 'Lavaggio 500', ['type' => MaintenanceType::WASH, 'cost' => '1200.00', 'km' => 3000, 'notes' => 'Rossi consiglia']),
            'kit' => $this->maintenance($vehicle, '2022-01-01', 'Kit Brembo', ['type' => MaintenanceType::BRAKES, 'cost' => null, 'km' => 1000]),
        ];
        // Stessa org, altro veicolo: non compare mai, anche se corrisponde
        $this->maintenance(VehicleFactory::createOne(['organization' => $org]), '2025-03-12', 'Tagliando Brembo', ['cost' => '120.50']);

        $url = '/api/maintenances';
        $base = ['vehicleId' => $vehicle->getId()];
        $this->assertSearch($url, $base, array_keys($ids), $ids, $session, 'senza ricerca: tutto');
        $this->assertSearch($url, $base + ['q' => '   '], array_keys($ids), $ids, $session, 'solo spazi: nessun filtro');
        $this->assertSearch($url, $base + ['q' => 'tagliando', 'hint' => [0 => ['maintenance_type:inspection']]], ['tagliando', 'revisione'], $ids, $session, 'testo o tipo tradotto');
        $this->assertSearch($url, $base + ['q' => 'TAGL'], ['tagliando'], $ids, $session, 'maiuscole e parziale');
        $this->assertSearch($url, $base + ['q' => 'perche'], ['revisione'], $ids, $session, 'accenti, nelle note');
        $this->assertSearch($url, $base + ['q' => 'rossi'], ['riparazione', 'lavaggio'], $ids, $session, 'officina o note');
        $this->assertSearch($url, $base + ['q' => 'bianchi'], ['sconto'], $ids, $session, 'negozio');
        $this->assertSearch($url, $base + ['q' => 'revisione', 'hint' => [0 => ['maintenance_category:inspection']]], ['revisione'], $ids, $session, 'sezione tradotta');
        $this->assertSearch($url, $base + ['q' => '50%'], ['sconto'], $ids, $session, '% letterale');
        $this->assertSearch($url, $base + ['q' => '_'], ['sconto'], $ids, $session, '_ letterale');
        $this->assertSearch($url, $base + ['q' => '120'], ['revisione', 'riparazione'], $ids, $session, 'importo intero: 120,00 e 120,50, non 12,00 né 1.200,00');
        $this->assertSearch($url, $base + ['q' => '120,50'], ['revisione'], $ids, $session, 'importo con virgola');
        $this->assertSearch($url, $base + ['q' => '€120.50'], ['revisione'], $ids, $session, 'importo con punto e euro');
        $this->assertSearch($url, $base + ['q' => '2025'], ['revisione', 'riparazione', 'sconto'], $ids, $session, 'anno');
        $this->assertSearch($url, $base + ['q' => '03/2025'], ['revisione', 'riparazione'], $ids, $session, 'mese/anno');
        $this->assertSearch($url, $base + ['q' => 'marzo', 'hint' => [0 => ['month:3']]], ['revisione', 'riparazione', 'lavaggio'], $ids, $session, 'mese tradotto, ogni anno');
        $this->assertSearch($url, $base + ['q' => 'marzo 2025', 'hint' => [0 => ['month:3']]], ['revisione', 'riparazione'], $ids, $session, 'mese tradotto + anno');
        $this->assertSearch($url, $base + ['q' => '12/03/2025'], ['revisione'], $ids, $session, 'giorno');
        $this->assertSearch($url, $base + ['q' => '31/02/2025'], [], $ids, $session, 'data impossibile: solo testo');
        $this->assertSearch($url, $base + ['q' => '125000'], ['revisione'], $ids, $session, 'km');
        $this->assertSearch($url, $base + ['q' => '125.000'], ['revisione'], $ids, $session, 'km con il punto delle migliaia');
        $this->assertSearch($url, $base + ['q' => '1200'], ['lavaggio'], $ids, $session, 'importo 1.200,00');
        $this->assertSearch($url, $base + ['q' => '1.200'], [], $ids, $session, '1.200 sono km (nessuno), non l\'importo 1.200,00');
        $this->assertSearch($url, $base + ['q' => 'riparazione rossi'], ['riparazione'], $ids, $session, 'due parole in AND: testo + officina');
        $this->assertSearch($url, $base + ['q' => 'rossi', 'category' => 'unscheduled'], ['riparazione'], $ids, $session, 'con la sezione');
        $this->assertSearch($url, $base + ['q' => 'brembo', 'limit' => 2], ['riparazione', 'kit'], $ids, $session, 'componenti: un lavoro con due componenti trovati compare una volta, e il limite resta giusto', ordered: true);
        $this->assertSearch($url, $base + ['q' => 'brembo', 'limit' => 1, 'page' => 2], ['kit'], $ids, $session, 'pagina successiva');
    }

    public function testExpenses(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $washing = $this->value($user, $org, UserValueKind::EXPENSE_CATEGORY, 'Lavaggio auto');
        $roadTax = $this->value($user, $org, UserValueKind::TAX_TYPE, 'Bollo auto');
        $insurer = $this->value($user, $org, UserValueKind::INSURER, 'Allianz');

        $ids = [
            'lavaggio' => $this->expense($vehicle, '2025-03-12', 'Spesa varia', '45.00', ['category' => $washing, 'notes' => 'Rossi']),
            'polizza' => $this->expense($vehicle, '2024-06-01', 'Polizza', '600.00', ['section' => ExpenseSection::INSURANCE, 'category' => null, 'insurer' => $insurer, 'recurring' => true, 'recurringPeriod' => RecurringPeriod::YEARLY]),
            'bollo' => $this->expense($vehicle, '2025-01-10', 'Pagamento', '120.50', ['section' => ExpenseSection::TAX, 'category' => $roadTax]),
        ];
        $this->expense(VehicleFactory::createOne(['organization' => $org]), '2025-03-12', 'Bollo Allianz', '120.50', []);

        $url = '/api/expenses';
        $base = ['vehicleId' => $vehicle->getId()];
        $this->assertSearch($url, $base, array_keys($ids), $ids, $session, 'senza ricerca');
        $this->assertSearch($url, $base + ['q' => 'allianz'], ['polizza'], $ids, $session, 'compagnia');
        $this->assertSearch($url, $base + ['q' => 'BOLLO'], ['bollo'], $ids, $session, 'tipo di tassa');
        $this->assertSearch($url, $base + ['q' => 'lavagg'], ['lavaggio'], $ids, $session, 'categoria');
        $this->assertSearch($url, $base + ['q' => 'rossi'], ['lavaggio'], $ids, $session, 'note');
        $this->assertSearch($url, $base + ['q' => 'assicurazione', 'hint' => [0 => ['expense_section:insurance']]], ['polizza'], $ids, $session, 'sezione tradotta');
        $this->assertSearch($url, $base + ['q' => 'annuale', 'hint' => [0 => ['recurring_period:yearly']]], ['polizza'], $ids, $session, 'ricorrenza tradotta');
        $this->assertSearch($url, $base + ['q' => '120,50'], ['bollo'], $ids, $session, 'importo');
        $this->assertSearch($url, $base + ['q' => '2025'], ['lavaggio', 'bollo'], $ids, $session, 'anno');
        $this->assertSearch($url, $base + ['q' => 'marzo 2025', 'hint' => [0 => ['month:3']]], ['lavaggio'], $ids, $session, 'mese tradotto + anno');
        $this->assertSearch($url, $base + ['q' => '12/03/2025'], ['lavaggio'], $ids, $session, 'giorno');
        $this->assertSearch($url, $base + ['q' => 'pagamento bollo'], ['bollo'], $ids, $session, 'due parole: descrizione + tipo di tassa');
        $this->assertSearch($url, $base + ['q' => 'pagamento allianz'], [], $ids, $session, 'due parole che non stanno nello stesso record');
        $this->assertSearch($url, $base + ['q' => '2025', 'section' => 'tax'], ['bollo'], $ids, $session, 'con la sezione');
    }

    public function testRefuelings(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $station = $this->value($user, $org, UserValueKind::STATION, 'Q8 Modena');

        $ids = [
            'q8' => $this->refueling($vehicle, '2025-03-12', 125000, '40.000', '1.8000', ['fuelType' => FuelType::DIESEL, 'station' => $station]),
            'mare' => $this->refueling($vehicle, '2024-08-01', 90000, '50.000', '1.9000', ['fuelType' => FuelType::LPG, 'fullTank' => false, 'notes' => 'Viaggio al mare']),
        ];
        $this->refueling(VehicleFactory::createOne(['organization' => $org]), '2025-03-12', 125000, '40.000', '1.8000', ['notes' => 'Q8 mare']);

        $url = '/api/refuelings';
        $base = ['vehicleId' => $vehicle->getId()];
        $this->assertSearch($url, $base, array_keys($ids), $ids, $session, 'senza ricerca');
        $this->assertSearch($url, $base + ['q' => 'q8'], ['q8'], $ids, $session, 'distributore');
        $this->assertSearch($url, $base + ['q' => 'MARE'], ['mare'], $ids, $session, 'note');
        $this->assertSearch($url, $base + ['q' => 'gasolio', 'hint' => [0 => ['fuel_type:diesel']]], ['q8'], $ids, $session, 'carburante tradotto');
        $this->assertSearch($url, $base + ['q' => 'parziale', 'hint' => [0 => ['refueling_tank:partial']]], ['mare'], $ids, $session, 'parziale tradotto');
        $this->assertSearch($url, $base + ['q' => 'pieno', 'hint' => [0 => ['refueling_tank:full']]], ['q8'], $ids, $session, 'pieno tradotto');
        $this->assertSearch($url, $base + ['q' => '72'], ['q8'], $ids, $session, 'importo: 40 × 1,80 = 72,00');
        $this->assertSearch($url, $base + ['q' => '95,00'], ['mare'], $ids, $session, 'importo con decimali');
        $this->assertSearch($url, $base + ['q' => '2025'], ['q8'], $ids, $session, 'anno');
        $this->assertSearch($url, $base + ['q' => 'marzo', 'hint' => [0 => ['month:3']]], ['q8'], $ids, $session, 'mese tradotto');
        $this->assertSearch($url, $base + ['q' => '125.000'], ['q8'], $ids, $session, 'km');
        $this->assertSearch($url, $base + ['q' => 'viaggio 2024'], ['mare'], $ids, $session, 'due parole: note + anno');
    }

    public function testRemindersOfAVehicle(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);

        $ids = [
            'revisione' => $this->reminder($vehicle, ReminderType::INSPECTION, 'Revisione ministeriale', ['dueDate' => new \DateTimeImmutable('2025-03-12')]),
            'filtro' => $this->reminder($vehicle, ReminderType::CUSTOM, 'Cambio filtro abitacolo', ['dueDate' => null, 'dueKm' => 125000, 'notes' => 'perché polline']),
            'fatto' => $this->reminder($vehicle, ReminderType::SERVICE, 'Controllo', ['dueDate' => new \DateTimeImmutable('2026-05-01'), 'completedAt' => new \DateTimeImmutable()]),
        ];
        $this->reminder(VehicleFactory::createOne(['organization' => $org]), ReminderType::INSPECTION, 'Revisione', ['dueDate' => new \DateTimeImmutable('2025-03-12')]);

        $url = '/api/reminders';
        $base = ['vehicleId' => $vehicle->getId()];
        $this->assertSearch($url, $base, ['revisione', 'filtro'], $ids, $session, 'senza ricerca: i soli attivi');
        $this->assertSearch($url, $base + ['q' => 'revisione', 'hint' => [0 => ['reminder_type:inspection']]], ['revisione'], $ids, $session, 'testo o tipo');
        $this->assertSearch($url, $base + ['q' => 'tagliando', 'hint' => [0 => ['reminder_type:service']]], [], $ids, $session, 'il completato resta fuori');
        $this->assertSearch($url, $base + ['q' => 'tagliando', 'hint' => [0 => ['reminder_type:service']], 'onlyActive' => 'false'], ['fatto'], $ids, $session, 'con onlyActive=false');
        $this->assertSearch($url, $base + ['q' => 'PERCHE'], ['filtro'], $ids, $session, 'note, maiuscole e accenti');
        $this->assertSearch($url, $base + ['q' => '125000'], ['filtro'], $ids, $session, 'km di scadenza');
        $this->assertSearch($url, $base + ['q' => '12/03/2025'], ['revisione'], $ids, $session, 'giorno di scadenza');
        $this->assertSearch($url, $base + ['q' => 'marzo 2025', 'hint' => [0 => ['month:3']]], ['revisione'], $ids, $session, 'mese + anno');
        $this->assertSearch($url, $base + ['q' => 'cambio abitacolo'], ['filtro'], $ids, $session, 'due parole');
    }

    public function testReminderOverviewSearchesOnlyTheOwnedActiveVehicles(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();
        $panda = $this->ownedVehicle($user, $org, ['name' => 'Panda', 'licensePlate' => 'AB123CD']);
        $golf = $this->ownedVehicle($user, $org, ['name' => 'Golf', 'licensePlate' => 'ZZ999ZZ']);
        $shared = VehicleFactory::createOne(['organization' => $org, 'name' => 'Panda condivisa']);
        VehicleShareFactory::createOne(['vehicle' => $shared, 'user' => $user]);
        $archived = $this->ownedVehicle($user, $org, ['name' => 'Panda archiviata', 'archivedAt' => new \DateTimeImmutable()]);
        $otherOrg = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $otherOrg, 'role' => OrgRole::OWNER]);
        $elsewhere = $this->ownedVehicle($user, $otherOrg, ['name' => 'Panda altrove']);

        $ids = [
            'bollo' => $this->reminder($panda, ReminderType::ROAD_TAX, 'Bollo', ['dueDate' => new \DateTimeImmutable('2025-03-12')]),
            'polizza' => $this->reminder($golf, ReminderType::INSURANCE, 'Polizza', ['dueDate' => new \DateTimeImmutable('2025-06-01')]),
        ];
        foreach ([$shared, $archived, $elsewhere] as $vehicle) {
            $this->reminder($vehicle, ReminderType::ROAD_TAX, 'Bollo', ['dueDate' => new \DateTimeImmutable('2025-03-12')]);
        }

        $url = '/api/reminders/overview';
        $this->assertSearch($url, [], array_keys($ids), $ids, $session, 'senza ricerca');
        $this->assertSearch($url, ['q' => 'bollo'], ['bollo'], $ids, $session, 'testo: solo veicoli propri, attivi, dell\'org attiva');
        $this->assertSearch($url, ['q' => 'panda'], ['bollo'], $ids, $session, 'nome del veicolo');
        $this->assertSearch($url, ['q' => 'ab123'], ['bollo'], $ids, $session, 'targa');
        $this->assertSearch($url, ['q' => 'assicurazione', 'hint' => [0 => ['reminder_type:insurance']]], ['polizza'], $ids, $session, 'tipo tradotto');
        $this->assertSearch($url, ['q' => 'golf 2025'], ['polizza'], $ids, $session, 'veicolo + anno');
    }

    public function testComponents(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $shop = $this->value($user, $org, UserValueKind::SHOP, 'Ricambi Bianchi');

        $ids = [
            'pastiglie' => (int) ComponentFactory::createOne(['vehicle' => $vehicle, 'name' => 'Pastiglie Brembo', 'code' => 'P85020', 'serialNumber' => 'SN-77', 'shop' => $shop, 'purchasedAt' => new \DateTimeImmutable('2025-03-12'), 'price' => '45.90', 'notes' => 'anteriori'])->getId(),
            'batteria' => (int) ComponentFactory::createOne(['vehicle' => $vehicle, 'name' => 'Batteria Varta', 'purchasedAt' => new \DateTimeImmutable('2024-01-01'), 'price' => '120.00'])->getId(),
        ];
        $deleted = ComponentFactory::createOne(['vehicle' => $vehicle, 'name' => 'Brembo vecchie']);
        $deleted->softDelete();
        static::getContainer()->get('doctrine')->getManager()->flush();
        ComponentFactory::createOne(['vehicle' => VehicleFactory::createOne(['organization' => $org]), 'name' => 'Brembo altrove']);

        $url = '/api/components';
        $base = ['vehicleId' => $vehicle->getId()];
        $this->assertSearch($url, $base, ['batteria', 'pastiglie'], $ids, $session, 'senza ricerca');
        $this->assertSearch($url, $base + ['q' => 'brembo'], ['pastiglie'], $ids, $session, 'nome; eliminati e altri veicoli esclusi');
        $this->assertSearch($url, $base + ['q' => 'bianchi'], ['pastiglie'], $ids, $session, 'negozio');
        $this->assertSearch($url, $base + ['q' => 'sn-77'], ['pastiglie'], $ids, $session, 'numero di serie');
        $this->assertSearch($url, $base + ['q' => 'p850'], ['pastiglie'], $ids, $session, 'codice');
        $this->assertSearch($url, $base + ['q' => 'ANTERIORI'], ['pastiglie'], $ids, $session, 'note');
        $this->assertSearch($url, $base + ['q' => '120'], ['batteria'], $ids, $session, 'prezzo');
        $this->assertSearch($url, $base + ['q' => '45,90'], ['pastiglie'], $ids, $session, 'prezzo con decimali');
        $this->assertSearch($url, $base + ['q' => '2024'], ['batteria'], $ids, $session, 'anno d\'acquisto');
        $this->assertSearch($url, $base + ['q' => 'marzo', 'hint' => [0 => ['month:3']]], ['pastiglie'], $ids, $session, 'mese tradotto');
        $this->assertSearch($url, $base + ['q' => '12/03/2025'], ['pastiglie'], $ids, $session, 'giorno');
        $this->assertSearch($url, $base + ['q' => 'batteria brembo'], [], $ids, $session, 'due parole in record diversi');
    }

    public function testVehiclesSearchOnlyTheAccessibleOnes(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $panda = VehicleFactory::createOne(['organization' => $org, 'name' => 'Panda', 'brand' => 'Fiat', 'model' => 'Panda 4x4', 'licensePlate' => 'AB123CD', 'vin' => 'ZFA31200000123456', 'year' => 2025, 'type' => VehicleType::CAR, 'fuelType' => FuelType::LPG, 'secondaryFuelType' => FuelType::GASOLINE, 'initialKm' => 125000]);
        VehicleShareFactory::createOne(['vehicle' => $panda, 'user' => $user]);
        $moto = $this->ownedVehicle($user, $org, ['name' => 'Moto', 'brand' => 'Honda', 'model' => 'Africa Twin', 'year' => 2019, 'type' => VehicleType::MOTORCYCLE, 'fuelType' => FuelType::GASOLINE, 'initialKm' => 0, 'notes' => 'In garage']);
        $oldPanda = VehicleFactory::createOne(['organization' => $org, 'name' => 'Panda vecchia', 'brand' => 'Fiat', 'year' => 2005, 'initialKm' => 0, 'archivedAt' => new \DateTimeImmutable()]);
        VehicleShareFactory::createOne(['vehicle' => $oldPanda, 'user' => $user]);
        // Stessa org ma senza condivisione, e altra org: mai
        VehicleFactory::createOne(['organization' => $org, 'name' => 'Panda di Luca', 'brand' => 'Fiat', 'year' => 2025]);
        VehicleFactory::createOne(['name' => 'Panda altrove', 'brand' => 'Fiat', 'year' => 2025]);

        $ids = ['panda' => (int) $panda->getId(), 'moto' => (int) $moto->getId(), 'vecchia' => (int) $oldPanda->getId()];
        $url = '/api/vehicles';
        $this->assertSearch($url, [], ['panda', 'moto'], $ids, $session, 'senza ricerca');
        $this->assertSearch($url, ['q' => 'panda'], ['panda'], $ids, $session, 'nome, solo gli accessibili');
        $this->assertSearch($url, ['q' => 'panda', 'archived' => '1'], ['vecchia'], $ids, $session, 'con gli archiviati');
        $this->assertSearch($url, ['q' => 'FIAT'], ['panda'], $ids, $session, 'marca');
        $this->assertSearch($url, ['q' => 'africa'], ['moto'], $ids, $session, 'modello');
        $this->assertSearch($url, ['q' => 'ab123'], ['panda'], $ids, $session, 'targa');
        $this->assertSearch($url, ['q' => 'zfa312'], ['panda'], $ids, $session, 'telaio');
        $this->assertSearch($url, ['q' => 'garage'], ['moto'], $ids, $session, 'note');
        $this->assertSearch($url, ['q' => 'motocicletta', 'hint' => [0 => ['vehicle_type:motorcycle']]], ['moto'], $ids, $session, 'tipo tradotto');
        $this->assertSearch($url, ['q' => 'benzina', 'hint' => [0 => ['fuel_type:gasoline']]], ['panda', 'moto'], $ids, $session, 'carburante, anche il secondario');
        $this->assertSearch($url, ['q' => '2025'], ['panda'], $ids, $session, 'anno del veicolo');
        $this->assertSearch($url, ['q' => '125.000'], ['panda'], $ids, $session, 'km iniziali');
        $this->assertSearch($url, ['q' => 'fiat 2019'], [], $ids, $session, 'due parole in veicoli diversi');
    }

    /** @return iterable<string, array{string}> */
    public static function vehicleLists(): iterable
    {
        yield 'lavori' => ['/api/maintenances'];
        yield 'spese' => ['/api/expenses'];
        yield 'rifornimenti' => ['/api/refuelings'];
        yield 'promemoria' => ['/api/reminders'];
        yield 'componenti' => ['/api/components'];
    }

    #[DataProvider('vehicleLists')]
    public function testSearchKeepsTheVehicleScoping(string $url): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $notShared = VehicleFactory::createOne(['organization' => $org]);
        $otherOrg = VehicleFactory::createOne();
        $search = ['q' => 'tagliando 2025', 'hint' => [0 => ['maintenance_type:inspection']]];

        $this->jsonRequest('GET', $url . '?' . http_build_query(['vehicleId' => $notShared->getId()] + $search), session: $session);
        self::assertResponseStatusCodeSame(403);
        $this->jsonRequest('GET', $url . '?' . http_build_query(['vehicleId' => $otherOrg->getId()] + $search), session: $session);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('vehicle.not_found', $this->jsonBody()['title']);
    }

    /** @return iterable<string, array{string}> */
    public static function allLists(): iterable
    {
        yield from self::vehicleLists();
        yield 'panoramica' => ['/api/reminders/overview'];
        yield 'veicoli' => ['/api/vehicles'];
    }

    #[DataProvider('allLists')]
    public function testMalformedParametersAre400AndLongQueriesAreFine(string $url): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $base = str_starts_with($url, '/api/vehicles') || str_contains($url, 'overview') ? [] : ['vehicleId' => $vehicle->getId()];

        $this->jsonRequest('GET', $url . '?' . http_build_query($base + ['hint' => 'x']), session: $session);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('http.400', $this->jsonBody()['title']);

        $this->jsonRequest('GET', $url . '?' . http_build_query($base) . '&q[]=x', session: $session);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('http.400', $this->jsonBody()['title']);

        $this->jsonRequest('GET', $url . '?' . http_build_query($base + ['q' => str_repeat('tagliando ', 1000), 'hint' => [0 => array_fill(0, 500, 'month:3'), 99 => ['month:4']]]), session: $session);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody());
    }

    public function testSearchAddsNoQueries(): void
    {
        [$user, $org, $session] = $this->createAuthenticatedUser();
        $vehicle = $this->ownedVehicle($user, $org);
        $workshop = $this->value($user, $org, UserValueKind::WORKSHOP, 'Officina Rossi');
        $component = ComponentFactory::createOne(['vehicle' => $vehicle, 'name' => 'Filtro Rossi']);
        foreach (range(1, 5) as $day) {
            $this->maintenance($vehicle, sprintf('2025-03-%02d', $day), 'Tagliando ' . $day, ['workshop' => $workshop, 'components' => [$component]]);
        }

        $plain = $this->queriesOf('/api/maintenances?vehicleId=' . $vehicle->getId(), $session);
        $searched = $this->queriesOf('/api/maintenances?' . http_build_query(['vehicleId' => $vehicle->getId(), 'q' => 'rossi marzo 2025', 'hint' => [1 => ['month:3']]]), $session);

        self::assertCount(5, $this->jsonBody());
        self::assertGreaterThan(0, $plain);
        self::assertSame($plain, $searched, 'stesso numero di query con e senza ricerca');
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $expected chiavi di $ids attese (in ordine solo se $ordered)
     * @param array<string, int> $ids
     */
    private function assertSearch(string $url, array $params, array $expected, array $ids, string $session, string $label, bool $ordered = false): void
    {
        $this->jsonRequest('GET', $url . ($params === [] ? '' : '?' . http_build_query($params)), session: $session);
        self::assertResponseIsSuccessful($label);
        $names = array_flip($ids);
        $found = array_map(static fn (mixed $id): string => $names[$id] ?? 'estraneo #' . (\is_scalar($id) ? $id : '?'), array_column($this->jsonBody(), 'id'));
        if (!$ordered) {
            sort($found);
            sort($expected);
        }
        self::assertSame($expected, $found, $label);
    }

    /** Numero di query SQL eseguite da una richiesta (stesso container per tutta la richiesta). */
    private function queriesOf(string $url, string $session): int
    {
        $this->client->disableReboot();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        static::getContainer()->get('doctrine')->getManager()->clear(); // niente entità già in memoria dai factory
        $holder->reset();
        $this->jsonRequest('GET', $url, session: $session);
        self::assertResponseIsSuccessful();

        return \count($holder->getData()['default'] ?? []);
    }

    /** @param array<string, mixed> $attributes */
    private function ownedVehicle(User $owner, Organization $org, array $attributes = []): Vehicle
    {
        $vehicle = VehicleFactory::createOne(['organization' => $org, ...$attributes]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $owner]);

        return $vehicle;
    }

    private function value(User $user, Organization $org, UserValueKind $kind, string $name): UserValue
    {
        return UserValueFactory::createOne(['user' => $user, 'organization' => $org, 'kind' => $kind, 'name' => $name]);
    }

    /** @param array<string, mixed> $attributes */
    private function maintenance(Vehicle $vehicle, string $date, string $description, array $attributes): int
    {
        return (int) MaintenanceFactory::createOne([
            'organization' => $vehicle->getOrganization(),
            'vehicle' => $vehicle,
            'performedAt' => new \DateTimeImmutable($date),
            'description' => $description,
            'cost' => '1.00',
            'km' => 1,
            ...$attributes,
        ])->getId();
    }

    /** @param array<string, mixed> $attributes */
    private function expense(Vehicle $vehicle, string $date, string $description, string $amount, array $attributes): int
    {
        return (int) ExpenseFactory::createOne([
            'organization' => $vehicle->getOrganization(),
            'vehicle' => $vehicle,
            'occurredAt' => new \DateTimeImmutable($date),
            'description' => $description,
            'amount' => $amount,
            ...$attributes,
        ])->getId();
    }

    /** @param array<string, mixed> $attributes */
    private function refueling(Vehicle $vehicle, string $date, int $km, string $liters, string $pricePerLiter, array $attributes): int
    {
        return (int) RefuelingFactory::createOne([
            'organization' => $vehicle->getOrganization(),
            'vehicle' => $vehicle,
            'refueledAt' => new \DateTimeImmutable($date),
            'km' => $km,
            'liters' => $liters,
            'pricePerLiter' => $pricePerLiter,
            ...$attributes,
        ])->getId();
    }

    /** @param array<string, mixed> $attributes */
    private function reminder(Vehicle $vehicle, ReminderType $type, string $description, array $attributes): int
    {
        return (int) ReminderFactory::createOne([
            'organization' => $vehicle->getOrganization(),
            'vehicle' => $vehicle,
            'type' => $type,
            'description' => $description,
            ...$attributes,
        ])->getId();
    }
}
