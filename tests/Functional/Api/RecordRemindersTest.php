<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Reminder;
use App\Enum\ReminderType;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;

/** Revisione, assicurazione e bollo salvati con la prossima scadenza aggiornano o creano il promemoria. */
final class RecordRemindersTest extends ApiTestCase
{
    public function testInspectionCreatesARecurringReminderWhenThereIsNone(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/maintenances', [
            'vehicleId' => $vehicle->getId(), 'performedAt' => '2026-05-10', 'km' => 80000, 'category' => 'inspection',
            'description' => 'Revisione biennale', 'nextDueDate' => '2028-05-31',
        ], session: $token);
        self::assertResponseStatusCodeSame(201);

        $reminders = $this->reminders();
        self::assertCount(1, $reminders);
        self::assertSame([ReminderType::INSPECTION, 'Revisione biennale', '2028-05-31', 24, null], [
            $reminders[0]->getType(), $reminders[0]->getDescription(), $reminders[0]->getDueDate()?->format('Y-m-d'),
            $reminders[0]->getRecurrenceMonths(), $reminders[0]->getCompletedAt(),
        ]);
    }

    public function testInsuranceCompletesTheOpenReminderAndMovesTheNextOne(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);
        $open = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle, 'type' => ReminderType::INSURANCE, 'description' => 'RCA',
            'dueDate' => new \DateTimeImmutable('2026-06-01'), 'recurrenceMonths' => 12,
        ]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(), 'occurredAt' => '2026-05-28', 'section' => 'insurance', 'insurer' => 'Allianz',
            'description' => 'Polizza 2026', 'amount' => '480.00', 'recurring' => true, 'recurringPeriod' => 'semiannual',
            'nextDueDate' => '2026-11-30',
        ], session: $token);
        self::assertResponseStatusCodeSame(201);

        $reminders = $this->reminders();
        self::assertCount(2, $reminders, 'il vecchio completato più il successivo, nessun doppione');
        self::assertSame($open->getId(), $reminders[0]->getId());
        self::assertSame('2026-05-28', $reminders[0]->getCompletedOn()?->format('Y-m-d'));
        self::assertSame(['RCA', '2026-11-30', 12, null], [
            $reminders[1]->getDescription(), $reminders[1]->getDueDate()?->format('Y-m-d'),
            $reminders[1]->getRecurrenceMonths(), $reminders[1]->getCompletedAt(),
        ], 'la data scelta vince su quella calcolata; la ricorrenza resta quella del promemoria');
    }

    public function testRoadTaxAndSuperTaxAreSeparateReminders(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $tax = fn (string $type, string $due) => $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(), 'occurredAt' => '2026-01-31', 'section' => 'tax', 'category' => $type,
            'description' => $type.' 2026', 'amount' => '200.00', 'nextDueDate' => $due,
        ], session: $token);

        $tax('Bollo', '2027-01-31');
        $tax('Superbollo', '2027-01-31');
        $tax('Passaggio di proprietà', '2027-01-31'); // non periodica: nessun promemoria

        $reminders = $this->reminders();
        self::assertSame([[ReminderType::ROAD_TAX, 'Bollo', 12], [ReminderType::CUSTOM, 'Superbollo', 12]], array_map(
            static fn (Reminder $r): array => [$r->getType(), $r->getDescription(), $r->getRecurrenceMonths()],
            $reminders,
        ));
    }

    public function testWithoutNextDueDateOrOnUpdateNothingHappens(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $body = ['vehicleId' => $vehicle->getId(), 'performedAt' => '2026-05-10', 'km' => 80000, 'category' => 'inspection', 'description' => 'Revisione'];

        $this->jsonRequest('POST', '/api/maintenances', $body, session: $token);
        $this->jsonRequest('PUT', '/api/maintenances/'.$this->jsonBody()['id'], [...$body, 'nextDueDate' => '2028-05-31'], session: $token);
        $this->jsonRequest('POST', '/api/maintenances', [...$body, 'category' => 'scheduled', 'nextDueDate' => '2028-05-31'], session: $token);

        self::assertSame([], $this->reminders());
    }

    /** @return list<Reminder> */
    private function reminders(): array
    {
        /** @var list<Reminder> */
        return static::getContainer()->get('doctrine')->getRepository(Reminder::class)->findBy([], ['id' => 'ASC']);
    }
}
