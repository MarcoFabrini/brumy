<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sessioni a refresh token con rotazione e rilevamento del riuso.
 *
 * Ogni login apre una famiglia di token (`family_id`); ogni `/refresh` revoca il token presentato e ne
 * emette uno nuovo nella stessa famiglia. Un token già ruotato che ricompare dopo la finestra di tolleranza
 * ({@see self::REUSE_GRACE_SECONDS}) vuol dire che due parti ne hanno una copia: chi l'ha rubato o il
 * client legittimo. Non si può sapere chi, quindi si revoca TUTTA la famiglia e l'utente rifà il login.
 * Entro la finestra è solo una corsa tra richieste legittime (due schede, un retry dopo un errore di rete):
 * la perdente riceve 401 come sempre, senza effetti sulla famiglia.
 *
 * Un token scaduto non conta come riuso (ormai inutilizzabile), né uno revocato per altro motivo (logout,
 * cambio password: `revoked_at` senza `rotated_at`).
 *
 * Ogni revoca (logout, {@see self::revokeAllForUser()}, riuso) incrementa anche `users.token_version`: gli access
 * token già emessi smettono di valere subito invece che alla scadenza (vedi {@see \App\Security\JwtUserProvider}).
 * La versione è per utente, quindi anche gli altri dispositivi perdono l'access token corrente: il loro refresh
 * token resta valido e il client si riprende con un refresh trasparente.
 *
 * Rotazione e revoche si serializzano sul lock `PESSIMISTIC_WRITE` della riga `users` dell'utente, preso per
 * primo dentro la transazione (ordine fisso: `users` poi `refresh_tokens`). Una revoca o finisce prima della
 * rotazione, che allora non trova più il token attivo, oppure aspetta il commit del successore e revoca anche lui.
 */
final class RefreshTokenService
{
    public const TTL_DAYS = 7;
    public const COOKIE_NAME = 'refresh_token';

    /** Secondi dopo la rotazione in cui un secondo uso dello stesso token è una corsa e non un riuso. */
    public const REUSE_GRACE_SECONDS = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenRepository $repo,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Senza `$familyId` apre una nuova famiglia (nuovo login o sessione ricreata); la rotazione passa la propria. */
    public function issue(User $user, ?int $activeOrganizationId = null, ?string $familyId = null): RefreshToken
    {
        $token = bin2hex(random_bytes(48)); // 96 chars, consegnato al client e mai salvato
        $expiresAt = (new \DateTimeImmutable())->modify('+'.self::TTL_DAYS.' days');

        // In DB solo l'hash: un dump o un backup non permettono di rubare le sessioni attive.
        $refreshToken = new RefreshToken($user, self::hash($token), $expiresAt, $familyId);
        $refreshToken->setPlainToken($token);
        $refreshToken->setActiveOrganizationId($activeOrganizationId);
        $this->em->persist($refreshToken);
        $this->em->flush();

        return $refreshToken;
    }

    /**
     * Apre la sessione di un login appena verificato, ma solo se email e hash della password sono ancora quelli
     * controllati: sotto lock l'utente viene riletto, quindi un reset, un cambio password o un cambio email
     * committato tra la verifica e qui (che ha già chiuso tutte le sessioni) fa ritornare null e il login fallisce
     * invece di aprire una sessione che sopravvive alla revoca. Se invece la revoca arriva dopo, aspetta il lock e
     * revoca anche questa.
     */
    public function issueIfCredentialsUnchanged(User $user): ?RefreshToken
    {
        $email = $user->getEmail();
        $passwordHash = $user->getPassword();

        return $this->em->wrapInTransaction(function () use ($user, $email, $passwordHash): ?RefreshToken {
            $this->lockUser($user);
            if ($user->getEmail() !== $email || $user->getPassword() !== $passwordHash) {
                return null;
            }

            return $this->issue($user);
        });
    }

    /**
     * Ruota il token mantenendo l'org attiva e la famiglia. Null se un'altra richiesta l'ha già ruotato
     * (due refresh concorrenti con lo stesso token non ottengono due sessioni).
     */
    public function rotate(RefreshToken $oldToken): ?RefreshToken
    {
        return $this->em->wrapInTransaction(function () use ($oldToken): ?RefreshToken {
            // Sotto lock: una revoca concorrente o è già committata (e il token non è più attivo) o aspetta il successore.
            $this->lockUser($oldToken->getUser());
            if (!$this->repo->rotateIfActive($oldToken)) {
                return null;
            }

            return $this->issue($oldToken->getUser(), $oldToken->getActiveOrganizationId(), $oldToken->getFamilyId());
        });
    }

