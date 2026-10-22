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

    /** Il motivo di un invio push rifiutato (messaggio di Guzzle) cita l'endpoint intero, che è il token del dispositivo. */
    public function testThePushEndpointInARejectedDeliveryReasonIsRemoved(): void
    {
        $scrubbed = LogRedactor::scrub('Client error: `POST https://fcm.googleapis.com/fcm/send/dEviCeToKeN:APA91b` resulted in a `404 Not Found` response');

        self::assertStringNotContainsString('dEviCeToKeN', $scrubbed);
        self::assertStringContainsString('404 Not Found', $scrubbed);
    }

    public function testMessagesWithoutPersonalDataAreUnchanged(): void
    {
        self::assertSame('Connection refused', LogRedactor::scrub('Connection refused'));
    }
}
