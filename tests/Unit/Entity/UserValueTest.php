<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\UserValue;
use PHPUnit\Framework\TestCase;

final class UserValueTest extends TestCase
{
    public function testKeyOfNormalizesCaseAndSpaces(): void
    {
        self::assertSame('q8 via roma', UserValue::keyOf("  Q8   Via\tRoma "));
    }

    public function testKeyOfKeepsAccents(): void
    {
        self::assertNotSame(UserValue::keyOf('Caffè'), UserValue::keyOf('Caffe'));
    }
}
