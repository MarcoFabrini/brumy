<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Service\Storage\AttachmentStorageInterface;
use App\Service\UserAvatar;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Due caricamenti contemporanei della foto dello stesso account: il secondo deve cancellare il file del primo, non
 * quello che c'era a inizio richiesta. Audit run-2,
 * finding `src/Controller/Api/AuthController.php:uploadAvatar-concurrent-replace-orphans-files-outside-quota`.
 */
final class UserAvatarTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    public function testReplaceDeletesTheFileCommittedByAConcurrentUploadNotTheStaleOne(): void
    {
        self::bootKernel();
        $storage = static::getContainer()->get(AttachmentStorageInterface::class);
        $first = $storage->store($this->png(), 'avatar');
        $user = UserFactory::createOne(['avatarPath' => $first]);

        // Un'altra richiesta, autenticata insieme a questa, ha già salvato la sua foto e cancellato la prima.
        $concurrent = $storage->store($this->png(), 'avatar');
        $other = DriverManager::getConnection(static::getContainer()->get(Connection::class)->getParams());
        $other->executeStatement('UPDATE users SET avatar_path = ? WHERE id = ?', [$concurrent, $user->getId()]);
        $other->close();
        $storage->delete($first);
        self::assertSame($first, $user->getAvatarPath(), 'Questa richiesta vede ancora la foto di inizio richiesta');

        $mine = $storage->store($this->png(), 'avatar');
        static::getContainer()->get(UserAvatar::class)->replace($user, $mine);

        self::assertFalse($storage->exists($concurrent), 'Il file della richiesta concorrente non resta orfano');
        self::assertTrue($storage->exists($mine));
        self::assertSame($mine, $user->getAvatarPath());
        $storage->delete($mine);
    }

    public function testRemoveDeletesTheCurrentFile(): void
    {
        self::bootKernel();
        $storage = static::getContainer()->get(AttachmentStorageInterface::class);
        $path = $storage->store($this->png(), 'avatar');
        $user = UserFactory::createOne(['avatarPath' => $path]);

        static::getContainer()->get(UserAvatar::class)->replace($user, null);

        self::assertNull($user->getAvatarPath());
        self::assertFalse($storage->exists($path));
    }

    private function png(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'avatar');
        self::assertIsString($tmp);
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true));

        return new UploadedFile($tmp, 'me.png', 'image/png', test: true);
    }
}
