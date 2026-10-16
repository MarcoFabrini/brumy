<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\PushSettings;
use App\Entity\PushSubscription;
use App\Service\Push\PushPayload;
use App\Service\Push\WebPushSender;
use App\Service\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Minishlink\WebPush\VAPID;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class WebPushSenderTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    public function testClientKeepsTheMessageForADayWhenTheDeviceIsUnreachable(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $keys = VAPID::createVapidKeys();
        $em->persist(
            (new PushSettings())
                ->setPublicKey($keys['publicKey'])
                ->setPrivateKeyCipher(static::getContainer()->get(SecretCipher::class)->encrypt($keys['privateKey']))
                ->setEnabled(true),
        );
        $em->flush();

        $client = static::getContainer()->get(WebPushSender::class)->buildClient();

        self::assertNotNull($client);
        self::assertSame(86400, $client->getDefaultOptions()['TTL'], 'Un telefono spento alle 07:00 deve ricevere il push quando si riaccende');
    }

    public function testNoClientWithoutVapidConfiguration(): void
    {
        self::bootKernel();

        self::assertNull(static::getContainer()->get(WebPushSender::class)->buildClient());
    }

    public function testClientDoesNotFollowRedirects(): void
    {
        self::bootKernel();
        $this->enableVapid();

        $webPush = static::getContainer()->get(WebPushSender::class)->buildClient();
        self::assertNotNull($webPush);

        // Il client HTTP è un dettaglio protetto della libreria: lo leggo per verificare la configurazione davvero applicata
        $client = (new \ReflectionProperty($webPush, 'client'))->getValue($webPush);
        self::assertInstanceOf(Client::class, $client);
        self::assertFalse($client->getConfig('allow_redirects'));
        // Un push service lento non deve tenere occupato per mezzo minuto l'unico worker.
        self::assertSame(10, $client->getConfig('timeout'));
        self::assertSame(5, $client->getConfig('connect_timeout'));
    }

    public function testStoredSubscriptionWithDisallowedEndpointIsNotSentAndIsMarkedGone(): void
    {
        self::bootKernel();
        $this->enableVapid();
        $subscription = (new PushSubscription())
            ->setEndpoint('https://storage.googleapis.com/bucket/obj')
            ->setP256dh('p256dh')
            ->setAuthSecret('auth');

        $result = static::getContainer()->get(WebPushSender::class)->send($subscription, new PushPayload('t', 'b'));

        self::assertFalse($result->success);
        self::assertTrue($result->gone);
        self::assertSame('endpoint_not_allowed', $result->errorMessage);
    }

    public function testDisabledSettingsSendNothingEvenWithKeysStored(): void
    {
        self::bootKernel();
        $this->enableVapid(enabled: false);
        $sender = static::getContainer()->get(WebPushSender::class);

        self::assertFalse($sender->isConfigured());
        self::assertSame('', $sender->activePublicKey(), 'Un push spento non espone la chiave al frontend');
        self::assertSame('none', $sender->activeSource());
        $result = $sender->send($this->validLookingSubscription(), new PushPayload('t', 'b'));
        self::assertFalse($result->success);
        self::assertFalse($result->gone, 'Spento non vuol dire device morto: le subscription restano');
        self::assertSame('vapid_not_configured', $result->errorMessage);
    }

    public function testAnUndecryptablePrivateKeyDisablesSendingInsteadOfCrashing(): void
    {
        // Succede se APP_SECRET cambia dopo la generazione delle chiavi VAPID
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $keys = VAPID::createVapidKeys();
        $em->persist(
            (new PushSettings())
                ->setPublicKey($keys['publicKey'])
                ->setPrivateKeyCipher(base64_encode(random_bytes(80)))
                ->setEnabled(true),
        );
        $em->flush();
        $sender = static::getContainer()->get(WebPushSender::class);

        self::assertFalse($sender->isConfigured());
        self::assertSame('vapid_not_configured', $sender->send($this->validLookingSubscription(), new PushPayload('t', 'b'))->errorMessage);
    }

    public function testASubscriptionMissingItsKeysFailsWithoutBeingMarkedGone(): void
    {
        self::bootKernel();
        $this->enableVapid();
        $subscription = (new PushSubscription())->setEndpoint('https://fcm.googleapis.com/fcm/send/abc');

        $result = static::getContainer()->get(WebPushSender::class)->send($subscription, new PushPayload('t', 'b'));

        self::assertFalse($result->success);
        self::assertFalse($result->gone);
        self::assertSame('missing_web_push_fields', $result->errorMessage);
    }

    private function validLookingSubscription(): PushSubscription
    {
        return (new PushSubscription())
            ->setEndpoint('https://fcm.googleapis.com/fcm/send/abc')
            ->setP256dh('p256dh')
            ->setAuthSecret('auth');
    }

    private function enableVapid(bool $enabled = true): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $keys = VAPID::createVapidKeys();
        $em->persist(
            (new PushSettings())
                ->setPublicKey($keys['publicKey'])
                ->setPrivateKeyCipher(static::getContainer()->get(SecretCipher::class)->encrypt($keys['privateKey']))
                ->setEnabled($enabled),
        );
        $em->flush();
    }
}
