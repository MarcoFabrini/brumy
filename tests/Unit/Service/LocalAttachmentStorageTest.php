<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Storage\LocalAttachmentStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class LocalAttachmentStorageTest extends TestCase
{
    private string $root;
    private LocalAttachmentStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/brumy-storage-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0750, true);
        $this->storage = new LocalAttachmentStorage($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testStoredFilesAndDirectoriesAreNotReadableByOtherUsers(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF\n");
        $upload = new UploadedFile($tmp, 'fattura.pdf', 'application/pdf', null, true);

        $path = $this->storage->store($upload, 'expense');

        self::assertSame(0640, fileperms($this->storage->absolutePath($path)) & 0777);
        self::assertSame(0750, fileperms(dirname($this->storage->absolutePath($path))) & 0777);
    }

    /** @return iterable<string, array{string}> */
    public static function escapingPaths(): iterable
    {
        yield 'risale con ..' => ['expense/2026/../../../etc/passwd'];
        yield 'solo ..' => ['..'];
        yield 'dentro e fuori' => ['expense/../../outside'];
        yield 'byte nullo' => ["expense/2026/x\0.pdf"];
        yield 'vuoto' => [''];
        yield 'solo slash' => ['///'];
    }

    #[DataProvider('escapingPaths')]
    public function testPathsOutsideTheRootAreNeverResolved(string $stored): void
    {
        self::assertFalse($this->storage->exists($stored));
        $this->storage->delete($stored); // niente da cancellare, niente eccezioni
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->absolutePath($stored);
    }

    public function testRegularStoredPathsStillResolveInsideTheRoot(): void
    {
        self::assertSame($this->root.'/expense/2026/05/abc.pdf', $this->storage->absolutePath('expense/2026/05/abc.pdf'));
        self::assertSame($this->root.'/avatar/2026/05/abc', $this->storage->absolutePath('/avatar/2026/05/abc'));
    }
}
