<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Enum\AttachmentEntityType;
use App\Enum\OrgRole;
use App\Repository\AttachmentRepository;
use App\Service\Storage\AttachmentStorageInterface;
use App\Tests\Factory\AttachmentFactory;
use App\Tests\Factory\ExpenseFactory;
use App\Tests\Factory\MaintenanceFactory;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Chi può leggere, scaricare e cancellare un allegato, e i limiti di dimensione dell'upload.
 * Il file è di chi l'ha caricato sul veicolo: l'accesso segue sempre il veicolo del record a cui è attaccato.
 */
final class AttachmentAccessTest extends ApiTestCase
{
    // -------------------- tenant isolation --------------------

    public function testAnAttachmentOfAnotherOrganizationIsNeitherDownloadableNorDeletable(): void
    {
        [, $orgB, $tokenB] = $this->createAuthenticatedUser();
        $vehicleB = VehicleFactory::createOne(['organization' => $orgB]);
        $id = $this->upload($tokenB, 'vehicle', (int) $vehicleB->getId());
        $storedPath = $this->storedPath($id);

        [, , $tokenA] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/attachments/'.$id, session: $tokenA);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('attachment.not_found', $this->jsonBody()['title']);

        $this->jsonRequest('DELETE', '/api/attachments/'.$id, session: $tokenA);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('attachment.not_found', $this->jsonBody()['title']);

        self::assertNotNull(static::getContainer()->get(AttachmentRepository::class)->find($id), 'La riga resta');
        self::assertTrue($this->storage()->exists($storedPath), 'Il file resta');
    }

    public function testListingAttachmentsOfAnotherOrganizationsRecordIsNotFound(): void
    {
        [, $orgB] = $this->createAuthenticatedUser();
        $vehicleB = VehicleFactory::createOne(['organization' => $orgB]);
        AttachmentFactory::createOne(['organization' => $orgB, 'entityId' => (string) $vehicleB->getId()]);
        [, , $tokenA] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/attachments?entityType=vehicle&entityId='.$vehicleB->getId(), session: $tokenA);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('entity.not_found', $this->jsonBody()['title']);
    }

    // -------------------- accesso al veicolo --------------------

    public function testAMemberWithoutAShareCannotDownloadOrDeleteAndTheFileSurvives(): void
    {
        [, $org, $ownerToken] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $id = $this->upload($ownerToken, 'vehicle', (int) $vehicle->getId());
        $storedPath = $this->storedPath($id);

        $member = $this->memberOf($org, OrgRole::MEMBER);

        $this->jsonRequest('GET', '/api/attachments/'.$id, session: $member);
        self::assertResponseStatusCodeSame(403);
        $this->jsonRequest('DELETE', '/api/attachments/'.$id, session: $member);
        self::assertResponseStatusCodeSame(403);
        $this->jsonRequest('GET', '/api/attachments?entityType=vehicle&entityId='.$vehicle->getId(), session: $member);
        self::assertResponseStatusCodeSame(403);

        self::assertNotNull(static::getContainer()->get(AttachmentRepository::class)->find($id));
        self::assertTrue($this->storage()->exists($storedPath));
    }

