<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Versione installata dell'app e controllo delle nuove versioni pubblicate su GitHub.
 *
 * La versione è quella di `frontend/package.json`, che il rilascio aggiorna prima del tag (l'immagine la copia).
 * Il controllo legge i tag `vX.Y.Z` del repository pubblico una volta al giorno (job Ofelia `app:update:check`) e
 * tiene l'ultima versione stabile nella cache dell'app: l'avviso è solo informativo, non scarica niente. Dopo un
 * riavvio del container la cache è vuota fino al controllo successivo. Si spegne con `UPDATE_CHECK_ENABLED=0`.
 */
final class AppUpdates
{
    public const REPOSITORY = 'MarcoFabrini/brumy';
    private const TAGS_URL = 'https://api.github.com/repos/'.self::REPOSITORY.'/tags?per_page=100';
    private const CACHE_KEY = 'app_updates.latest_version';
    /** Il controllo è giornaliero: due giorni coprono un job saltato senza tenere un dato vecchio per sempre. */
    private const CACHE_TTL = 172800;
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    private ?string $currentVersion = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%kernel.project_dir%/frontend/package.json')]
        private readonly string $packageJsonPath,
        #[Autowire(env: 'bool:default:app_update_check_enabled_default:UPDATE_CHECK_ENABLED')]
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** `X.Y.Z` del rilascio installato, `dev` se il file manca o non ha una versione valida. */
    public function currentVersion(): string
    {
        if ($this->currentVersion === null) {
            $json = is_file($this->packageJsonPath) ? json_decode((string) file_get_contents($this->packageJsonPath), true) : null;
            $version = is_array($json) ? ($json['version'] ?? null) : null;
            $this->currentVersion = is_string($version) && preg_match(self::VERSION_PATTERN, $version) === 1 ? $version : 'dev';
        }

        return $this->currentVersion;
    }

    /**
     * Chiede a GitHub l'ultima versione stabile e la memorizza. Se GitHub non risponde la cache resta com'era.
     *
     * @throws \RuntimeException
     */
    public function check(): ?string
    {
        try {
            $tags = $this->httpClient->request('GET', self::TAGS_URL, [
                'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Brumy/'.$this->currentVersion()],
                'timeout' => 10,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('GitHub non raggiungibile: '.$e->getMessage(), previous: $e);
        }

        $latest = self::latestStable(array_values(array_filter(
            array_map(static fn (mixed $tag): mixed => is_array($tag) ? ($tag['name'] ?? null) : null, $tags),
            is_string(...),
        )));
        $item = $this->cache->getItem(self::CACHE_KEY);
        $this->cache->save($item->set($latest)->expiresAfter(self::CACHE_TTL));

        return $latest;
    }

    /** Ultima versione stabile pubblicata secondo l'ultimo controllo, null se non c'è un controllo recente. */
    public function latestVersion(): ?string
    {
        $value = $this->cache->getItem(self::CACHE_KEY)->get();

        return is_string($value) ? $value : null;
    }

    /** La versione da installare, se è più recente di quella in uso (mai per una build `dev`). */
    public function availableUpdate(): ?string
    {
        $latest = $this->latestVersion();
        $current = $this->currentVersion();
        if ($latest === null || $current === 'dev') {
            return null;
        }

        return version_compare($latest, $current, '>') ? $latest : null;
    }

    public static function releaseUrl(string $version): string
    {
        return 'https://github.com/'.self::REPOSITORY.'/releases/tag/v'.$version;
    }

    /**
     * La versione più alta tra i tag `vX.Y.Z`; i pre-release (`v1.0.0-rc.1`) e gli altri tag non contano.
     *
     * @param list<string> $tagNames
     */
    public static function latestStable(array $tagNames): ?string
    {
        $latest = null;
        foreach ($tagNames as $name) {
            $version = str_starts_with($name, 'v') ? substr($name, 1) : '';
            if (preg_match(self::VERSION_PATTERN, $version) === 1 && ($latest === null || version_compare($version, $latest, '>'))) {
                $latest = $version;
            }
        }

        return $latest;
    }
}
