<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ExpenseCategory;
use PHPUnit\Framework\TestCase;

final class ExpenseCategoryTest extends TestCase
{
    public function testLabels(): void
    {
        self::assertSame('Pedaggio', ExpenseCategory::TOLL->label('it'));
        self::assertSame('Toll', ExpenseCategory::TOLL->label('en'));
        self::assertSame('Autolavaggio', ExpenseCategory::CAR_WASH->label('fr'));

        foreach (ExpenseCategory::cases() as $case) {
            self::assertNotSame('', $case->label('it'));
            self::assertNotSame('', $case->label('en'));
        }
    }
}
