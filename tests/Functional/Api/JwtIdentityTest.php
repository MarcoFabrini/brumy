<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\OrgRole;
use App\Service\Gdpr\GdprDeleteService;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * L'identità di un access token è il claim `user_id`, non l'email: il token vale solo se `username` è ancora l'email
 * di quell'utente e `token_version` la sua versione corrente (App\Security\JwtUserProvider).
 * Audit run-2, finding `auth/jwt-identity-bound-to-mutable-email`.
 *
 * I token dei casi sintetici sono firmati con le chiavi di test tramite l'encoder Lexik, senza passare dal
 * JwtCreatedListener: così si possono omettere o falsare i claim.
 */
final class JwtIdentityTest extends ApiTestCase
{
    public function testACoherentTokenIsAccepted(): void
    {
        $alice = UserFactory::createOne(['email' => 'alice@test.it']);

        $this->jsonRequest('GET', '/api/auth/me', accessToken: $this->sign([
            'username' => 'alice@test.it',
            'user_id' => $alice->getId(),
            'token_version' => 0,
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame($alice->getId(), $this->jsonBody()['id']);
    }

    /**
     * @param array<string, mixed> $claims claim del token; i segnaposto `{alice}`/`{victim}` diventano id reali
     */
    #[DataProvider('incoherentTokens')]
    public function testAnIncoherentTokenIsRejected(array $claims): void
    {
        $alice = UserFactory::createOne(['email' => 'alice@test.it']);
        $victim = UserFactory::createOne(['email' => 'victim@test.it']);
        $ids = ['{alice}' => $alice->getId(), '{victim}' => $victim->getId()];
        $claims = array_map(static fn (mixed $v): mixed => is_string($v) && isset($ids[$v]) ? $ids[$v] : $v, $claims);

        $this->jsonRequest('GET', '/api/auth/me', accessToken: $this->sign($claims));

        self::assertResponseStatusCodeSame(401);
        self::assertSame('http.401', $this->jsonBody()['title']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function incoherentTokens(): iterable
    {
        yield 'id di un utente, email di un altro' => [['username' => 'victim@test.it', 'user_id' => '{alice}', 'token_version' => 0]];
        yield 'email giusta, id di un altro' => [['username' => 'alice@test.it', 'user_id' => '{victim}', 'token_version' => 0]];
        yield 'user_id inesistente' => [['username' => 'alice@test.it', 'user_id' => 999999999, 'token_version' => 0]];
        yield 'user_id mancante' => [['username' => 'alice@test.it', 'token_version' => 0]];
        yield 'user_id non intero' => [['username' => 'alice@test.it', 'user_id' => 'x', 'token_version' => 0]];
        yield 'token_version mancante (token emesso prima del deploy)' => [['username' => 'alice@test.it', 'user_id' => '{alice}']];
        yield 'token_version diversa dalla corrente' => [['username' => 'alice@test.it', 'user_id' => '{alice}', 'token_version' => 1]];
        yield 'token_version non intera' => [['username' => 'alice@test.it', 'user_id' => '{alice}', 'token_version' => '0']];
    }

    public function testATokenIssuedBeforeAnEmailChangeDoesNotAuthenticateTheNextOwnerOfTheAddress(): void
    {
        // Scenario dell'audit: A passa a v@example.test, tiene il token T, poi torna all'email di prima.
        [$alice, , $initial] = $this->createAuthenticatedUser();
        $originalEmail = $alice->getEmail();
        $this->jsonRequest('PUT', '/api/auth/profile', $this->profile('v@example.test'), accessToken: $initial);
        self::assertResponseIsSuccessful();
        $kept = (string) $this->jsonBody()['access_token'];
        $this->jsonRequest('PUT', '/api/auth/profile', $this->profile($originalEmail), accessToken: $kept);
        self::assertResponseIsSuccessful();

        // V ottiene l'indirizzo ed è membro di un'organizzazione O.
        $victim = UserFactory::createOne(['email' => 'v@example.test']);
        $victimOrg = OrganizationFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $victim, 'organization' => $victimOrg, 'role' => OrgRole::OWNER]);

        $this->jsonRequest('GET', '/api/auth/me', accessToken: $kept);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('http.401', $this->jsonBody()['title']);

        $this->jsonRequest('POST', '/api/auth/switch-org', ['organizationId' => $victimOrg->getId()], accessToken: $kept);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('http.401', $this->jsonBody()['title']);
    }

    public function testATokenOfAnErasedAccountDoesNotAuthenticateTheNextOwnerOfTheAddress(): void
    {
        [$erased, , $token] = $this->createAuthenticatedUser();
        $email = $erased->getEmail();

        static::getContainer()->get(GdprDeleteService::class)->anonymize($erased);
        UserFactory::createOne(['email' => $email]);

        $this->jsonRequest('GET', '/api/auth/me', accessToken: $token);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('http.401', $this->jsonBody()['title']);
    }

    // -------------------- helpers --------------------

    /** @param array<string, mixed> $claims */
    private function sign(array $claims): string
    {
        return static::getContainer()->get(JWTEncoderInterface::class)->encode($claims);
    }

    /** @return array<string, string> */
    private function profile(string $email): array
    {
        return [
            'email' => $email,
            'firstName' => 'Anna',
            'lastName' => 'Rossi',
            'locale' => 'it',
            'currentPassword' => UserFactory::DEFAULT_PASSWORD,
        ];
    }
}
