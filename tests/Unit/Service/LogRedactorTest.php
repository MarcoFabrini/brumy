<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LogRedactor;
use PHPUnit\Framework\TestCase;

final class LogRedactorTest extends TestCase
{
    public function testEmailsAndUrlsAreRemovedFromExceptionMessages(): void
    {
        $scrubbed = LogRedactor::scrub('550 5.1.1 <mario.rossi+x@example.test> rejected; POST https://fcm.googleapis.com/fcm/send/abc123 failed');

        self::assertStringNotContainsString('mario.rossi', $scrubbed);
        self::assertStringNotContainsString('fcm.googleapis.com', $scrubbed);
        self::assertStringContainsString('[email]', $scrubbed);
        self::assertStringContainsString('[url]', $scrubbed);
        self::assertStringContainsString('rejected', $scrubbed, 'Il resto del messaggio resta leggibile');
    }

    public function testMessagesWithoutPersonalDataAreUnchanged(): void
    {
        self::assertSame('Connection refused', LogRedactor::scrub('Connection refused'));
    }
}
