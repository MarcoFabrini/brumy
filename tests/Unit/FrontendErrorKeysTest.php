<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Il backend manda chiavi i18n stabili (`vehicle.not_found`), il frontend le traduce sotto `errors.*`:
 * una chiave senza voce mostra "Errore inatteso". Questo test legge il codice di `src/` e verifica che
 * ogni chiave passata ai punti da cui esce un errore verso l'utente esista in entrambi i locali.
 *
 * Punti riconosciuti (letterali con forma `dominio.codice`, commenti esclusi):
 * - `problem('x.y', …)`, `fieldProblem('campo', 'x.y')` (ProblemDetailsResponseTrait);
 * - `createNotFoundException('x.y')` e `createBadRequest('x.y')`;
 * - `new …HttpException('x.y')`, anche con la sfida (`'Session'`) davanti (ApiProblemListener ne fa il title);
 * - messaggi dei vincoli: `buildViolation('x.y')` e `message: 'x.y'` (finiscono nel campo `message` dei 422).
 *
 * Le chiavi dinamiche (concatenate, in variabili) non si vedono: restano a carico della checklist di
 * review. Un'eccezione che non è mai mostrata all'utente (es. `\RuntimeException('cipher.invalid_payload')`)
 * non passa da questi punti, quindi non serve nessuna allowlist; se un domani una chiave non fosse mai
 * mostrata all'utente, si esclude esplicitamente nel ciclo di testEveryBackendKeyIsTranslatedUnderErrors,
 * con la ragione in un commento.
 */
final class FrontendErrorKeysTest extends TestCase
{
    private const KEY = "'([a-z][a-z0-9_]*(?:\\.[a-z0-9_]+)+)'";

    /** @var array<string, list<string>>|null chiave => file in cui compare */
    private static ?array $keys = null;

    public function testKeysAreCollectedFromSource(): void
    {
        // Se i pattern smettessero di agganciare il codice il test passerebbe a vuoto: oggi sono ~60 chiavi.
        self::assertGreaterThan(40, \count(self::backendKeys()));
    }

    #[DataProvider('locales')]
    public function testEveryBackendKeyIsTranslatedUnderErrors(string $locale): void
    {
        $translations = self::errorsOf($locale);
        $missing = [];
        foreach (self::backendKeys() as $key => $files) {
            if (self::resolves($translations, $key)) {
                continue;
            }
            $missing[] = \sprintf('errors.%s (in %s)', $key, implode(', ', $files));
        }

        self::assertSame([], $missing, \sprintf("Chiavi senza traduzione in %s.json:\n", $locale));
    }

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'it' => ['it'];
        yield 'en' => ['en'];
    }

    /** @return array<string, mixed> il ramo `errors` del locale */
    private static function errorsOf(string $locale): array
    {
        $path = \dirname(__DIR__, 2).'/frontend/src/i18n/locales/'.$locale.'.json';
        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        $errors = \is_array($data) ? ($data['errors'] ?? null) : null;
        self::assertIsArray($errors, \sprintf('%s.json non ha il ramo "errors"', $locale));

        /** @var array<string, mixed> $errors */
        return $errors;
    }

    /**
     * Risolve `a.b.c` come percorso annidato (le chiavi sono nested, non con i punti nel nome).
     *
     * @param array<string, mixed> $translations
     */
    private static function resolves(array $translations, string $key): bool
    {
        $node = $translations;
        foreach (explode('.', $key) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return \is_string($node);
    }

    /** @return array<string, list<string>> */
    private static function backendKeys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $patterns = [
            '/\bproblem\(\s*'.self::KEY.'/',
            '/\bfieldProblem\(\s*\'[^\']+\'\s*,\s*'.self::KEY.'/',
            '/\bcreateNotFoundException\(\s*'.self::KEY.'/',
            '/\bcreateBadRequest\(\s*'.self::KEY.'/',
            '/\bnew\s+\\\\?(?:[A-Za-z]+\\\\)*[A-Za-z]*HttpException\(\s*(?:\'[A-Za-z]+\'\s*,\s*)?'.self::KEY.'/',
            '/\bbuildViolation\(\s*'.self::KEY.'/',
            '/\bmessage:\s*'.self::KEY.'/',
        ];

        $root = \dirname(__DIR__, 2);
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = self::withoutComments((string) file_get_contents($file->getPathname()));
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $code, $matches);
                foreach ($matches[1] as $key) {
                    $keys[$key][$relative] = $relative;
                }
            }
        }

        ksort($keys);

        return self::$keys = array_map(array_values(...), $keys);
    }

    /** Toglie commenti e docblock: gli esempi nei commenti non sono chiavi realmente emesse. */
    private static function withoutComments(string $code): string
    {
        $out = '';
        foreach (\PhpToken::tokenize($code) as $token) {
            if (!$token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                $out .= $token->text;
            }
        }

        return $out;
    }
}