    public function testAViewerCanDownloadButNotDelete(): void
    {
        [, $org, $ownerToken] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $id = $this->upload($ownerToken, 'vehicle', (int) $vehicle->getId());

        [$viewer, , $viewerToken] = $this->createAuthenticatedUserIn($org, OrgRole::MEMBER);
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $viewer]); // viewer

        $this->useSession($viewerToken);
        $this->client->request('GET', '/api/attachments/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));

        $this->jsonRequest('DELETE', '/api/attachments/'.$id, session: $viewerToken);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(static::getContainer()->get(AttachmentRepository::class)->find($id));
    }

    #[DataProvider('recordTypes')]
    public function testAccessToARecordsAttachmentFollowsTheRecordsVehicle(string $type): void
    {
        [, $org] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $record = 'maintenance' === $type
            ? MaintenanceFactory::createOne(['organization' => $org, 'vehicle' => $vehicle])
            : ExpenseFactory::createOne(['organization' => $org, 'vehicle' => $vehicle]);
        $attachment = AttachmentFactory::createOne([
            'organization' => $org,
            'entityType' => AttachmentEntityType::from($type),
            'entityId' => (string) $record->getId(),
        ]);
        $member = $this->memberOf($org, OrgRole::MEMBER); // nessuna share sul veicolo del record

        $this->jsonRequest('GET', '/api/attachments?entityType='.$type.'&entityId='.$record->getId(), session: $member);
        self::assertResponseStatusCodeSame(403);
        $this->jsonRequest('GET', '/api/attachments/'.$attachment->getId(), session: $member);
        self::assertResponseStatusCodeSame(403);
        $this->jsonRequest('DELETE', '/api/attachments/'.$attachment->getId(), session: $member);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{string}> */
    public static function recordTypes(): iterable
    {
        yield 'maintenance' => ['maintenance'];
        yield 'expense' => ['expense'];
    }

    public function testAnAttachmentWhoseRecordIsGoneIsNotServedToAnyone(): void
    {
        // Orfano (record cancellato senza pulizia): il voter non risolve il veicolo e nega, anche all'owner dell'org
        [, $org, $token] = $this->createAuthenticatedUser();
        $attachment = AttachmentFactory::createOne(['organization' => $org, 'entityId' => '999999']);

        $this->jsonRequest('GET', '/api/attachments/'.$attachment->getId(), session: $token);

        self::assertResponseStatusCodeSame(403);
    }

    public function testADownloadWhoseFileIsMissingIsAReportedNotFound(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $attachment = AttachmentFactory::createOne([
            'organization' => $org, 'entityId' => (string) $vehicle->getId(), 'storedPath' => 'vehicle/does-not-exist.jpg',
        ]);

        $this->jsonRequest('GET', '/api/attachments/'.$attachment->getId(), session: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('attachment.file_missing', $this->jsonBody()['title']);
    }

    // -------------------- parametri della lista --------------------

    #[DataProvider('invalidListQueries')]
    public function testListRequiresAValidEntityTypeAndId(string $query): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/attachments'.$query, session: $token);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('query.entity_required', $this->jsonBody()['title']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidListQueries(): iterable
    {
        yield 'no params' => [''];
        yield 'unknown type' => ['?entityType=user&entityId=1'];
        yield 'zero id' => ['?entityType=vehicle&entityId=0'];
        yield 'negative id' => ['?entityType=vehicle&entityId=-4'];
        yield 'missing id' => ['?entityType=vehicle'];
    }

    public function testListOfAnUnknownRecordIsNotFound(): void
    {
        [, , $token] = $this->createAuthenticatedUser();

        $this->jsonRequest('GET', '/api/attachments?entityType=maintenance&entityId=999999', session: $token);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('entity.not_found', $this->jsonBody()['title']);
    }

    // -------------------- dimensione --------------------

    public function testThePerFileLimitIsInclusiveAndOneByteMoreIsRejectedWithoutStoringAnything(): void
    {
        [, $org, $token] = $this->createAuthenticatedUser();
        $vehicle = VehicleFactory::createOne(['organization' => $org]);
        $limit = 10 * 1024 * 1024;

        $tooBig = $this->makeUploadedFile('big.png', str_pad($this->pngBytes(), $limit + 1, "\0"), 'image/png');
        $this->postUpload($token, 'vehicle', (int) $vehicle->getId(), $tooBig);
        self::assertResponseStatusCodeSame(413);
        self::assertSame('upload.file_too_large', $this->jsonBody()['title']);
        self::assertCount(0, static::getContainer()->get(AttachmentRepository::class)->findAll(), 'Niente riga per un upload rifiutato');

        // Esattamente 10 MiB supera il controllo di dimensione (inclusivo): in test la quota d'organizzazione è 1 MB,
        // quindi a fermarlo è la quota (altra chiave), non il tetto per singolo file.
        $exact = $this->makeUploadedFile('exact.png', str_pad($this->pngBytes(), $limit, "\0"), 'image/png');
        $this->postUpload($token, 'vehicle', (int) $vehicle->getId(), $exact);
        self::assertResponseStatusCodeSame(413);
        self::assertSame('upload.quota_exceeded', $this->jsonBody()['title']);
    }

    // -------------------- helpers --------------------

    private function postUpload(string $token, string $type, int $entityId, UploadedFile $file): void
    {
        $this->useSession($token);
        $this->client->request(
            'POST',
            '/api/attachments',
            parameters: ['entityType' => $type, 'entityId' => (string) $entityId],
            files: ['file' => $file],
        );
    }

    private function upload(string $token, string $type, int $entityId): int
    {
        $this->postUpload($token, $type, $entityId, $this->makeUploadedFile('photo.png', $this->pngBytes(), 'image/png'));
        self::assertResponseStatusCodeSame(201);

        return (int) $this->jsonBody()['id'];
    }

    private function storedPath(int $attachmentId): string
    {
        $attachment = static::getContainer()->get(AttachmentRepository::class)->find($attachmentId);
        self::assertNotNull($attachment);

        return $attachment->getStoredPath();
    }

    private function storage(): AttachmentStorageInterface
    {
        return static::getContainer()->get(AttachmentStorageInterface::class);
    }

    /** Sessione di un nuovo membro (senza share) dell'organizzazione indicata. */
    private function memberOf(Organization $org, OrgRole $role): string
    {
        return $this->createAuthenticatedUserIn($org, $role)[2];
    }

    /** @return array{0: User, 1: Organization, 2: string} */
    private function createAuthenticatedUserIn(Organization $org, OrgRole $role): array
    {
        $user = UserFactory::createOne();
        OrganizationMemberFactory::createOne(['user' => $user, 'organization' => $org, 'role' => $role]);
        return [$user, $org, $this->sessionFor($user, $org)];
    }
}
