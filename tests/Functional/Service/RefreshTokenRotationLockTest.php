<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Service\RefreshRejection;
use App\Service\RefreshTokenService;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Rotazione e revoche dei refresh token si serializzano sul lock della riga `users` dell'utente: un successore
 * emesso durante la rotazione non sfugge a una revoca concorrente (cambio/reset password, cambio email, riuso,
 * GDPR). Audit run-2, finding `refresh-token/rotate-successor-escapes-concurrent-revocation`.
 *
 * Due richieste concorrenti = due connessioni: la seconda è una connessione DBAL a parte con gli stessi parametri.
 * I dati delle factory sono committati (niente transazione di test), quindi la seconda connessione li vede.
 * Un'eccezione dentro `wrapInTransaction()` chiude l'EntityManager: un solo caso bloccante per test.
 */
final class RefreshTokenRotationLockTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private ?Connection $other = null;

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
            $this->other = null;
        }
        parent::tearDown();
    }

    /**
     * Mentre un'altra transazione tiene il lock sull'utente, ogni operazione aspetta (e qui va in timeout) senza
     * scrivere nulla: niente successore, niente revoca, `token_version` invariata.
     */
    #[DataProvider('lockedOperations')]
    public function testEveryRotationAndRevocationWaitsForTheUserLock(string $operation): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RefreshTokenService::class);
        $user = UserFactory::createOne();
        $session = $service->issue($user);
        $plain = $session->getPlainToken();
        if ($operation === 'reuse') {
            // Token già ruotato e ripresentato fuori dalla finestra di tolleranza.
            self::assertNotNull($service->rotate($session));
            $this->connection()->executeStatement(
                'UPDATE refresh_tokens SET rotated_at = ? WHERE token = ?',
                [(new \DateTimeImmutable('-60 seconds'))->format('Y-m-d H:i:s'), RefreshTokenService::hash($plain)],
            );
            $this->em()->clear(); // l'istanza in memoria ha ancora la rotazione appena avvenuta
        }
        $before = $this->snapshot($user);

        $this->holdUserLock($user);
        $this->connection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            match ($operation) {
                'rotate' => $service->rotate($session),
                'revokeAllForUser' => $service->revokeAllForUser($user),
                'reuse' => $service->classifyRejected($plain),
                'logout' => $service->revoke($session),
                'login' => $service->issueIfCredentialsUnchanged($user),
                default => throw new \LogicException("Operazione sconosciuta: $operation"),
            };
            self::fail("$operation non ha atteso il lock dell'utente");
        } catch (LockWaitTimeoutException) {
        }

        $this->releaseUserLock();
        self::assertSame($before, $this->snapshot($user), "$operation non deve aver scritto nulla");
    }

    /** @return iterable<string, array{string}> */
    public static function lockedOperations(): iterable
    {
        yield 'rotazione' => ['rotate'];
        yield 'revoca di tutte le sessioni' => ['revokeAllForUser'];
        yield 'revoca della famiglia per riuso' => ['reuse'];
        yield 'logout' => ['logout'];
        yield 'sessione di un login' => ['login'];
    }

    /**
     * Nessun aggiornamento perso: la versione viene incrementata dal valore riletto sotto lock, non da quello
     * (vecchio) dell'istanza in memoria. Qui un'altra revoca è già committata dopo che l'utente è stato caricato:
     * un lock senza rilettura scriverebbe 1 e riaprirebbe gli access token che quella revoca aveva chiuso.
     */
    #[DataProvider('versionBumpingOperations')]
    public function testRevocationIncrementsTheCommittedVersionNotTheStaleOne(string $operation): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RefreshTokenService::class);
        $user = UserFactory::createOne();
        $session = $service->issue($user);
        self::assertSame(0, $user->getTokenVersion());

        $this->otherConnection()->executeStatement('UPDATE users SET token_version = token_version + 1 WHERE id = ?', [$user->getId()]);

        match ($operation) {
            'revokeAllForUser' => $service->revokeAllForUser($user),
            'logout' => $service->revoke($session),
            default => throw new \LogicException("Operazione sconosciuta: $operation"),
        };

        self::assertSame(2, $this->snapshot($user)['token_version']);
        self::assertSame(2, $user->getTokenVersion(), "L'istanza porta la versione scritta");
    }

    /** @return iterable<string, array{string}> */
    public static function versionBumpingOperations(): iterable
    {
        yield 'revoca di tutte le sessioni' => ['revokeAllForUser'];
        yield 'logout' => ['logout'];
    }

    /**
     * Logout di un token che una rotazione concorrente ha già sostituito (tra `findValid()` e il lock): il
     * successore è della stessa sessione e decade con lei, invece di restare valido.
     */
    public function testLogoutAlsoRevokesASuccessorRotatedInTheMeantime(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RefreshTokenService::class);
        $user = UserFactory::createOne();
        $otherSession = $service->issue($user);
        $read = $service->findValid($service->issue($user)->getPlainToken());
        self::assertInstanceOf(RefreshToken::class, $read);

        $successor = $service->rotate($read);
        self::assertInstanceOf(RefreshToken::class, $successor);
        $service->revoke($read);

        $this->em()->clear(); // la revoca è un UPDATE in blocco: le istanze in memoria non la vedono
        self::assertNull($service->findValid($successor->getPlainToken()), 'Il successore non sopravvive al logout');
        self::assertNotNull($service->findValid($otherSession->getPlainToken()), "Le altre sessioni dell'utente restano");
    }

    /**
     * Un login verificato con la password vecchia mentre un reset/cambio password o un cambio email viene
     * committato non apre una sessione: altrimenti sopravviverebbe alla revoca di quella transizione.
     */
    #[DataProvider('credentialChanges')]
    public function testLoginSessionIsNotIssuedWhenCredentialsChangedAfterTheCheck(string $column, string $value): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RefreshTokenService::class);
        $user = UserFactory::createOne();

        $this->otherConnection()->executeStatement("UPDATE users SET $column = ? WHERE id = ?", [$value, $user->getId()]);

        self::assertNull($service->issueIfCredentialsUnchanged($user));
        self::assertSame(0, $this->snapshot($user)['tokens'], 'Nessuna sessione creata');
    }

    /** @return iterable<string, array{string, string}> */
    public static function credentialChanges(): iterable
    {
        yield 'password cambiata' => ['password', '$2y$13$changedchangedchangedchangedchangedchangedchangedchang'];
        yield 'email cambiata' => ['email', 'altro@test.it'];
    }

    public function testLoginSessionIsIssuedWhenCredentialsAreUnchanged(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RefreshTokenService::class);
        $user = UserFactory::createOne();

        $session = $service->issueIfCredentialsUnchanged($user);

        self::assertInstanceOf(RefreshToken::class, $session);
        self::assertSame(['tokens' => 1, 'active' => 1, 'token_version' => 0], $this->snapshot($user));
    }

    /**
     * Il lato complementare: rotazione e login tengono il lock (preso prima di leggere lo stato) finché il nuovo
     * token non è committato, quindi una revoca concorrente lo aspetta e lo revoca. Senza quel lock l'INSERT
     * aspetterebbe comunque la revoca per la foreign key, ma dopo aver letto lo stato vecchio: il token nascerebbe
     * dopo la revoca e le sopravviverebbe.
     */
    #[DataProvider('issuingOperations')]
    public function testIssuingHoldsTheUserLockUntilTheNewTokenIsCommitted(string $operation): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RefreshTokenService::class);
        $user = UserFactory::createOne();
        $session = $operation === 'rotate' ? $service->issue($user) : null;
        $other = $this->otherConnection();
        $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $userId = (int) $user->getId();

        $probe = new class($other, $userId) {
            public ?bool $blocked = null;

            public function __construct(private readonly Connection $other, private readonly int $userId)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($this->blocked !== null || !$args->getObject() instanceof RefreshToken) {
                    return;
                }
                // Il successore sta per essere scritto: una revoca concorrente deve restare in attesa.
                try {
                    $this->other->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$this->userId]);
                    $this->blocked = false;
                } catch (LockWaitTimeoutException) {
                    $this->blocked = true;
                }
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::prePersist], $probe);

        $successor = match ($operation) {
            'rotate' => $service->rotate($session ?? throw new \LogicException('Sessione mancante')),
            'login' => $service->issueIfCredentialsUnchanged($user),
            default => throw new \LogicException("Operazione sconosciuta: $operation"),
        };

        $this->em()->getEventManager()->removeEventListener([Events::prePersist], $probe);
        self::assertTrue($probe->blocked, 'La revoca concorrente deve attendere il commit del successore');
        self::assertInstanceOf(RefreshToken::class, $successor);

        $service->revokeAllForUser($user);

        $this->em()->clear();
        self::assertSame(0, static::getContainer()->get(RefreshTokenRepository::class)->countActiveForUser($user));
        self::assertNull($service->findValid($successor->getPlainToken()), 'Il successore non sfugge alla revoca');
    }

    /** @return iterable<string, array{string}> */
    public static function issuingOperations(): iterable
    {
        yield 'rotazione' => ['rotate'];
        yield 'sessione di un login' => ['login'];
    }

    /** La revoca committata prima: la rotazione (sul token letto prima della revoca) non emette nulla. */
    public function testRotationAfterACommittedRevocationIssuesNothing(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RefreshTokenService::class);
        $user = UserFactory::createOne();
        $plain = $service->issue($user)->getPlainToken();
        $this->em()->clear();
        $read = $service->findValid($plain);
        self::assertInstanceOf(RefreshToken::class, $read);
        $user = $read->getUser();

        $service->revokeAllForUser($user);
        $rotated = $service->rotate($read);

        self::assertNull($rotated);
        self::assertSame(0, static::getContainer()->get(RefreshTokenRepository::class)->countActiveForUser($user));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ?', [$user->getId()]), 'Nessun successore');
    }

    public function testReuseStillRevokesTheFamilyAndInvalidatesAccessTokens(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RefreshTokenService::class);
        $user = UserFactory::createOne();
        $session = $service->issue($user);
        $plain = $session->getPlainToken();
        self::assertNotNull($service->rotate($session));
        $this->connection()->executeStatement(
            'UPDATE refresh_tokens SET rotated_at = ? WHERE token = ?',
            [(new \DateTimeImmutable('-60 seconds'))->format('Y-m-d H:i:s'), RefreshTokenService::hash($plain)],
        );
        $this->em()->clear();

        self::assertSame(RefreshRejection::Reuse, $service->classifyRejected($plain));

        self::assertSame(['tokens' => 2, 'active' => 0, 'token_version' => 1], $this->snapshot($user));
    }

    // -------------------- helpers --------------------

    /** Un'altra "richiesta": BEGIN + SELECT … FOR UPDATE sulla riga dell'utente, tenuto fino al rilascio. */
    private function holdUserLock(User $user): void
    {
        $other = $this->otherConnection();
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->getId()]);
    }

    private function releaseUserLock(): void
    {
        $this->otherConnection()->rollBack();
    }

    private function otherConnection(): Connection
    {
        return $this->other ??= DriverManager::getConnection($this->connection()->getParams());
    }

    /**
     * Stato letto da una connessione a parte, quindi solo ciò che è committato.
     *
     * @return array{tokens: int, active: int, token_version: int}
     */
    private function snapshot(User $user): array
    {
        $db = $this->otherConnection();

        return [
            'tokens' => (int) $db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ?', [$user->getId()]),
            'active' => (int) $db->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ? AND revoked_at IS NULL', [$user->getId()]),
            'token_version' => (int) $db->fetchOne('SELECT token_version FROM users WHERE id = ?', [$user->getId()]),
        ];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
