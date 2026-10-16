<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\User;
use App\EventListener\ApiWriteRateLimitListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ApiWriteRateLimitListenerTest extends TestCase
{
    private function listener(?User $user, int $limit = 2): ApiWriteRateLimitListener
    {
        $storage = new TokenStorage();
        if ($user !== null) {
            $storage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        }

        return new ApiWriteRateLimitListener(
            new RateLimiterFactory(['id' => 'api', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()),
            $storage,
        );
    }

    private function event(string $method, string $path, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create($path, $method), $type);
    }

    private static function user(int $id): User
    {
        $user = new User();
        $user->setEmail('u'.$id.'@test.it')->setFirstName('A')->setLastName('B');
        (new \ReflectionProperty($user, 'id'))->setValue($user, $id);

        return $user;
    }

    public function testWritesBeyondTheLimitAreAnswered429WithRetryAfter(): void
    {
        $listener = $this->listener(self::user(1));

        for ($i = 0; $i < 2; ++$i) {
            $event = $this->event('POST', '/api/expenses');
            $listener($event);
            self::assertFalse($event->hasResponse(), 'Dentro il limite passa');
        }

        $blocked = $this->event('DELETE', '/api/expenses/1');
        $listener($blocked);

        self::assertTrue($blocked->hasResponse());
        $response = $blocked->getResponse();
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('http.429', json_decode((string) $response->getContent(), true)['title']);
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
    }

    public function testReadsAnonymousCallsAndSubRequestsAreNeverLimited(): void
    {
        $listener = $this->listener(self::user(1), 1);
        $anonymous = $this->listener(null, 1);

        for ($i = 0; $i < 5; ++$i) {
            foreach ([$this->event('GET', '/api/vehicles'), $this->event('POST', '/not-api/x'), $this->event('POST', '/api/expenses', HttpKernelInterface::SUB_REQUEST)] as $event) {
                $listener($event);
                self::assertFalse($event->hasResponse());
            }
            $login = $this->event('POST', '/api/auth/login');
            $anonymous($login);
            self::assertFalse($login->hasResponse(), 'Gli anonimi hanno i limiter delle rotte pubbliche');
        }
    }

    public function testEachUserHasHisOwnBudget(): void
    {
        $listener = $this->listener(self::user(1), 1);
        $first = $this->event('POST', '/api/expenses');
        $listener($first);
        $second = $this->event('POST', '/api/expenses');
        $listener($second);
        self::assertTrue($second->hasResponse(), 'Il secondo dello stesso utente è bloccato');

        $other = $this->listener(self::user(2), 1);
        $event = $this->event('POST', '/api/expenses');
        $other($event);
        self::assertFalse($event->hasResponse(), 'Un altro utente ha un budget separato (factory e storage diversi qui: la chiave è per utente)');
    }
}
