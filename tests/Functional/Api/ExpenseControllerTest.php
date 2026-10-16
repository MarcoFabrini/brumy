<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\RecurringPeriod;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Support\ApiTestCase;

final class ExpenseControllerTest extends ApiTestCase
{
    public function testCreateExpenseWithRecurringPeriod(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-15',
            'category' => 'insurance',
            'description' => 'Polizza annuale',
            'amount' => '650.00',
            'recurring' => true,
            'recurringPeriod' => 'yearly',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->jsonBody()['recurring']);
        self::assertSame('yearly', $this->jsonBody()['recurringPeriod']);
    }

    public function testCreateExpenseRejectsADateBefore1970(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '1850-01-01',
            'category' => 'insurance',
            'description' => 'Polizza',
            'amount' => '10.00',
            'recurring' => true,
            'recurringPeriod' => 'weekly',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('occurredAt', $this->jsonBody()['errors'][0]['field']);
    }

    public function testCreateNonRecurringExpense(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-02-01',
            'category' => 'fine',
            'description' => 'Multa autovelox',
            'amount' => '85.00',
            'recurring' => false,
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->jsonBody()['recurring']);
    }

    public function testValidateAmountFormat(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-01',
            'category' => 'tax',
            'description' => 'X',
            'amount' => 'abc',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testListReturnsExpensesSeededViaFactory(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        ExpenseFactory::createMany(3, ['organization' => $org, 'vehicle' => $vehicle]);

        $this->jsonRequest('GET', '/api/expenses?vehicleId='.$vehicle->getId(), accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->jsonBody());
    }

    public function testCreateRecurringExpenseWithEndDate(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-15',
            'category' => 'subscription',
            'description' => 'Telepass',
            'amount' => '50.00',
            'recurring' => true,
            'recurringPeriod' => 'monthly',
            'recurringUntil' => '2026-06-15',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-06-15', substr($this->jsonBody()['recurringUntil'], 0, 10));

        $this->jsonRequest('GET', '/api/expenses/'.$this->jsonBody()['id'], accessToken: $token);
        self::assertSame('2026-06-15', substr($this->jsonBody()['recurringUntil'], 0, 10));
    }

    public function testRecurringExpenseWithoutEndDateStaysOpenEnded(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        foreach ([null, ''] as $until) {
            $this->jsonRequest('POST', '/api/expenses', [
                'vehicleId' => $vehicle->getId(),
                'occurredAt' => '2026-01-15',
                'description' => 'Abbonamento',
                'amount' => '9.99',
                'recurring' => true,
                'recurringPeriod' => 'monthly',
                'recurringUntil' => $until,
            ], accessToken: $token);

            self::assertResponseStatusCodeSame(201);
            self::assertNull($this->jsonBody()['recurringUntil']);
        }
    }

    public function testEndDateEqualToStartIsAccepted(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-15',
            'description' => 'Una tantum',
            'amount' => '9.99',
            'recurring' => true,
            'recurringPeriod' => 'yearly',
            'recurringUntil' => '2026-01-15',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
    }

    public function testEndDateBeforeStartIsRejectedOnTheField(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-15',
            'description' => 'Abbonamento',
            'amount' => '9.99',
            'recurring' => true,
            'recurringPeriod' => 'monthly',
            'recurringUntil' => '2026-01-14',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'recurringUntil', 'message' => 'expense.recurring_until_before_start']],
            $this->jsonBody()['errors'],
        );
    }

    public function testEndDateOnNonRecurringExpenseIsRejectedOnTheField(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-15',
            'description' => 'Multa',
            'amount' => '85.00',
            'recurring' => false,
            'recurringUntil' => '2026-06-15',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'recurringUntil', 'message' => 'expense.recurring_until_unexpected']],
            $this->jsonBody()['errors'],
        );
    }

    public function testMalformedEndDateIsRejected(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-15',
            'description' => 'Abbonamento',
            'amount' => '9.99',
            'recurring' => true,
            'recurringPeriod' => 'monthly',
            'recurringUntil' => '15/06/2026',
        ], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('recurringUntil', $this->jsonBody()['errors'][0]['field']);
    }

    public function testUpdateChangesAndClearsTheEndDate(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $expense = ExpenseFactory::createOne([
            'organization' => $org,
            'vehicle' => $vehicle,
            'occurredAt' => new \DateTimeImmutable('2026-01-15'),
            'recurring' => true,
            'recurringPeriod' => RecurringPeriod::MONTHLY,
            'recurringUntil' => new \DateTimeImmutable('2026-03-15'),
        ]);
        $payload = [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-15',
            'description' => 'Abbonamento',
            'amount' => '9.99',
            'recurring' => true,
            'recurringPeriod' => 'monthly',
        ];

        $this->jsonRequest('PUT', '/api/expenses/'.$expense->getId(), $payload + ['recurringUntil' => '2026-09-15'], accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-15', substr($this->jsonBody()['recurringUntil'], 0, 10));

        // Senza data di fine la spesa torna "in corso".
        $this->jsonRequest('PUT', '/api/expenses/'.$expense->getId(), $payload, accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody()['recurringUntil']);
    }

    public function testTurningRecurringOffClearsPeriodAndEndDate(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $expense = ExpenseFactory::createOne([
            'organization' => $org,
            'vehicle' => $vehicle,
            'recurring' => true,
            'recurringPeriod' => RecurringPeriod::MONTHLY,
            'recurringUntil' => new \DateTimeImmutable('2030-01-01'),
        ]);

        $this->jsonRequest('PUT', '/api/expenses/'.$expense->getId(), [
            'vehicleId' => $vehicle->getId(),
            'occurredAt' => '2026-01-15',
            'description' => 'Ora singola',
            'amount' => '9.99',
            'recurring' => false,
        ], accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->jsonBody()['recurring']);
        self::assertNull($this->jsonBody()['recurringPeriod']);
        self::assertNull($this->jsonBody()['recurringUntil']);
    }

    public function testListExposesRecurrenceFieldsForTheCards(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        ExpenseFactory::createOne([
            'organization' => $org,
            'vehicle' => $vehicle,
            'recurring' => true,
            'recurringPeriod' => RecurringPeriod::QUARTERLY,
            'recurringUntil' => new \DateTimeImmutable('2030-01-01'),
        ]);

        $this->jsonRequest('GET', '/api/expenses?vehicleId='.$vehicle->getId(), accessToken: $token);

        self::assertResponseIsSuccessful();
        $item = current($this->jsonBody());
        self::assertIsArray($item);
        self::assertTrue($item['recurring']);
        self::assertSame('quarterly', $item['recurringPeriod']);
        self::assertSame('2030-01-01', substr($item['recurringUntil'], 0, 10));
    }
}
