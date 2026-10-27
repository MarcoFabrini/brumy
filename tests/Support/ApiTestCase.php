<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Service\ActiveOrganizationResolver;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Base per i test funzionali HTTP. Centralizza:
 * - reset DB tra test (ResetDatabase)
 * - factory autowire (Factories)
 * - helper per creare un utente autenticato + una sessione (id del cookie `brumy_session`)
 * - helper per richieste JSON tipizzate, che passano il controllo CSRF same-origin come la SPA
 */
abstract class ApiTestCase extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    protected KernelBrowser $client;

    /**
     * Path file temporanei generati nei test multipart, puliti in tearDown.
     * @var list<string>
     */
    protected array $tmpFiles = [];

    public const SESSION_COOKIE = 'brumy_session';
    public const REMEMBER_ME_COOKIE = 'brumy_remember';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // Le eccezioni HTTP non interrompono il test: vogliamo asserire sullo status code
        $this->client->catchExceptions(true);
        // Come il browser per le richieste della SPA: le scritture passano il controllo CSRF (i test CSRF lo sovrascrivono)
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    /**
     * Crea utente + organization + membership; ritorna [user, org, id di una sessione già autenticata su quell'org].
     *
     * @return array{0: User, 1: Organization, 2: string}
     */
    protected function createAuthenticatedUser(OrgRole $role = OrgRole::OWNER): array
    {
        $user = UserFactory::createOne();
        $org = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne([
            'user' => $user,
            'organization' => $org,
            'role' => $role,
        ]);

        return [$user, $org, $this->sessionFor($user, $org)];
    }

    /**
     * Apre una sessione autenticata per $user con $org attiva, come farebbe un login (stesso schema di
     * `KernelBrowser::loginUser()`: token del firewall `api` serializzato in sessione), e ne ritorna l'id da passare a
     * {@see self::jsonRequest()}. $org null = nessuna org scelta: la sceglie il resolver alla prima richiesta.
     */
    protected function sessionFor(User $user, ?Organization $org = null): string
    {
        $session = static::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $session);
        $session = $session->createSession();
        $session->set('_security_api', serialize(new UsernamePasswordToken($user, 'api', $user->getRoles())));
        if ($org !== null) {
            $session->set(ActiveOrganizationResolver::SESSION_KEY, $org->getId());
        }
        $session->save();

        return $session->getId();
    }

    /**
     * Richiesta JSON. Con $session la richiesta porta quel cookie di sessione (al posto di quello che il client avesse
     * ricevuto); senza, il client manda i cookie che ha (es. dopo un login), come un browser.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $server header aggiuntivi in formato $_SERVER (`HTTP_ORIGIN`...)
     */
    protected function jsonRequest(
        string $method,
        string $uri,
        ?array $body = null,
        ?string $session = null,
        array $server = [],
    ): void {
        if ($session !== null) {
            $this->useSession($session);
        }

        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', ...$server],
            content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null,
        );
    }

    /** Mette $sessionId nel cookie `brumy_session` del client, al posto di quello che aveva. */
    protected function useSession(string $sessionId): void
    {
        $jar = $this->client->getCookieJar();
        $jar->expire(self::SESSION_COOKIE, '/api');
        $jar->expire(self::SESSION_COOKIE, '/');
        $jar->set(new Cookie(self::SESSION_COOKIE, $sessionId, null, '/api', 'localhost'));
    }

    /** Valore del cookie $name impostato dall'ultima risposta (null se non c'è o se la risposta lo cancella). */
    protected function responseCookie(string $name): ?string
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie->isCleared() ? null : $cookie->getValue();
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(): array
    {
        $content = $this->client->getResponse()->getContent();
        if (!is_string($content) || $content === '') {
            return [];
        }
        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Il pool dei rate limiter è un ArrayAdapter che il kernel azzera a ogni richiesta (kernel.reset):
     * se ne usa uno che ignora il reset (e niente reboot tra le richieste), così i contatori durano
     * per tutto il test e il superamento del limite si può osservare.
     */
    protected function keepRateLimiterCountersForTheWholeTest(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set('cache.rate_limiter', new class extends ArrayAdapter {
            public function reset(): void
            {
            }
        });
    }

    /**
     * Token del link nell'ultima email di verifica o di conferma del cambio email. Le email registrate valgono solo
     * per l'ultima richiesta: va letto subito dopo quella che l'ha spedita.
     */
    protected function lastVerificationToken(): string
    {
        $messages = self::getMailerMessages();
        $last = end($messages);
        self::assertInstanceOf(Email::class, $last, 'Nessuna email spedita');
        if (preg_match('#/verify-email\?token=([a-f0-9]{64})#', (string) $last->getTextBody(), $m) !== 1) {
            self::fail('Link di verifica non trovato');
        }

        return $m[1];
    }

    /** Apre il link dell'ultima email di verifica, come farebbe l'utente (subito dopo la richiesta che l'ha spedita). */
    protected function openLastVerificationLink(): void
    {
        $this->jsonRequest('POST', '/api/auth/verify-email', ['token' => $this->lastVerificationToken()]);
    }

    // ---------------- multipart helpers ----------------

    /**
     * Creates an UploadedFile from raw bytes. Symfony's HttpFoundation requires a real file path
     * for multipart upload simulation, so we write to /tmp + register cleanup.
     */
    protected function makeUploadedFile(string $name, string $bytes, string $mime): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'brumy_test_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Cannot create tempfile');
        }
        file_put_contents($tmpPath, $bytes);
        $this->tmpFiles[] = $tmpPath;

        // 4° arg null = skip client MIME (server-side guess from content)
        // 5° arg true = test mode (consente file non passato da HTTP reale)
        return new UploadedFile($tmpPath, $name, $mime, null, true);
    }

    /** Minimal valid 1x1 PNG (binary header) — server-side getMimeType() detecta image/png. */
    protected function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAA'.
            'DUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    /** Minimal valid JPEG (SOI + JFIF header + EOI) — sufficient per file mime guess. */
    protected function jpegBytes(): string
    {
        // 1x1 black JPEG generato con `convert -size 1x1 xc:black tmp.jpg`
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'.
            'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAf/AABEIAAEAAQMBIgACEQEDEQH/'.
            'xAAfAAABBQEBAQEBAQAAAAAAAAAAAQIDBAUGBwgJCgv/xAC1EAACAQMDAgQDBQUEBAAAAX0B'.
            'AgMABBEFEiExQQYTUWEHInEUMoGRoQgjQrHBFVLR8CQzYnKCCQoWFxgZGiUmJygpKjQ1Njc4'.
            'OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoOEhYaHiImKkpOUlZaXmJmaoqOk'.
            'paanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4eLj5OXm5+jp6vHy8/T19vf4+fr/'.
            '2gAMAwEAAhEDEQA/AP38ooooAKKKKAP/2Q=='
        );
    }
}
