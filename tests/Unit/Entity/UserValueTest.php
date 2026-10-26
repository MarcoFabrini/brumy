<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserValue;
use App\Enum\UserValueKind;
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

    public function testOnlyALinkedStationFollowsThePriceAndUnlinkingStops(): void
    {
        $station = new UserValue(new User(), new Organization(), UserValueKind::STATION, 'Q8');
        try {
            $station->followPrice(true);
            self::fail('Una voce non collegata non può seguire il prezzo');
        } catch (\LogicException) {
        }

        $station->linkFuelStation(1);
        $station->followPrice(true);
        self::assertTrue($station->followsPrice());

        $station->linkFuelStation(2);
        self::assertTrue($station->followsPrice());
        $station->linkFuelStation(null);
        self::assertFalse($station->followsPrice());
    }
}
