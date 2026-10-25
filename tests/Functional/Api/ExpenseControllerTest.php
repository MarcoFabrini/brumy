<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\UserValue;
use App\Enum\ExpenseCategory;
use App\Enum\RecurringPeriod;
use App\Enum\TaxType;
use App\Enum\UserValueKind;
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
        // Senza categoria esplicita la factory usa la voce di partenza "Altro"
        $item = current($this->jsonBody());
        self::assertIsArray($item);
        self::assertSame('Altro', $item['category']['name']);
        self::assertIsInt($item['category']['id']);
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
                'category' => 'subscription',
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
            'category' => 'other',
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
            'category' => 'subscription',
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
            'category' => 'fine',
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
            'category' => 'subscription',
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
            'category' => 'subscription',
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
            'category' => 'other',
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

    public function testCreateWithNewCategoryCreatesIt(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', $this->expenseBody($vehicle->getId(), ' Revisione  camper '), accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        $category = $this->jsonBody()['category'];
        self::assertSame('Revisione camper', $category['name']);

        // Veicolo senza proprietario: la voce va nella lista di chi salva
        $value = static::getContainer()->get('doctrine')->getRepository(UserValue::class)->find($category['id']);
        self::assertNotNull($value);
        self::assertSame(UserValueKind::EXPENSE_CATEGORY, $value->getKind());
        self::assertSame($user->getId(), $value->getUser()->getId());
        self::assertNull($value->getStarterKey());

        $this->jsonRequest('GET', '/api/expenses/'.$this->jsonBody()['id'], accessToken: $token);
        self::assertSame(['id' => $category['id'], 'name' => 'Revisione camper'], $this->jsonBody()['category']);
    }

    public function testCreateWithLegacySlugUsesStarter(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser(); // locale 'it'
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', $this->expenseBody($vehicle->getId(), 'toll'), accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Pedaggio', $this->jsonBody()['category']['name']);
        $value = static::getContainer()->get('doctrine')->getRepository(UserValue::class)->find($this->jsonBody()['category']['id']);
        self::assertSame(ExpenseCategory::TOLL->value, $value?->getStarterKey());
    }

    public function testUpdateChangesTheCategory(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $expense = ExpenseFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);

        $this->jsonRequest('PUT', '/api/expenses/'.$expense->getId(), $this->expenseBody($vehicle->getId(), 'Lavaggio interni'), accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('Lavaggio interni', $this->jsonBody()['category']['name']);
    }

    public function testBlankCategoryIs422(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        // Anche gli spazi Unicode (NBSP, tab) contano come vuoto, come per il nome della voce
        foreach (['  ', "\u{00A0}\t"] as $blank) {
            $this->jsonRequest('POST', '/api/expenses', $this->expenseBody($vehicle->getId(), $blank), accessToken: $token);

            self::assertResponseStatusCodeSame(422);
            self::assertSame('category', $this->jsonBody()['errors'][0]['field']);
            self::assertSame('expense.category.required', $this->jsonBody()['errors'][0]['message']);
        }
        self::assertSame([], static::getContainer()->get('doctrine')->getRepository(UserValue::class)->findAll());
    }

    public function testTaxUsesTheTaxTypeListStartingFromTheStarters(): void
    {
        [$user, $org, $token] = $this->createAuthenticatedUser(); // locale 'it'
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [...$this->expenseBody($vehicle->getId(), 'bollo'), 'section' => 'tax'], accessToken: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('tax', $this->jsonBody()['section']);
        self::assertSame('Bollo', $this->jsonBody()['category']['name']);
        self::assertNull($this->jsonBody()['insurer']);
        $value = static::getContainer()->get('doctrine')->getRepository(UserValue::class)->find($this->jsonBody()['category']['id']);
        self::assertSame([UserValueKind::TAX_TYPE, TaxType::ROAD_TAX->value], [$value?->getKind(), $value?->getStarterKey()]);

        $this->jsonRequest('GET', '/api/values/tax_type', accessToken: $token);
        self::assertSame(['Altra tassa', 'Bollo', 'Passaggio di proprietà', 'Superbollo'], array_column($this->jsonBody(), 'name'));
        $this->jsonRequest('GET', '/api/values/expense_category', accessToken: $token);
        self::assertNotContains('Bollo', array_column($this->jsonBody(), 'name'), 'il bollo non è più una categoria delle altre spese');
    }

    public function testTaxWithoutTypeIs422(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [...$this->expenseBody($vehicle->getId(), ' '), 'section' => 'tax'], accessToken: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['field' => 'category', 'message' => 'expense.tax_type.required'], array_intersect_key($this->jsonBody()['errors'][0], ['field' => 1, 'message' => 1]));
    }

    public function testInsuranceHasAnOptionalInsurerAndNoCategory(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->jsonRequest('POST', '/api/expenses', [...$this->expenseBody($vehicle->getId(), ''), 'section' => 'insurance', 'insurer' => ' Allianz '], accessToken: $token);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->jsonBody()['category']);
        self::assertSame('Allianz', $this->jsonBody()['insurer']['name']);
        $id = $this->jsonBody()['id'];

        // Un testo di categoria mandato per sbaglio con l'assicurazione non crea nulla
        $this->jsonRequest('PUT', '/api/expenses/'.$id, [...$this->expenseBody($vehicle->getId(), 'Pedaggio'), 'section' => 'insurance'], accessToken: $token);
        self::assertResponseIsSuccessful();
        self::assertNull($this->jsonBody()['category']);
        self::assertNull($this->jsonBody()['insurer']);

        $this->jsonRequest('GET', '/api/vehicles/'.$vehicle->getId().'/values/insurer', accessToken: $token);
        self::assertSame(['Allianz'], array_column($this->jsonBody(), 'name'));
    }

    public function testSwitchingSectionDoesNotKeepAValueOfAnotherList(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $this->jsonRequest('POST', '/api/expenses', $this->expenseBody($vehicle->getId(), 'Varie'), accessToken: $token);
        $other = $this->jsonBody()['category'];

        $this->jsonRequest('PUT', '/api/expenses/'.$this->jsonBody()['id'], [...$this->expenseBody($vehicle->getId(), 'Varie'), 'section' => 'tax'], accessToken: $token);

        self::assertResponseIsSuccessful();
        self::assertNotSame($other['id'], $this->jsonBody()['category']['id']);
        $value = static::getContainer()->get('doctrine')->getRepository(UserValue::class)->find($this->jsonBody()['category']['id']);
        self::assertSame(UserValueKind::TAX_TYPE, $value?->getKind());
    }

    public function testListFiltersBySection(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        foreach ([['tax', 'Bollo', null], ['insurance', '', 'Allianz'], ['other', 'Pedaggio', null]] as [$section, $category, $insurer]) {
            $this->jsonRequest('POST', '/api/expenses', [...$this->expenseBody($vehicle->getId(), $category), 'section' => $section, 'insurer' => $insurer], accessToken: $token);
            self::assertResponseStatusCodeSame(201);
        }

        foreach (['tax' => 'tax', 'insurance' => 'insurance', 'other' => 'other'] as $query => $expected) {
            $this->jsonRequest('GET', '/api/expenses?vehicleId='.$vehicle->getId().'&section='.$query, accessToken: $token);
            self::assertSame([$expected], array_column($this->jsonBody(), 'section'));
        }
        $this->jsonRequest('GET', '/api/expenses?vehicleId='.$vehicle->getId(), accessToken: $token);
        self::assertCount(3, $this->jsonBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseBody(?int $vehicleId, string $category): array
    {
        return [
            'vehicleId' => $vehicleId,
            'occurredAt' => '2026-02-01',
            'category' => $category,
            'description' => 'Spesa',
            'amount' => '20.00',
        ];
    }
}
