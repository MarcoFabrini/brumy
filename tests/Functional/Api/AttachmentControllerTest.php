<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Entity\Maintenance;
use App\Enum\AttachmentEntityType;
use App\Repository\AttachmentRepository;
use App\Tests\Factory\AttachmentFactory;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AttachmentControllerTest extends ApiTestCase
{
    public function testUploadValidImageReturns201(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $file = $this->makeUploadedFile('photo.png', $this->pngBytes(), 'image/png');

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'vehicle', 'entityId' => (string) $vehicle->getId()],
            files: ['file' => $file],
        );

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame('image/png', $body['mimeType']);
        self::assertSame('photo.png', $body['originalFilename']);
        self::assertGreaterThan(0, $body['sizeBytes']);

        $repo = static::getContainer()->get(AttachmentRepository::class);
        self::assertCount(1, $repo->findAll());
    }

    public function testUploadPdfOnMaintenanceEntity(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $maintenance = MaintenanceFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);

        $file = $this->makeUploadedFile('fattura.pdf', "%PDF-1.4\n%fake pdf\n", 'application/pdf');

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'maintenance', 'entityId' => (string) $maintenance->getId()],
            files: ['file' => $file],
        );

        self::assertResponseStatusCodeSame(201);
        self::assertSame('maintenance', $this->jsonBody()['entityType']);
    }

    public function testUploadWithoutFileReturns400(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'vehicle', 'entityId' => (string) $vehicle->getId()],
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame('upload.file_required', $this->jsonBody()['title']);
    }

    public function testUploadDisallowedMimeReturns415(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $file = $this->makeUploadedFile('script.sh', "#!/bin/bash\necho hello\n", 'application/x-sh');

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'vehicle', 'entityId' => (string) $vehicle->getId()],
            files: ['file' => $file],
        );

        self::assertResponseStatusCodeSame(415);
        self::assertSame('upload.mime_not_allowed', $this->jsonBody()['title']);
    }

    public function testUploadMissingEntityParamsReturns400(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $file = $this->makeUploadedFile('x.png', $this->pngBytes(), 'image/png');

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            files: ['file' => $file],
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame('upload.entity_required', $this->jsonBody()['title']);
    }

    public function testUploadCrossTenantVehicleReturns404(): void
    {
        [, , $tokenA] = $this->createAuthenticatedUser();
        [, $orgB] = $this->createAuthenticatedUser();
        $vehicleB = VehicleFactory::createOne(['organization' => $orgB]);

        $file = $this->makeUploadedFile('hack.png', $this->pngBytes(), 'image/png');

        $this->useSession($tokenA);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'vehicle', 'entityId' => (string) $vehicleB->getId()],
            files: ['file' => $file],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testListAttachmentsByEntity(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        // Upload 2 file
        for ($i = 1; $i <= 2; $i++) {
            $file = $this->makeUploadedFile("img$i.png", $this->pngBytes(), 'image/png');
            $this->useSession($token);
            $this->client->request(
                'POST',
                '/api/attachments',
                parameters: ['entityType' => 'vehicle', 'entityId' => (string) $vehicle->getId()],
                files: ['file' => $file],
            );
            self::assertResponseStatusCodeSame(201);
        }

        $this->jsonRequest('GET', '/api/attachments?entityType=vehicle&entityId='.$vehicle->getId(), session: $token);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->jsonBody());
    }

    public function testDownloadReturnsXAccelRedirect(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $file = $this->makeUploadedFile('photo.jpg', $this->jpegBytes(), 'image/jpeg');
        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'vehicle', 'entityId' => (string) $vehicle->getId()],
            files: ['file' => $file],
        );
        $id = $this->jsonBody()['id'];

        $this->useSession($token);
        $this->client->request('GET', '/api/attachments/'.$id);

        self::assertResponseIsSuccessful();
        $resp = $this->client->getResponse();
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $resp);
        self::assertSame('image/jpeg', $resp->headers->get('Content-Type'));
        // Un file autorizzato non va in una cache condivisa (BinaryFileResponse è `public` di default).
        $cacheControl = (string) $resp->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    #[DataProvider('refuelingAndReminderTypes')]
    public function testDownloadAndDeleteOnRefuelingAndReminder(string $type): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $entity = 'refueling' === $type
            ? RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $vehicle])
            : ReminderFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);

        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => $type, 'entityId' => (string) $entity->getId()],
            files: ['file' => $this->makeUploadedFile('photo.jpg', $this->jpegBytes(), 'image/jpeg')],
        );
        self::assertResponseStatusCodeSame(201);
        $id = $this->jsonBody()['id'];

        $this->client->request('GET', '/api/attachments/'.$id);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('DELETE', '/api/attachments/'.$id, session: $token);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refuelingAndReminderTypes(): iterable
    {
        yield 'refueling' => ['refueling'];
        yield 'reminder' => ['reminder'];
    }

    public function testDeleteAttachmentRemovesDbAndFile(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);

        $file = $this->makeUploadedFile('x.png', $this->pngBytes(), 'image/png');
        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => 'vehicle', 'entityId' => (string) $vehicle->getId()],
            files: ['file' => $file],
        );
        $id = $this->jsonBody()['id'];

        $this->jsonRequest('DELETE', '/api/attachments/'.$id, session: $token);
        self::assertResponseStatusCodeSame(204);

        $repo = static::getContainer()->get(AttachmentRepository::class);
        self::assertNull($repo->find($id));
    }

    public function testListReturnsAttachmentsSeededViaFactory(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        AttachmentFactory::createOne([
            'organization' => $org,
            'entityType' => AttachmentEntityType::VEHICLE,
            'entityId' => (string) $vehicle->getId(),
        ]);

        $this->jsonRequest(
            'GET',
            '/api/attachments?entityType=vehicle&entityId='.$vehicle->getId(),
            session: $token,
        );

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonBody());
    }

    // -------------------- LIMITI --------------------

    /** Quota di test: 1 MB (vedi `app_attachments_org_quota_mb_default` in `when@test`). */
    private const TEST_QUOTA_BYTES = 1024 * 1024;

    private function uploadTo(string $token, string $entityType, int|string|null $entityId, string $name = 'photo.png'): void
    {
        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => $entityType, 'entityId' => (string) $entityId],
            files: ['file' => $this->makeUploadedFile($name, $this->pngBytes(), 'image/png')],
        );
    }

    private function seedAttachment(object $org, string $entityId, int $sizeBytes, string $type = 'vehicle'): void
    {
        AttachmentFactory::createOne([
            'organization' => $org,
            'entityType' => AttachmentEntityType::from($type),
            'entityId' => $entityId,
            'sizeBytes' => $sizeBytes,
        ]);
    }

    public function testARecordAcceptsTwentyFilesAndRejectsTheTwentyFirst(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $other = VehicleFactory::createOne(['organization' => $org]);
        for ($i = 0; $i < 19; ++$i) {
            $this->seedAttachment($org, (string) $vehicle->getId(), 100);
        }

        $this->uploadTo($token, 'vehicle', $vehicle->getId());
        self::assertResponseStatusCodeSame(201, 'Il ventesimo file ci sta');

        $this->uploadTo($token, 'vehicle', $vehicle->getId());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('upload.too_many_files', $this->jsonBody()['title']);
        $repo = static::getContainer()->get(AttachmentRepository::class);
        self::assertCount(20, $repo->findByEntity(AttachmentEntityType::VEHICLE, (int) $vehicle->getId(), $org));

        // Il limite è per record: un altro veicolo, o lo stesso id su un altro tipo, non è toccato
        $this->uploadTo($token, 'vehicle', $other->getId());
        self::assertResponseStatusCodeSame(201);
    }

    #[DataProvider('quotaBoundary')]
    public function testOrganizationQuotaBoundary(int $bytesOverTheLimit, int $expectedStatus): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $maintenance = MaintenanceFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        // Lo spazio già occupato sta su un altro record: la quota è dell'organizzazione, non del record.
        // Con 0 il nuovo file arriva esattamente al limite, con 1 lo supera di un byte.
        $this->seedAttachment($org, (string) $maintenance->getId(), self::TEST_QUOTA_BYTES - strlen($this->pngBytes()) + $bytesOverTheLimit, 'maintenance');

        $this->uploadTo($token, 'vehicle', $vehicle->getId());

        self::assertResponseStatusCodeSame($expectedStatus);
        if ($expectedStatus === 413) {
            self::assertSame('upload.quota_exceeded', $this->jsonBody()['title']);
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function quotaBoundary(): iterable
    {
        yield 'esattamente al limite' => [0, 201];
        yield 'un byte oltre' => [1, 413];
        yield 'molto oltre' => [500_000, 413];
    }

    public function testQuotaIsPerOrganization(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        // Un'altra organizzazione ha già riempito la sua quota: non deve toccare la nostra
        $otherOrg = OrganizationFactory::createOne();
        $otherVehicle = VehicleFactory::createOne(['organization' => $otherOrg]);
        $this->seedAttachment($otherOrg, (string) $otherVehicle->getId(), self::TEST_QUOTA_BYTES);

        $this->uploadTo($token, 'vehicle', $vehicle->getId());

        self::assertResponseStatusCodeSame(201);
    }

    public function testFileCountIsPerOrganizationToo(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        // Stesso entityId di un'altra organizzazione (id polimorfici non univoci tra org): non conta
        $otherOrg = OrganizationFactory::createOne();
        for ($i = 0; $i < 20; ++$i) {
            $this->seedAttachment($otherOrg, (string) $vehicle->getId(), 100);
        }

        $this->uploadTo($token, 'vehicle', $vehicle->getId());

        self::assertResponseStatusCodeSame(201);
    }

    public function testTooLongClientFilenameIsTruncatedKeepingTheExtension(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $filesBefore = $this->storedFiles();

        $this->uploadTo($token, 'vehicle', $vehicle->getId(), str_repeat('è', 300).'.png');

        self::assertResponseStatusCodeSame(201);
        $name = $this->jsonBody()['originalFilename'];
        self::assertSame(255, mb_strlen($name));
        self::assertStringEndsWith('.png', $name);
        self::assertSame(str_repeat('è', 251).'.png', $name);
        self::assertCount(count($filesBefore) + 1, $this->storedFiles(), 'Un file su disco per un allegato');
    }

    public function testAFilenameWithinTheLimitIsStoredUntouched(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $name = str_repeat('b', 251).'.png'; // 255 esatti

        $this->uploadTo($token, 'vehicle', $vehicle->getId(), $name);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($name, $this->jsonBody()['originalFilename']);
    }

    public function testNoOrphanFileIsLeftWhenTheDatabaseInsertFails(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $filesBefore = $this->storedFiles();

        // Stesso container per tutta la richiesta; il flush dell'allegato fallisce dopo che il file è già stato scritto
        $this->client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getEventManager()->addEventListener(Events::preFlush, new class {
            public function preFlush(PreFlushEventArgs $args): void
            {
                throw new \RuntimeException('INSERT simulato fallito');
            }
        });

        $this->uploadTo($token, 'vehicle', $vehicle->getId());

        self::assertResponseStatusCodeSame(500);
        self::assertSame($filesBefore, $this->storedFiles(), 'La compensazione rimuove il file già salvato');
    }

    /**
     * Il record viene cancellato dopo il controllo iniziale dell'upload (qui: appena caricato, da un'altra
     * connessione) ma prima che l'upload prenda il lock: l'upload ricontrolla sotto lock e non lascia né riga né file.
     * Audit run-2, finding `src/Service/AttachmentCleaner.php:upload-vs-parent-delete-orphan-attachments-consume-org-quota`.
     */
    public function testUploadForARecordDeletedWhileWaitingCreatesNoOrphan(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $maintenance = MaintenanceFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        $maintenanceId = (int) $maintenance->getId();
        $filesBefore = $this->storedFiles();

        $db = static::getContainer()->get(Connection::class);
        $other = DriverManager::getConnection($db->getParams());
        $deleter = new class($other, $maintenanceId) {
            private bool $done = false;

            public function __construct(private readonly Connection $other, private readonly int $id)
            {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                $entity = $args->getObject();
                if ($this->done || !$entity instanceof Maintenance || $entity->getId() !== $this->id) {
                    return;
                }
                $this->done = true;
                $this->other->executeStatement('DELETE FROM maintenances WHERE id = ?', [$this->id]);
            }
        };
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear(); // il controllo iniziale deve caricare il record dal DB (e far scattare postLoad)
        $em->getEventManager()->addEventListener([Events::postLoad], $deleter);

        try {
            $this->uploadTo($token, 'maintenance', $maintenanceId);
        } finally {
            $em->getEventManager()->removeEventListener([Events::postLoad], $deleter);
            $other->close();
        }

        self::assertResponseStatusCodeSame(404);
        self::assertSame('entity.not_found', $this->jsonBody()['title']);
        self::assertSame(0, static::getContainer()->get(AttachmentRepository::class)->count(['entityId' => (string) $maintenanceId]));
        self::assertSame($filesBefore, $this->storedFiles(), 'Nessun file salvato');
    }

    /** @return list<string> percorsi dei file nello storage di test */
    private function storedFiles(): array
    {
        $root = (string) static::getContainer()->getParameter('app.attachments_root');
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = (string) $file;
        }
        sort($files);

        return $files;
    }
}
