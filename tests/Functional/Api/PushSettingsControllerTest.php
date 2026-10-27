<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\PushSubscription;
use App\Enum\OrgRole;
use App\Enum\PushPlatform;
use App\Repository\PushSettingsRepository;
use App\Service\SecretCipher;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PushSettingsControllerTest extends ApiTestCase
{
    public function testGetReturnsDefaultsWhenNeverConfigured(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/settings/push', session: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertFalse($body['enabled']);
        self::assertFalse($body['hasKeys']);
        self::assertSame('', $body['publicKey']);
    }

    public function testGetForbiddenForAdminAndMember(): void
    {
        // Un altro utente occupa lo slot di primo iscritto (= admin di istanza),
        // altrimenti l'ADMIN/MEMBER creato sotto sarebbe l'unico utente del test
        // e passerebbe la verifica per coincidenza.
        $this->createAuthenticatedUser();

        foreach ([OrgRole::ADMIN, OrgRole::MEMBER] as $role) {
            [, , $token] = $this->createAuthenticatedUser($role);

            $this->jsonRequest('GET', '/api/settings/push', session: $token);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('settings.instance_admin_required', $this->jsonBody()['title']);
        }
    }

    public function testGenerateForbiddenForMember(): void
    {
        $this->createAuthenticatedUser();
        [, , $token] = $this->createAuthenticatedUser(OrgRole::MEMBER);

        $this->jsonRequest('POST', '/api/settings/push/generate', session: $token);
        self::assertResponseStatusCodeSame(403);
    }

    public function testForbiddenForOwnerOfAnotherOrganization(): void
    {
        // La falla reale: l'autorizzazione era "sei OWNER di UNA org", non "sei
        // l'admin di istanza". Ogni utente è OWNER della propria org personale — e
        // con la vecchia logica poteva rigenerare le chiavi VAPID dell'istanza
        // (rompendo il push di tutti). Il secondo OWNER (org diversa, non il primo
        // utente) resta fuori.
        $this->createAuthenticatedUser(); // primo utente = admin di istanza
        [, , $token] = $this->createAuthenticatedUser(); // secondo OWNER, org diversa

        $this->jsonRequest('GET', '/api/settings/push', session: $token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('settings.instance_admin_required', $this->jsonBody()['title']);

        $this->jsonRequest('POST', '/api/settings/push/generate', session: $token);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGenerateCreatesEncryptedKeypair(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/settings/push/generate', session: $token);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertNotSame('', $body['publicKey']);
        self::assertArrayHasKey('removedSubscriptions', $body);

        // GET ora riporta le chiavi presenti; la private non esce mai dall'API.
        $this->jsonRequest('GET', '/api/settings/push', session: $token);
        $get = $this->jsonBody();
        self::assertTrue($get['hasKeys']);
        self::assertSame($body['publicKey'], $get['publicKey']);
        self::assertArrayNotHasKey('privateKey', $get);

        // At rest: cifrata (non plaintext), decifrabile con la chiave dell'app.
        $settings = static::getContainer()->get(PushSettingsRepository::class)->get();
        self::assertNotNull($settings);
        $cipher = static::getContainer()->get(SecretCipher::class);
        $privateKey = $cipher->decrypt((string) $settings->getPrivateKeyCipher());
        self::assertNotSame('', $privateKey);
        self::assertStringNotContainsString($privateKey, (string) $settings->getPrivateKeyCipher());
    }

    public function testEnableWithoutKeysReturns400(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/settings/push', [
            'subject' => 'mailto:admin@example.com',
            'enabled' => true,
        ], session: $token);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('push.no_keys', $this->jsonBody()['title']);
    }

    public function testGenerateAutomaticallyEnablesAndExposesDbPublicKeyAtRuntime(): void
    {
        // Generare una coppia è un'azione esplicita dell'owner: attiva da sola,
        // niente secondo passaggio "abilita" separato (era un passaggio in più).
        // NB: env-indipendente — l'env VAPID_* può essere valorizzato (fallback);
        // qui verifichiamo solo che, una volta generata, la public key del DB vince.
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/settings/push/generate', session: $token);
        $publicKey = $this->jsonBody()['publicKey'];

        $this->jsonRequest('GET', '/api/settings/push', session: $token);
        self::assertTrue($this->jsonBody()['enabled']);

        // La public key del DB (appena generata) è esposta al frontend a runtime.
        $this->jsonRequest('GET', '/api/push-subscriptions/vapid-public-key', session: $token);
        self::assertSame($publicKey, $this->jsonBody()['publicKey']);

        // Una volta abilitata, la sorgente esposta in Settings è 'db' (a prescindere dall'env).
        $this->jsonRequest('GET', '/api/settings/push', session: $token);
        self::assertSame('db', $this->jsonBody()['source']);
    }

    public function testTestReturns400WhenNoSubscription(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        // Generare abilita già da solo — config completa, ma nessun device sottoscritto.
        $this->jsonRequest('POST', '/api/settings/push/generate', session: $token);

        $this->jsonRequest('POST', '/api/settings/push/test', session: $token);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('push.no_subscription', $this->jsonBody()['title']);
    }

    public function testEndpointsRequireAuth(): void
    {
        $this->jsonRequest('GET', '/api/settings/push');
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('POST', '/api/settings/push/generate');
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('POST', '/api/settings/push/test');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUpdateForbiddenForNonInstanceAdminAndSettingsStayUntouched(): void
    {
        [, , $adminToken] = $this->createAuthenticatedUser(); // primo utente = admin di istanza
        $this->jsonRequest('POST', '/api/settings/push/generate', session: $adminToken);
        $publicKey = $this->jsonBody()['publicKey'];

        // Un OWNER di un'altra org non deve poter spegnere o ridirigere il push di tutta l'istanza
        [, , $token] = $this->createAuthenticatedUser();
        $this->jsonRequest('PUT', '/api/settings/push', ['subject' => 'mailto:evil@example.com', 'enabled' => false], session: $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('settings.instance_admin_required', $this->jsonBody()['title']);

        $this->jsonRequest('GET', '/api/settings/push', session: $adminToken);
        $settings = $this->jsonBody();
        self::assertTrue($settings['enabled']);
        self::assertSame($publicKey, $settings['publicKey']);
        self::assertStringNotContainsString('evil', (string) $settings['subject']);
    }

    public function testTestEndpointForbiddenForNonInstanceAdmin(): void
    {
        $this->createAuthenticatedUser();
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/settings/push/test', session: $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('settings.instance_admin_required', $this->jsonBody()['title']);
    }

    public function testTestReturns400WhenPushIsNotConfigured(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('POST', '/api/settings/push/test', session: $token);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('push.not_configured', $this->jsonBody()['title']);
    }

    public function testUpdateSavesTheSubjectAndDisablingStopsExposingThePublicKey(): void
    {
        [, , $token] = $this->createAuthenticatedUser();
        $this->jsonRequest('POST', '/api/settings/push/generate', session: $token);

        $this->jsonRequest('PUT', '/api/settings/push', ['subject' => 'mailto:ops@example.com', 'enabled' => false], session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('mailto:ops@example.com', $this->jsonBody()['subject']);
        self::assertFalse($this->jsonBody()['enabled']);
        self::assertTrue($this->jsonBody()['hasKeys'], 'Disabilitare non cancella le chiavi');

        // Il frontend non deve più iscrivere device a un push spento
        $this->jsonRequest('GET', '/api/push-subscriptions/vapid-public-key', session: $token);
        self::assertSame('', $this->jsonBody()['publicKey']);

        // Riabilitare con le chiavi presenti è consentito
        $this->jsonRequest('PUT', '/api/settings/push', ['subject' => 'mailto:ops@example.com', 'enabled' => true], session: $token);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['enabled']);
    }

    public function testUpdateRejectsATooLongSubject(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('PUT', '/api/settings/push', ['subject' => str_repeat('a', 256), 'enabled' => false], session: $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('subject', $this->jsonBody()['errors'][0]['field']);
    }

    public function testRegeneratingKeysDropsTheSubscriptionsOfEveryUser(): void
    {
        // La vecchia public key è incastonata in ogni subscription: dopo la rigenerazione nessuna può ricevere.
        [$admin, , $token] = $this->createAuthenticatedUser();
        $this->jsonRequest('POST', '/api/settings/push/generate', session: $token);
        $firstKey = $this->jsonBody()['publicKey'];

        [$other] = $this->createAuthenticatedUser();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([$admin, $other] as $n => $user) {
            $em->persist(
                (new PushSubscription())
                    ->setUser($user)
                    ->setPlatform(PushPlatform::WEB)
                    ->setEndpoint('https://fcm.googleapis.com/fcm/send/device-'.$n)
                    ->setP256dh('p256dh')
                    ->setAuthSecret('auth'),
            );
        }
        $em->flush();

        $this->jsonRequest('POST', '/api/settings/push/generate', session: $token);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->jsonBody()['removedSubscriptions']);
        self::assertNotSame($firstKey, $this->jsonBody()['publicKey']);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscriptions'));
    }
}
