<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Command\CheckUpdateCommand;
use App\Service\AppUpdates;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AppUpdatesTest extends TestCase
{
    private string $packageJson;

    protected function setUp(): void
    {
        $this->packageJson = (string) tempnam(sys_get_temp_dir(), 'brumy-package');
        file_put_contents($this->packageJson, '{"name": "brumy-frontend", "version": "0.7.0"}');
    }

    protected function tearDown(): void
    {
        @unlink($this->packageJson);
    }

    /** @param list<array{name: string}>|MockResponse $tags */
    private function updates(array|MockResponse $tags = [], bool $enabled = true, ?ArrayAdapter $cache = null): AppUpdates
    {
        $response = $tags instanceof MockResponse ? $tags : new MockResponse(json_encode($tags, JSON_THROW_ON_ERROR));

        return new AppUpdates(new MockHttpClient($response), $cache ?? new ArrayAdapter(), $this->packageJson, $enabled);
    }

    public function testLatestStableIgnoresPreReleasesAndOtherTags(): void
    {
        self::assertSame('0.10.0', AppUpdates::latestStable(['v0.9.3', 'v0.10.0', 'v1.0.0-rc.1', 'nightly', '0.11.0', 'v0.2']));
        self::assertNull(AppUpdates::latestStable(['nightly']));
    }

    public function testCurrentVersionComesFromPackageJson(): void
    {
        self::assertSame('0.7.0', $this->updates()->currentVersion());

        file_put_contents($this->packageJson, '{"version": "0.0.0-dev"}');
        self::assertSame('dev', $this->updates()->currentVersion(), 'una versione non di rilascio');
        unlink($this->packageJson);
        self::assertSame('dev', $this->updates()->currentVersion(), 'file assente');
    }

    public function testCheckRemembersTheNewerRelease(): void
    {
        $updates = $this->updates([['name' => 'v0.7.1'], ['name' => 'v0.7.0'], ['name' => 'v0.8.0-rc.1']]);

        self::assertNull($updates->availableUpdate(), 'prima del controllo non si sa niente');
        self::assertSame('0.7.1', $updates->check());
        self::assertSame('0.7.1', $updates->availableUpdate());
        self::assertSame('https://github.com/MarcoFabrini/brumy/releases/tag/v0.7.1', AppUpdates::releaseUrl('0.7.1'));
    }

    public function testNoUpdateWhenAlreadyOnTheLatest(): void
    {
        $updates = $this->updates([['name' => 'v0.7.0'], ['name' => 'v0.6.2']]);
        $updates->check();

        self::assertSame('0.7.0', $updates->latestVersion());
        self::assertNull($updates->availableUpdate());
    }

    public function testAFailedCheckKeepsThePreviousResult(): void
    {
        $cache = new ArrayAdapter();
        $this->updates([['name' => 'v0.7.1']], cache: $cache)->check();

        $offline = $this->updates(new MockResponse('', ['http_code' => 503]), cache: $cache);
        try {
            $offline->check();
            self::fail('GitHub giù deve far fallire il controllo');
        } catch (\RuntimeException) {
        }
        self::assertSame('0.7.1', $offline->availableUpdate());
    }

    public function testCommandReportsTheUpdate(): void
    {
        $tester = new CommandTester(new CheckUpdateCommand($this->updates([['name' => 'v0.7.1']])));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0.7.1', $tester->getDisplay());
    }

    public function testCommandDoesNothingWhenTurnedOff(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('nessuna richiesta a GitHub'));
        $tester = new CommandTester(new CheckUpdateCommand(new AppUpdates($client, new ArrayAdapter(), $this->packageJson, false)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('UPDATE_CHECK_ENABLED=0', $tester->getDisplay());
    }

    public function testCommandFailsWhenGitHubIsDown(): void
    {
        $tester = new CommandTester(new CheckUpdateCommand($this->updates(new MockResponse('', ['http_code' => 500]))));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }
}
