<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Service\AttachmentQuota;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Il lock per organizzazione che serializza controllo della quota e inserimento degli allegati.
 */
final class AttachmentQuotaLockTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    public function testRunsTheWorkAndReturnsItsResult(): void
    {
        self::bootKernel();
        $quota = static::getContainer()->get(AttachmentQuota::class);
        $org = OrganizationFactory::createOne();

        self::assertSame('fatto', $quota->serialized($org, static fn (): string => 'fatto'));
    }

    public function testTheLockIsReleasedEvenWhenTheWorkThrows(): void
    {
        self::bootKernel();
        $quota = static::getContainer()->get(AttachmentQuota::class);
        $db = static::getContainer()->get(Connection::class);
        $org = OrganizationFactory::createOne();
        $name = 'brumy_attachments_org_'.$org->getId();

        try {
            $quota->serialized($org, static function (): never {
                throw new \RuntimeException('upload fallito');
            });
            self::fail('L\'eccezione deve risalire');
        } catch (\RuntimeException) {
        }

        // Nessuno tiene più il lock: IS_FREE_LOCK vale 1.
        self::assertSame(1, (int) $db->fetchOne('SELECT IS_FREE_LOCK(:n)', ['n' => $name]));
    }

    public function testLocksAreIndependentPerOrganization(): void
    {
        self::bootKernel();
        $quota = static::getContainer()->get(AttachmentQuota::class);
        $db = static::getContainer()->get(Connection::class);
        $a = OrganizationFactory::createOne();
        $b = OrganizationFactory::createOne();

        $quota->serialized($a, function () use ($db, $a, $b): void {
            self::assertSame(0, (int) $db->fetchOne('SELECT IS_FREE_LOCK(:n)', ['n' => 'brumy_attachments_org_'.$a->getId()]));
            self::assertSame(1, (int) $db->fetchOne('SELECT IS_FREE_LOCK(:n)', ['n' => 'brumy_attachments_org_'.$b->getId()]));
        });
    }
}
