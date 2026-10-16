<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Storage allegati su volume Docker locale (`/var/storage/uploads`).
 * Path layout: `<root>/<namespace>/<YYYY>/<MM>/<random>.<ext>`.
 *
 * Whitelist MIME e size_max sono enforced a livello controller, qui ci limitiamo
 * a scrivere il file dove sicuro (mai con il filename originale).
 */
final class LocalAttachmentStorage implements AttachmentStorageInterface
{
    private readonly string $root;
    private readonly Filesystem $fs;

    public function __construct(
        #[Autowire(param: 'app.attachments_root')] string $root,
    ) {
        $this->root = rtrim($root, '/');
        $this->fs = new Filesystem();
    }

    public function store(UploadedFile $file, string $namespace): string
    {
        $now = new \DateTimeImmutable();
        $relativeDir = sprintf('%s/%s/%s', $namespace, $now->format('Y'), $now->format('m'));
        $absDir = $this->root.'/'.$relativeDir;

        // 0750 dir + 0640 file: li leggono solo il processo app (BinaryFileResponse li serve dopo il check
        // di autorizzazione lato Symfony), il worker (stesso utente) e il gruppo; nessun altro utente dell'host.
        if (!$this->fs->exists($absDir)) {
            $this->fs->mkdir($absDir, 0750);
        }

        $extension = $this->safeExtension($file);
        $filename = bin2hex(random_bytes(16)).($extension ? '.'.$extension : '');
        $file->move($absDir, $filename);
        $this->fs->chmod($absDir.'/'.$filename, 0640);

        return $relativeDir.'/'.$filename;
    }

    public function delete(string $storedPath): void
    {
        $abs = $this->resolve($storedPath);
        if ($abs !== null && $this->fs->exists($abs)) {
            $this->fs->remove($abs);
        }
    }

    public function absolutePath(string $storedPath): string
    {
        return $this->resolve($storedPath)
            ?? throw new \InvalidArgumentException('Percorso di allegato non valido.');
    }

    public function exists(string $storedPath): bool
    {
        $abs = $this->resolve($storedPath);

        return $abs !== null && $this->fs->exists($abs);
    }

    /**
     * Percorso assoluto dentro la radice, o null se `$storedPath` ne uscirebbe (segmenti `..`, byte nulli, vuoto).
     * I percorsi nascono da {@see self::store()} e stanno nel DB, quindi oggi sono sempre validi: il controllo
     * evita che una riga alterata, o un futuro import, faccia leggere o cancellare file fuori dalla radice.
     */
    private function resolve(string $storedPath): ?string
    {
        $relative = ltrim($storedPath, '/');
        if ($relative === '' || str_contains($relative, "\0") || in_array('..', explode('/', $relative), true)) {
            return null;
        }

        return $this->root.'/'.$relative;
    }

    private function safeExtension(UploadedFile $file): ?string
    {
        $ext = strtolower((string) $file->guessExtension());
        // Whitelist coerente con quella del controller
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true) ? $ext : null;
    }
}
