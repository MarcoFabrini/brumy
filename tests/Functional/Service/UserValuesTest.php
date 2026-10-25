<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Expense;
use App\Entity\Maintenance;
use App\Entity\Refueling;
use App\Entity\UserValue;
use App\Entity\Vehicle;
use App\Enum\ExpenseCategory;
use App\Enum\UserValueKind;
use App\Repository\UserValueRepository;
use App\Repository\VehicleShareRepository;
use App\Service\UserValueNameTakenException;
use App\Service\UserValues;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserValueFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UserValuesTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private function values(): UserValues
    {
        self::bootKernel();

        return new UserValues(
            static::getContainer()->get(EntityManagerInterface::class),
            $this->repo(),
            static::getContainer()->get(VehicleShareRepository::class),
        );
    }

    private function repo(): UserValueRepository
    {
        return static::getContainer()->get(UserValueRepository::class);
    }

    public function testFindOrCreateReusesSameKey(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();

        $a = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Q8 Via Roma');
        $b = $values->findOrCreate($user, $org, UserValueKind::STATION, ' q8  via roma');

        self::assertSame($a->getId(), $b->getId());
        self::assertSame('Q8 Via Roma', $b->getName());
        self::assertCount(1, $this->repo()->findAll());
    }

    public function testFindOrCreateSeparatesUsersOrgsAndKinds(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $org2 = OrganizationFactory::createOne();

        $base = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Eni');
        $ids = [
            $base->getId(),
            $values->findOrCreate($other, $org, UserValueKind::STATION, 'Eni')->getId(),
            $values->findOrCreate($user, $org2, UserValueKind::STATION, 'Eni')->getId(),
            $values->findOrCreate($user, $org, UserValueKind::WORKSHOP, 'Eni')->getId(),
        ];

        self::assertCount(4, array_unique($ids));
    }

    public function testEnsureStarterCategoriesUsesUserLocaleOnce(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne(['locale' => 'en']);
        $org = OrganizationFactory::createOne();

        $values->ensureStarters($user, $org, UserValueKind::EXPENSE_CATEGORY);
        $values->ensureStarters($user, $org, UserValueKind::EXPENSE_CATEGORY);

        $all = $this->repo()->findBy(['user' => $user, 'organization' => $org, 'kind' => UserValueKind::EXPENSE_CATEGORY]);
        self::assertCount(\count(ExpenseCategory::starters()), $all);
        self::assertContains('Toll', array_map(static fn (UserValue $v) => $v->getName(), $all));
    }

    public function testEnsureStarterCategoriesToleratesAnExistingSameKeyRow(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        // Riga con la stessa chiave di una categoria di partenza, inserita "da un'altra richiesta" fuori dall'ORM
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement(
            "INSERT INTO user_values (user_id, organization_id, kind, name, name_key, created_at) VALUES (?, ?, 'expense_category', 'Pedaggio', 'pedaggio', NOW())",
            [$user->getId(), $org->getId()],
        );

        $values->ensureStarters($user, $org, UserValueKind::EXPENSE_CATEGORY);

        $all = $this->repo()->findBy(['user' => $user, 'organization' => $org, 'kind' => UserValueKind::EXPENSE_CATEGORY]);
        self::assertCount(1, $all, 'lista non vuota: nessuna categoria di partenza');
        $pedaggio = $this->repo()->findOneInList($user, $org, UserValueKind::EXPENSE_CATEGORY, 'pedaggio');
        self::assertNull($pedaggio?->getStarterKey(), 'la voce omonima fatta a mano non viene sovrascritta');
    }

    public function testEnsureStarterCategoriesSeedsOnlyAnEmptyCategoryList(): void
    {
        $values = $this->values();
        $org = OrganizationFactory::createOne();
        $custom = UserFactory::createOne();
        UserValueFactory::createOne(['user' => $custom, 'organization' => $org, 'kind' => UserValueKind::EXPENSE_CATEGORY, 'name' => 'Lavaggio']);
        $empty = UserFactory::createOne();

        $values->ensureStarters($custom, $org, UserValueKind::EXPENSE_CATEGORY);
        $values->ensureStarters($empty, $org, UserValueKind::EXPENSE_CATEGORY);

        self::assertCount(1, $this->repo()->findBy(['user' => $custom, 'kind' => UserValueKind::EXPENSE_CATEGORY]));
        self::assertCount(\count(ExpenseCategory::starters()), $this->repo()->findBy(['user' => $empty, 'kind' => UserValueKind::EXPENSE_CATEGORY]));
    }

    public function testFindOrCreateCategoryInAnEmptyListAddsTheStartersFirst(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();

        $camper = $values->findOrCreate($user, $org, UserValueKind::EXPENSE_CATEGORY, 'Revisione camper');
        $toll = $values->findOrCreate($user, $org, UserValueKind::EXPENSE_CATEGORY, 'pedaggio');

        self::assertNull($camper->getStarterKey());
        self::assertSame(ExpenseCategory::TOLL->value, $toll->getStarterKey(), 'il nome di una categoria di partenza trova la voce di partenza');
        self::assertCount(\count(ExpenseCategory::starters()) + 1, $this->repo()->findBy(['user' => $user, 'organization' => $org, 'kind' => UserValueKind::EXPENSE_CATEGORY]));
        self::assertCount(0, $this->repo()->findBy(['user' => $user, 'organization' => $org, 'kind' => UserValueKind::STATION]));
    }

    public function testEnsureStarterCategoriesLeavesTheFlushToTheCaller(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $eni = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Eni');
        $eni->rename('Eni Pending'); // modifica non ancora salvata del chiamante

        $values->ensureStarters($user, $org, UserValueKind::EXPENSE_CATEGORY);

        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('Eni', $conn->fetchOne('SELECT name FROM user_values WHERE id = ?', [$eni->getId()]));
    }

    public function testRenameToCaseVariantIsAllowed(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $value = $values->findOrCreate($user, $org, UserValueKind::STATION, 'q8 via roma');

        $values->rename($value, 'Q8 Via Roma');

        self::assertSame('Q8 Via Roma', $value->getName());
        self::assertSame('q8 via roma', $value->getNameKey());
    }

    public function testRenameToOtherEntryNameThrows(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $values->findOrCreate($user, $org, UserValueKind::STATION, 'Eni');
        $value = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Q8');

        $this->expectException(UserValueNameTakenException::class);
        $values->rename($value, ' ENI ');
    }

    public function testMergeRejectsDifferentKindOrOwner(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $from = $values->findOrCreate($user, $org, UserValueKind::STATION, 'A');

        $cases = [
            $values->findOrCreate($user, $org, UserValueKind::WORKSHOP, 'B'),
            $values->findOrCreate($other, $org, UserValueKind::STATION, 'C'),
            $values->findOrCreate($user, OrganizationFactory::createOne(), UserValueKind::STATION, 'D'),
            $from,
        ];
        foreach ($cases as $into) {
            try {
                $values->merge($from, $into);
                self::fail('Doveva lanciare');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertNotNull($this->repo()->find($from->getId()));
    }

    public function testMergeMovesRefuelingsMaintenancesAndExpenses(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $fromStation = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Q8 v. Roma');
        $intoStation = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Q8 Via Roma');
        $fromWorkshop = $values->findOrCreate($user, $org, UserValueKind::WORKSHOP, 'Rossi');
        $intoWorkshop = $values->findOrCreate($user, $org, UserValueKind::WORKSHOP, 'Officina Rossi');
        $fromCategory = $values->findOrCreate($user, $org, UserValueKind::EXPENSE_CATEGORY, 'Telepas');
        $intoCategory = $values->findOrCreate($user, $org, UserValueKind::EXPENSE_CATEGORY, 'Telepass');
        $refuelings = RefuelingFactory::createMany(2, ['vehicle' => $vehicle, 'organization' => $org, 'station' => $fromStation]);
        $untouched = RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => $intoStation]);
        $maintenance = MaintenanceFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'workshop' => $fromWorkshop]);
        $expense = ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'category' => $fromCategory]);
        $fromStationId = $fromStation->getId();
        $fromWorkshopId = $fromWorkshop->getId();
        $fromCategoryId = $fromCategory->getId();

        $values->merge($fromStation, $intoStation);
        $values->merge($fromWorkshop, $intoWorkshop);
        $values->merge($fromCategory, $intoCategory);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ([...$refuelings, $untouched] as $r) {
            self::assertSame($intoStation->getId(), $em->find(Refueling::class, $r->getId())?->getStation()?->getId());
        }
        self::assertSame($intoWorkshop->getId(), $em->find(Maintenance::class, $maintenance->getId())?->getWorkshop()?->getId());
        self::assertSame($intoCategory->getId(), $em->find(Expense::class, $expense->getId())?->getCategory()?->getId());
        self::assertNull($this->repo()->find($fromStationId));
        self::assertNull($this->repo()->find($fromWorkshopId));
        self::assertNull($this->repo()->find($fromCategoryId));
    }

    public function testDeleteIsSoftEvenForUsedValues(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $station = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Eni');
        $refueling = RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => $station]);

        $values->delete($station);

        self::assertTrue($station->isDeleted());
        self::assertNull($station->getNameKey());
        self::assertSame([], $this->repo()->findWithUsage($user, $org, UserValueKind::STATION));
        self::assertNull($this->repo()->findOneOwnedBy((int) $station->getId(), $user, $org));
        self::assertSame($station->getId(), $refueling->getStation()?->getId());
        $again = $values->findOrCreate($user, $org, UserValueKind::STATION, 'eni');
        self::assertNotSame($station->getId(), $again->getId(), 'il nome è di nuovo libero');
        self::assertFalse($again->isDeleted());
    }

    public function testResolveKeepsTheCurrentDeletedValueWhenTheNameIsUnchanged(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        $station = $values->findOrCreate($user, $org, UserValueKind::STATION, 'Eni');
        $values->delete($station);

        self::assertSame($station, $values->resolve($vehicle, $user, UserValueKind::STATION, ' ENI ', $station));
        self::assertNotSame($station, $values->resolve($vehicle, $user, UserValueKind::STATION, 'Eni'), 'senza voce corrente ne crea una nuova');
        self::assertNull($values->resolve($vehicle, $user, UserValueKind::STATION, '', $station));
    }

    public function testLegacySlugOfADeletedStarterCreatesANewCategoryWithItsLabel(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne(['locale' => 'it']);
        $org = OrganizationFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        $values->ensureStarters($user, $org, UserValueKind::EXPENSE_CATEGORY);
        $toll = $this->repo()->findStarter($user, $org, UserValueKind::EXPENSE_CATEGORY, ExpenseCategory::TOLL->value);
        self::assertNotNull($toll);
        $values->delete($toll);

        $resolved = $values->resolve($vehicle, $user, UserValueKind::EXPENSE_CATEGORY, 'toll');

        self::assertNotNull($resolved);
        self::assertNotSame($toll->getId(), $resolved->getId());
        self::assertSame(ExpenseCategory::TOLL->label('it'), $resolved->getName());
    }

    public function testResolveUsesVehicleOwnerList(): void
    {
        $values = $this->values();
        $org = OrganizationFactory::createOne();
        $anna = UserFactory::createOne();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $anna]);

        $value = $values->resolve($vehicle, $mario, UserValueKind::STATION, 'Eni');

        self::assertNotNull($value);
        self::assertSame($anna->getId(), $value->getUser()->getId());
        self::assertSame($org->getId(), $value->getOrganization()->getId());
    }

    public function testResolveOnOrphanVehicleUsesActor(): void
    {
        $values = $this->values();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne();

        $value = $values->resolve($vehicle, $mario, UserValueKind::WORKSHOP, 'Rossi');

        self::assertNotNull($value);
        self::assertSame($mario->getId(), $value->getUser()->getId());
        self::assertSame($mario->getId(), $values->listOwner($vehicle, $mario)->getId());
    }

    public function testResolveBlankReturnsNull(): void
    {
        $values = $this->values();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne();

        self::assertNull($values->resolve($vehicle, $mario, UserValueKind::STATION, ''));
        self::assertNull($values->resolve($vehicle, $mario, UserValueKind::STATION, '   '));
        self::assertNull($values->resolve($vehicle, $mario, UserValueKind::STATION, null));
        self::assertCount(0, $this->repo()->findAll());
    }

    public function testResolveLegacySlugMapsToStarter(): void
    {
        $values = $this->values();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne();

        $first = $values->resolve($vehicle, $mario, UserValueKind::EXPENSE_CATEGORY, 'toll');
        self::assertNotNull($first);
        self::assertSame(ExpenseCategory::TOLL->value, $first->getStarterKey());

        $values->rename($first, 'Pedaggi A1');
        $again = $values->resolve($vehicle, $mario, UserValueKind::EXPENSE_CATEGORY, 'toll');

        self::assertNotNull($again);
        self::assertSame($first->getId(), $again->getId());
        self::assertSame('Pedaggi A1', $again->getName());
    }

    public function testCreateWhenRowAppearedMeanwhile(): void
    {
        $values = $this->values();
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            "INSERT INTO user_values (user_id, organization_id, kind, name, name_key, created_at) VALUES (:u, :o, 'station', 'Q8 Via Roma', 'q8 via roma', NOW())",
            ['u' => $user->getId(), 'o' => $org->getId()],
        );
        $id = (int) $em->getConnection()->lastInsertId();

        $found = $values->findOrCreate($user, $org, UserValueKind::STATION, 'q8 via roma');

        self::assertSame($id, $found->getId());
        self::assertCount(1, $this->repo()->findAll());
    }

    public function testRehomeRepointsAllKindsIntoTheNewOwnerList(): void
    {
        $values = $this->values();
        $org = OrganizationFactory::createOne();
        $anna = UserFactory::createOne();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $eni = $values->findOrCreate($anna, $org, UserValueKind::STATION, 'Eni');
        $rossi = $values->findOrCreate($anna, $org, UserValueKind::WORKSHOP, 'Officina Rossi');
        $telepass = $values->findOrCreate($anna, $org, UserValueKind::EXPENSE_CATEGORY, 'Telepass');
        // Mario ha già "ENI" (stesso nome a meno di maiuscole e spazi): si riusa, non si duplica
        $marioEni = $values->findOrCreate($mario, $org, UserValueKind::STATION, ' ENI ');
        $marioQ8 = $values->findOrCreate($mario, $org, UserValueKind::STATION, 'Q8');
        $onEni = RefuelingFactory::createMany(2, ['vehicle' => $vehicle, 'organization' => $org, 'station' => $eni]);
        $alreadyMario = RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => $marioQ8]);
        $noStation = RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => null]);
        $otherVehicle = RefuelingFactory::createOne(['organization' => $org, 'station' => $eni]);
        $bianchi = $values->findOrCreate($anna, $org, UserValueKind::SHOP, 'Ricambi Bianchi');
        $maintenance = MaintenanceFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'workshop' => $rossi, 'shop' => $bianchi]);
        $expense = ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'category' => $telepass]);

        $values->rehome($vehicle, $mario);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $em->clear();

        foreach ($onEni as $r) {
            self::assertSame($marioEni->getId(), $em->find(Refueling::class, $r->getId())?->getStation()?->getId());
        }
        self::assertSame($marioQ8->getId(), $em->find(Refueling::class, $alreadyMario->getId())?->getStation()?->getId());
        self::assertNull($em->find(Refueling::class, $noStation->getId())?->getStation());
        self::assertSame($eni->getId(), $em->find(Refueling::class, $otherVehicle->getId())?->getStation()?->getId(), 'Gli altri veicoli non si toccano');
        $workshop = $em->find(Maintenance::class, $maintenance->getId())?->getWorkshop();
        self::assertSame([$mario->getId(), 'Officina Rossi'], [$workshop?->getUser()->getId(), $workshop?->getName()]);
        $shop = $em->find(Maintenance::class, $maintenance->getId())?->getShop();
        self::assertSame([$mario->getId(), 'Ricambi Bianchi', UserValueKind::SHOP], [$shop?->getUser()->getId(), $shop?->getName(), $shop?->getKind()]);
        $category = $em->find(Expense::class, $expense->getId())?->getCategory();
        self::assertSame([$mario->getId(), 'Telepass'], [$category?->getUser()->getId(), $category?->getName()]);
        // La lista di Anna resta com'era
        self::assertNotNull($this->repo()->find($eni->getId()));
        self::assertNotNull($this->repo()->find($rossi->getId()));
        self::assertNotNull($this->repo()->find($telepass->getId()));
    }

    public function testRehomeMapsStarterByKeyEvenWhenRenamed(): void
    {
        $values = $this->values();
        $org = OrganizationFactory::createOne();
        $anna = UserFactory::createOne();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $values->ensureStarters($anna, $org, UserValueKind::EXPENSE_CATEGORY);
        $values->ensureStarters($mario, $org, UserValueKind::EXPENSE_CATEGORY);
        $annaToll = $this->repo()->findOneBy(['user' => $anna, 'organization' => $org, 'starterKey' => ExpenseCategory::TOLL->value]);
        $marioToll = $this->repo()->findOneBy(['user' => $mario, 'organization' => $org, 'starterKey' => ExpenseCategory::TOLL->value]);
        self::assertNotNull($annaToll);
        self::assertNotNull($marioToll);
        $values->rename($marioToll, 'Autostrade');
        $expense = ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'category' => $annaToll]);

        $values->rehome($vehicle, $mario);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $em->clear();

        self::assertSame($marioToll->getId(), $em->find(Expense::class, $expense->getId())?->getCategory()?->getId());
        self::assertNull($this->repo()->findOneInList($mario, $org, UserValueKind::EXPENSE_CATEGORY, 'pedaggio'));
    }

    public function testRehomeGivesTheNewOwnerADeletedCopyOfADeletedValue(): void
    {
        $values = $this->values();
        $org = OrganizationFactory::createOne();
        $anna = UserFactory::createOne();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $eni = $values->findOrCreate($anna, $org, UserValueKind::STATION, 'Eni');
        $refueling = RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => $eni]);
        $values->delete($eni);
        $marioEni = $values->findOrCreate($mario, $org, UserValueKind::STATION, 'Eni');

        $values->rehome($vehicle, $mario);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $em->clear();

        $station = $em->find(Refueling::class, $refueling->getId())?->getStation();
        self::assertNotNull($station);
        self::assertSame([$mario->getId(), 'Eni', true], [$station->getUser()->getId(), $station->getName(), $station->isDeleted()]);
        self::assertNotSame($marioEni->getId(), $station->getId(), 'non finisce sulla voce attiva omonima');
        self::assertSame(['Eni'], array_column($this->repo()->findWithUsage($mario, $org, UserValueKind::STATION), 'name'));
    }

    public function testRehomeLeavesTheFlushToTheCaller(): void
    {
        $values = $this->values();
        $org = OrganizationFactory::createOne();
        $anna = UserFactory::createOne();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $eni = $values->findOrCreate($anna, $org, UserValueKind::STATION, 'Eni');
        $refueling = RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => $eni, 'km' => 1000]);
        $refueling->setKm(1234); // modifica non ancora salvata del chiamante

        $values->rehome($vehicle, $mario);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $row = static fn (): array|false => $conn->fetchAssociative('SELECT km, station_id FROM refuelings WHERE id = ?', [$refueling->getId()]);
        self::assertSame([1000, $eni->getId()], array_map('intval', array_values((array) $row())));
        $em->flush();
        $marioEni = $this->repo()->findOneInList($mario, $org, UserValueKind::STATION, 'eni');
        self::assertNotNull($marioEni);
        self::assertSame([1234, $marioEni->getId()], array_map('intval', array_values((array) $row())));
    }

    public function testRehomeIsNoOpForUnsavedVehicle(): void
    {
        $values = $this->values();
        $org = OrganizationFactory::createOne();
        $mario = UserFactory::createOne();

        $values->rehome((new Vehicle())->setOrganization($org), $mario);

        self::assertCount(0, $this->repo()->findAll());
    }

    public function testDeleteUnusedForUserInOrganizationKeepsUsedAndForeignEntries(): void
    {
        $values = $this->values();
        $org = OrganizationFactory::createOne();
        $otherOrg = OrganizationFactory::createOne();
        $anna = UserFactory::createOne();
        $mario = UserFactory::createOne();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $usedStation = $values->findOrCreate($anna, $org, UserValueKind::STATION, 'Eni');
        $usedWorkshop = $values->findOrCreate($anna, $org, UserValueKind::WORKSHOP, 'Rossi');
        $usedCategory = $values->findOrCreate($anna, $org, UserValueKind::EXPENSE_CATEGORY, 'Telepass');
        $unused = [
            $values->findOrCreate($anna, $org, UserValueKind::STATION, 'Q8'),
            $values->findOrCreate($anna, $org, UserValueKind::WORKSHOP, 'Bianchi'),
            $values->findOrCreate($anna, $org, UserValueKind::EXPENSE_CATEGORY, 'Lavaggio'),
        ];
        $foreign = [
            $values->findOrCreate($anna, $otherOrg, UserValueKind::STATION, 'Tamoil'),
            $values->findOrCreate($mario, $org, UserValueKind::STATION, 'Shell'),
        ];
        RefuelingFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'station' => $usedStation]);
        MaintenanceFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'workshop' => $usedWorkshop]);
        ExpenseFactory::createOne(['vehicle' => $vehicle, 'organization' => $org, 'category' => $usedCategory]);
        $unusedIds = array_map(static fn (UserValue $v): ?int => $v->getId(), $unused);

        // le 3 voci inutilizzate + le categorie di partenza arrivate con la prima categoria di Anna
        self::assertSame(3 + \count(ExpenseCategory::starters()), $this->repo()->deleteUnusedForUserInOrganization($anna, $org));

        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $exists = static fn (?int $id): bool => (bool) $conn->fetchOne('SELECT 1 FROM user_values WHERE id = ?', [$id]);
        foreach ($unusedIds as $id) {
            self::assertFalse($exists($id));
        }
        foreach ([$usedStation, $usedWorkshop, $usedCategory, ...$foreign] as $kept) {
            self::assertTrue($exists($kept->getId()));
        }
    }
}