    /**
     * Da chiamare quando `/refresh` rifiuta un token (`findValid()` null): distingue un token ruotato da poco
     * (corsa legittima), uno ruotato da tempo (riuso: revoca la famiglia e lo scrive nel log) e tutto il
     * resto. Il chiamante risponde 401 uguale in tutti e tre i casi: l'esito non va mai rivelato al client.
     */
    public function classifyRejected(string $plainToken): RefreshRejection
    {
        $token = $this->repo->findOneByHash(self::hash($plainToken));
        $rotatedAt = $token?->getRotatedAt();
        if ($token === null || $rotatedAt === null || $token->isExpired()) {
            return RefreshRejection::Invalid;
        }

        $now = new \DateTimeImmutable();
        if ($rotatedAt > $now->modify('-'.self::REUSE_GRACE_SECONDS.' seconds')) {
            return RefreshRejection::RaceLost;
        }

        $revoked = $this->em->wrapInTransaction(function () use ($token): int {
            $user = $token->getUser();
            $this->lockUser($user);
            $revoked = $this->repo->revokeFamily($token->getFamilyId());
            // Chi ha il token rubato può avere anche un access token ancora valido: smette di funzionare ora.
            $user->incrementTokenVersion();

            return $revoked;
        });
        // Mai il token (nemmeno il suo hash): solo gli id che servono a chi amministra l'istanza.
        $this->logger->warning('Refresh token reuse detected: session family revoked', [
            'user_id' => $token->getUser()->getId(),
            'family_id' => $token->getFamilyId(),
            'revoked_tokens' => $revoked,
        ]);

        return RefreshRejection::Reuse;
    }

    public function setActiveOrganization(RefreshToken $token, int $organizationId): void
    {
        $token->setActiveOrganizationId($organizationId);
        $this->em->flush();
    }

    /**
     * Logout: revoca la sessione del token e invalida gli access token dell'utente (anche quelli degli altri
     * dispositivi). Revoca l'intera famiglia, non solo il token letto: se una rotazione concorrente l'ha già
     * sostituito tra `findValid()` e il lock, il successore appartiene alla stessa famiglia e decade con lui.
     */
    public function revoke(RefreshToken $token): void
    {
        $this->em->wrapInTransaction(function () use ($token): void {
            $user = $token->getUser();
            $this->lockUser($user);
            $this->repo->revokeFamily($token->getFamilyId());
            $user->incrementTokenVersion();
        });
    }

    /**
     * Chiude tutte le sessioni dell'utente (cambio/reset password, cambio email, GDPR): revoca ogni refresh token
     * attivo e invalida ogni access token. Ritorna quanti refresh token ha revocato.
     *
     * Rilegge l'utente dal DB (vedi {@see self::lockUser()}): le modifiche non ancora flushate sull'entity `User`
     * vanno salvate prima. Dopo il ritorno l'istanza porta già la nuova `token_version`, quindi un access token
     * emesso nella stessa richiesta (la nuova sessione del client corrente) vale subito.
     */
    public function revokeAllForUser(User $user): int
    {
        return $this->em->wrapInTransaction(function () use ($user): int {
            $this->lockUser($user);
            $revoked = $this->repo->revokeAllForUser($user);
            $user->incrementTokenVersion();

            return $revoked;
        });
    }

    public function findValid(string $token): ?RefreshToken
    {
        return $this->repo->findValidByToken(self::hash($token));
    }

    /**
     * Lock `PESSIMISTIC_WRITE` sulla riga `users` dentro la transazione corrente, rileggendola: da qui al commit
     * `token_version` in memoria è quella vera e nessun'altra rotazione o revoca dello stesso utente procede.
     * Un `incrementTokenVersion()` dopo questa chiamata parte quindi dal valore corrente e `wrapInTransaction()` lo
     * scrive (flush prima del commit) ancora sotto lock: nessun aggiornamento perso.
     * Il refresh scarta le modifiche non flushate dell'entity `User` (i chiamanti le salvano prima).
     */
    private function lockUser(User $user): void
    {
        $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
    }

    /** Il token ha 384 bit di entropia: sha256 senza sale basta (non è una password). */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
