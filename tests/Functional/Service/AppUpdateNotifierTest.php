<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Command\CheckUpdateCommand;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Enum\PushPlatform;
use App\Service\AppUpdateNotifier;
use App\Service\AppUpdates;
use App\Service\Push\FakePushNotifier;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AppUpdateNotifierTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private AppUpdateNotifier $notifier;
    private FakePushNotifier $push;
    private EntityManagerInterface $em;
    private string $packageJson;

    protected function setUp(): void
    {
        self::bootKernel();
        $notifier = static::getContainer()->get(AppUpdateNotifier::class);
        $push = static::getContainer()->get(FakePushNotifier::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(AppUpdateNotifier::class, $notifier);
        self::assertInstanceOf(FakePushNotifier::class, $push);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->notifier = $notifier;
        $this->push = $push;
        $this->em = $em;
        $this->packageJson = (string) tempnam(sys_get_temp_dir(), 'brumy-package');
        file_put_contents($this->packageJson, '{"version": "0.7.0"}');
    }

    protected function tearDown(): void
    {
        @unlink($this->packageJson);
        parent::tearDown();
    }

    private function withDevice(User $user): User
    {
        $this->em->persist((new PushSubscription())->setUser($user)->setPlatform(PushPlatform::WEB)
            ->setEndpoint('https://push.example/'.$user->getId())->setP256dh('k')->setAuthSecret('a'));
        $this->em->flush();

        return $user;
    }

    /** @return list<int|null> utenti che hanno ricevuto una notifica */
    private function notifiedUsers(): array
    {
        return array_map(static fn (array $sent): ?int => $sent['userId'], $this->push->sent());
    }

    public function testTheInstanceAdminIsNotifiedOncePerVersion(): void
    {
        $admin = $this->withDevice(UserFactory::createOne());
        $this->withDevice(UserFactory::createOne());

        self::assertTrue($this->notifier->notifyAdmin('0.8.0'));
        self::assertFalse($this->notifier->notifyAdmin('0.8.0'), 'lo stesso controllo dell\'ora dopo');
        self::assertTrue($this->notifier->notifyAdmin('0.8.1'), 'una versione ancora più nuova');

        self::assertSame([$admin->getId(), $admin->getId()], $this->notifiedUsers(), 'gli altri utenti non la ricevono');
        $payload = $this->push->sent()[0]['payload'];
        self::assertSame('Disponibile Brumy 0.8.0', $payload->title);
        self::assertSame('/settings', $payload->url);
        $this->em->clear();
        self::assertSame('0.8.1', $this->em->find(User::class, $admin->getId())?->getUpdateNotifiedVersion());
    }

    public function testWithoutAReachableDeviceItIsSentLater(): void
    {
        $admin = UserFactory::createOne();

        self::assertFalse($this->notifier->notifyAdmin('0.8.0'));
        self::assertNull($admin->getUpdateNotifiedVersion());

        $this->withDevice($admin);
        self::assertTrue($this->notifier->notifyAdmin('0.8.0'), 'appena accende le notifiche');
    }

    public function testTheCommandNotifiesANewerReleaseFoundOnGitHub(): void
    {
        $admin = $this->withDevice(UserFactory::createOne());
        $tester = $this->command(new MockResponse((string) json_encode([['name' => 'v0.7.1'], ['name' => 'v0.7.0']])));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0.7.1', $tester->getDisplay());
        self::assertStringContainsString('Notifica inviata', $tester->getDisplay());
        self::assertSame([$admin->getId()], $this->notifiedUsers());
    }

    public function testTheCommandNotifiesNothingWhenAlreadyOnTheLatest(): void
    {
        $this->withDevice(UserFactory::createOne());
        $tester = $this->command(new MockResponse((string) json_encode([['name' => 'v0.7.0']])));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([], $this->push->sent());
    }

    public function testTheCommandDoesNothingWhenTurnedOff(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('nessuna richiesta a GitHub'));
        $tester = new CommandTester(new CheckUpdateCommand(new AppUpdates($client, new ArrayAdapter(), $this->packageJson, false), $this->notifier));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('UPDATE_CHECK_ENABLED=0', $tester->getDisplay());
    }

    public function testTheCommandFailsWhenGitHubIsDown(): void
    {
        self::assertSame(Command::FAILURE, $this->command(new MockResponse('', ['http_code' => 500]))->execute([]));
    }

    private function command(MockResponse $tags): CommandTester
    {
        $updates = new AppUpdates(new MockHttpClient($tags), new ArrayAdapter(), $this->packageJson);

        return new CommandTester(new CheckUpdateCommand($updates, $this->notifier));
    }
}
