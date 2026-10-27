<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\OrgRole;
use App\Service\AppUpdates;
use App\Tests\Support\ApiTestCase;
use Psr\Cache\CacheItemPoolInterface;

final class AboutControllerTest extends ApiTestCase
{
    /** Come dopo il job giornaliero: GitHub ha una versione più nuova di quella installata. */
    private function newerReleaseKnown(): string
    {
        $updates = static::getContainer()->get(AppUpdates::class);
        self::assertInstanceOf(AppUpdates::class, $updates);
        [$major, $minor] = array_map('intval', explode('.', $updates->currentVersion()));
        $newer = sprintf('%d.%d.0', $major, $minor + 1);

        $cache = static::getContainer()->get(CacheItemPoolInterface::class);
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->save($cache->getItem('app_updates.latest_version')->set($newer));

        return $newer;
    }

    // Una sola richiesta per test: la cache dei test è in memoria e il client la perde riavviando il kernel (o
    // azzerando i servizi) prima della richiesta successiva; prima della prima richiesta non succede.

    public function testTheInstanceAdminSeesTheNewerRelease(): void
    {
        [, , $adminSession] = $this->createAuthenticatedUser();
        $newer = $this->newerReleaseKnown();

        $this->jsonRequest('GET', '/api/about', session: $adminSession);

        self::assertResponseIsSuccessful();
        self::assertSame(['version' => $newer, 'url' => AppUpdates::releaseUrl($newer)], $this->jsonBody()['update']);
    }

    public function testOtherUsersSeeOnlyTheInstalledVersion(): void
    {
        $this->createAuthenticatedUser();
        [, , $memberSession] = $this->createAuthenticatedUser(OrgRole::MEMBER);
        $this->newerReleaseKnown();

        $this->jsonRequest('GET', '/api/about', session: $memberSession);

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->jsonBody()['version']);
        self::assertNull($this->jsonBody()['update']);
    }

    public function testRequiresLogin(): void
    {
        $this->jsonRequest('GET', '/api/about');

        self::assertResponseStatusCodeSame(401);
    }
}
