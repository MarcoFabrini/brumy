<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Push;

use App\Service\Push\PushEndpointPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushEndpointPolicyTest extends TestCase
{
    #[DataProvider('allowed')]
    public function testRealPushServicesAreAllowed(string $endpoint): void
    {
        self::assertTrue(PushEndpointPolicy::isAllowed($endpoint));
    }

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'FCM (Chrome, Edge, Android)' => ['https://fcm.googleapis.com/fcm/send/abc'];
        yield 'FCM, host in maiuscolo' => ['https://FCM.GoogleAPIs.com/wp/abc'];
        yield 'Mozilla autopush (Firefox)' => ['https://updates.push.services.mozilla.com/wpush/v2/abc'];
        yield 'WNS' => ['https://wns2-par02p.notify.windows.com/w/?token=abc'];
        yield 'Apple (Safari)' => ['https://web.push.apple.com/abc'];
        yield 'porta 443 esplicita' => ['https://fcm.googleapis.com:443/fcm/send/abc'];
    }

    #[DataProvider('rejected')]
    public function testEverythingElseIsRejected(?string $endpoint): void
    {
        self::assertFalse(PushEndpointPolicy::isAllowed($endpoint));
    }

    /** @return iterable<string, array{string|null}> */
    public static function rejected(): iterable
    {
        yield 'null' => [null];
        yield 'vuoto' => [''];
        yield 'altro sottodominio googleapis' => ['https://storage.googleapis.com/bucket/obj'];
        yield 'dominio googleapis nudo' => ['https://googleapis.com/x'];
        yield 'android.googleapis.com legacy (GCM)' => ['https://android.googleapis.com/gcm/send/abc'];
        yield 'altro host mozilla' => ['https://evil.push.services.mozilla.com/x'];
        yield 'mozilla senza il sottodominio updates' => ['https://push.services.mozilla.com/x'];
        yield 'suffisso senza confine di punto (windows)' => ['https://evilnotify.windows.com/x'];
        yield 'suffisso senza confine di punto (apple)' => ['https://evilpush.apple.com/x'];
        yield 'suffisso senza confine di punto (google)' => ['https://evilfcm.googleapis.com/x'];
        yield 'dominio nudo notify.windows.com' => ['https://notify.windows.com/x'];
        yield 'http' => ['http://fcm.googleapis.com/fcm/send/1'];
        yield 'schema assente' => ['//fcm.googleapis.com/fcm/send/1'];
        yield 'user e pass' => ['https://user:pass@fcm.googleapis.com/fcm/send/1'];
        yield 'solo user' => ['https://user@fcm.googleapis.com/fcm/send/1'];
        yield 'solo pass' => ['https://:pass@fcm.googleapis.com/fcm/send/1'];
        yield 'host ammesso come userinfo' => ['https://fcm.googleapis.com@evil.test/x'];
        yield 'porta diversa' => ['https://fcm.googleapis.com:8443/x'];
        yield 'host con punto finale' => ['https://fcm.googleapis.com./x'];
        yield 'rete interna' => ['https://redis:6379/'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'senza host' => ['https:///fcm.googleapis.com'];
        yield 'host con backslash' => ['https://evil.test\\.fcm.googleapis.com/x'];
        yield 'host con percent-encoding' => ['https://fcm%2egoogleapis.com/x'];
        yield 'host con caratteri non DNS' => ['https://fcm_x.googleapis.com/x'];
    }
}
