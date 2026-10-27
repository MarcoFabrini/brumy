<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\LoginFailureListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Un'email sconosciuta deve costare quanto una password sbagliata: Symfony risponde subito, il listener calcola un hash
 * dello stesso tipo (e non della password inviata, che potrebbe superare il limite dell'hasher).
 */
final class LoginFailureListenerTest extends TestCase
{
    public function testAnUnknownEmailCostsAPasswordHash(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hash')->with(self::logicalNot(self::stringContains('inviata')));

        $this->listener($hasher, User::class)->onLoginFailure($this->failure(
            new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()),
        ));
    }

    public function testAWrongPasswordDoesNotHashAgain(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');

        $this->listener($hasher)->onLoginFailure($this->failure(new BadCredentialsException('The presented password is invalid.')));
    }

    private function listener(PasswordHasherInterface $hasher, ?string $expectedUserClass = null): LoginFailureListener
    {
        $factory = $this->createMock(PasswordHasherFactoryInterface::class);
        $factory->method('getPasswordHasher')->willReturnCallback(static function (mixed $user) use ($hasher, $expectedUserClass): PasswordHasherInterface {
            if ($expectedUserClass !== null) {
                self::assertSame($expectedUserClass, $user, "Lo stesso hasher configurato per l'utente");
            }

            return $hasher;
        });

        return new LoginFailureListener($factory, new NullLogger());
    }

    private function failure(AuthenticationException $exception): LoginFailureEvent
    {
        $request = Request::create('/api/auth/login', 'POST', content: '{"email":"x@test.it","password":"inviata"}');

        return new LoginFailureEvent($exception, $this->createStub(AuthenticatorInterface::class), $request, null, 'api');
    }
}
