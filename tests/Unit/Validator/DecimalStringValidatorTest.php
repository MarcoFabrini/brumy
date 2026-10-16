<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Validator\DecimalString;
use App\Validator\DecimalStringValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<DecimalStringValidator> */
final class DecimalStringValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): DecimalStringValidator
    {
        return new DecimalStringValidator();
    }

    #[DataProvider('validValuesProvider')]
    public function testValidValues(string $value, int $maxDecimals): void
    {
        $this->validator->validate($value, new DecimalString(maxDecimals: $maxDecimals));
        $this->assertNoViolation();
    }

    /** @return iterable<string, array{string, int}> */
    public static function validValuesProvider(): iterable
    {
        yield 'integer' => ['120', 2];
        yield 'exact decimals' => ['120.50', 2];
        yield 'fewer decimals than max' => ['120.5', 2];
        yield 'four decimals allowed' => ['1.2345', 4];
    }

    #[DataProvider('invalidValuesProvider')]
    public function testInvalidValues(string $value, int $maxDecimals): void
    {
        $this->validator->validate($value, new DecimalString(maxDecimals: $maxDecimals, message: 'validation.amount_format'));
        $this->buildViolation('validation.amount_format')->assertRaised();
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidValuesProvider(): iterable
    {
        yield 'too many decimals' => ['120.500', 2];
        yield 'trailing newline' => ["12.5\n", 2];
        yield 'negative' => ['-1', 2];
        yield 'comma separator' => ['120,50', 2];
        yield 'not numeric' => ['abc', 2];
        yield 'thousands separator' => ['1,200.50', 2];
        yield 'zero by default' => ['0', 2];
        yield 'zero with decimals' => ['0.00', 2];
        yield 'empty string' => ['', 2];
    }

    public function testZeroIsAllowedWhenExplicitlyEnabled(): void
    {
        $this->validator->validate('0', new DecimalString(allowZero: true));
        $this->assertNoViolation();
    }

    public function testNullIsSkippedButEmptyStringIsNotADecimal(): void
    {
        $this->validator->validate(null, new DecimalString());
        $this->assertNoViolation();

        $this->validator->validate('', new DecimalString(message: 'validation.amount_format'));
        $this->buildViolation('validation.amount_format')->assertRaised();
    }

    #[DataProvider('tooLargeProvider')]
    public function testValuesLargerThanTheColumnAreRejected(string $value, int $maxIntegerDigits): void
    {
        $this->validator->validate($value, new DecimalString(maxIntegerDigits: $maxIntegerDigits, message: 'validation.amount_format'));
        $this->buildViolation('validation.amount_format')->assertRaised();
    }

    /** @return iterable<string, array{string, int}> */
    public static function tooLargeProvider(): iterable
    {
        yield 'liters over NUMERIC(8,3)' => ['100000.000', 5];
        yield 'price over NUMERIC(6,4)' => ['100.0000', 2];
        yield 'amount over NUMERIC(10,2)' => ['123456789.00', 8];
    }
}
