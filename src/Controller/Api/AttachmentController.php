<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Attachment;
use App\Entity\User;
use App\Enum\AttachmentEntityType;
use App\Repository\AttachmentRepository;
use App\Repository\ComponentRepository;
use App\Repository\ExpenseRepository;
use App\Repository\MaintenanceRepository;
use App\Repository\RefuelingRepository;
use App\Repository\ReminderRepository;
use App\Repository\VehicleRepository;
use App\Security\Voter\AttachmentVoter;
use App\Security\Voter\VehicleVoter;
use App\Service\ActiveOrganizationResolver;
use App\Service\AttachmentQuota;
use App\Service\Storage\AttachmentStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Allegati (immagini + PDF) polimorfici. Upload via multipart, download
 * streaming diretto (BinaryFileResponse) dopo il check di autorizzazione.
 */
#[OA\Tag(name: 'Attachment')]
#[Route('/api/attachments', name: 'api_attachments_')]
#[IsGranted('ROLE_USER')]
final class AttachmentController extends AbstractController
{
    use ProblemDetailsResponseTrait;

    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB
    private const MAX_FILENAME_LENGTH = 255; // = attachments.original_filename
    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentRepository $repo,
        private readonly VehicleRepository $vehicleRepo,
        private readonly MaintenanceRepository $maintenanceRepo,
        private readonly ExpenseRepository $expenseRepo,
        private readonly RefuelingRepository $refuelingRepo,
        private readonly ReminderRepository $reminderRepo,
        private readonly ComponentRepository $componentRepo,
        private readonly ActiveOrganizationResolver $orgResolver,
        private readonly AttachmentStorageInterface $storage,
        private readonly AttachmentQuota $quota,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Lista allegati di una specifica entity. Query: ?entityType=...&entityId=...
     */
    #[OA\Get(
        summary: 'List attachments for an entity (vehicle/maintenance/expense/refueling/reminder)',
        parameters: [
            new OA\Parameter(name: 'entityType', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['vehicle','maintenance','expense','refueling','reminder'])),
            new OA\Parameter(name: 'entityId', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Array', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: Attachment::class, groups: ['attachment:list'])))),
            new OA\Response(response: 400, description: 'query.entity_required'),
            new OA\Response(response: 404, description: 'entity.not_found'),
        ],
    )]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $type = AttachmentEntityType::tryFrom((string) $request->query->get('entityType', ''));
        $id = (int) $request->query->get('entityId', 0);
        if (!$type || $id <= 0) {
            return $this->problem('query.entity_required', 400);
        }

        $org = $this->orgResolver->resolve();
        $vehicle = $this->resolveVehicleForEntity($type, $id, $org);
        if (!$vehicle) {
            throw $this->createNotFoundException('entity.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::VIEW, $vehicle);

        $items = $this->repo->findByEntity($type, $id, $org);
        return $this->jsonGroups($items, ['attachment:list']);
    }

    /**
     * Upload multipart. Campi form: `file` (binary), `entityType` (string), `entityId` (int).
     */
    #[OA\Post(
        summary: 'Upload attachment (multipart/form-data)',
        description: 'Max 10MB. Whitelist: image/jpeg, image/png, image/webp, application/pdf. MIME verified server-side. At most 20 files per record and a per-organization size cap (ATTACHMENTS_ORG_QUOTA_MB, default 1024 MB). A client file name longer than 255 characters is truncated keeping its extension.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file', 'entityType', 'entityId'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'entityType', type: 'string', enum: ['vehicle','maintenance','expense','refueling','reminder']),
                        new OA\Property(property: 'entityId', type: 'integer'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Uploaded', content: new OA\JsonContent(ref: new Model(type: Attachment::class, groups: ['attachment:read']))),
            new OA\Response(response: 400, description: 'upload.file_required / upload.invalid_file / upload.entity_required'),
            new OA\Response(response: 413, description: 'upload.file_too_large / upload.quota_exceeded'),
            new OA\Response(response: 415, description: 'upload.mime_not_allowed'),
            new OA\Response(response: 422, description: 'upload.too_many_files'),
            new OA\Response(response: 429, description: 'upload.busy (another upload of the same organization holds the quota lock)'),
        ],
    )]
    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        $entityTypeRaw = (string) $request->request->get('entityType', '');
        $entityId = (int) $request->request->get('entityId', 0);

        if (!$file) {
            return $this->problem('upload.file_required', 400);
        }
        if (!$file->isValid()) {
            return $this->problem('upload.invalid_file', 400);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return $this->problem('upload.file_too_large', 413);
        }
        // Detect MIME server-side: NON fidarsi di $file->getClientMimeType()
        $mime = $file->getMimeType() ?: '';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return $this->problem('upload.mime_not_allowed', 415);
        }

        $type = AttachmentEntityType::tryFrom($entityTypeRaw);
        if (!$type || $entityId <= 0) {
            return $this->problem('upload.entity_required', 400);
        }

        $org = $this->orgResolver->resolve();
        $vehicle = $this->resolveVehicleForEntity($type, $entityId, $org);
        if (!$vehicle) {
            throw $this->createNotFoundException('entity.not_found');
        }
        $this->denyAccessUnlessGranted(VehicleVoter::EDIT, $vehicle);

        $size = $file->getSize();
        /** @var User $user */
        $user = $this->getUser();

        // Controllo della quota, salvataggio e inserimento sotto un lock per organizzazione: due upload
        // concorrenti non possono più superare insieme il tetto.
        $response = $this->quota->serialized($org, function () use ($file, $org, $type, $entityId, $size, $mime, $user): JsonResponse {
            // Il genitore può essere stato cancellato mentre si aspettava il lock (la cancellazione prende lo stesso
            // lock, vedi AttachmentCleaner): un allegato per un'entità che non c'è più sarebbe irraggiungibile.
            if ($this->resolveVehicleForEntity($type, $entityId, $org) === null) {
                return $this->problem('entity.not_found', 404);
            }
            $quotaError = $this->quota->violation($org, $type, $entityId, $size);
            if ($quotaError === AttachmentQuota::TOO_MANY_FILES) {
                return $this->problem($quotaError, 422);
            }
            if ($quotaError !== null) {
                return $this->problem($quotaError, 413);
            }

            // La colonna è di 255 caratteri: un nome più lungo farebbe fallire l'INSERT DOPO aver salvato il file.
            $originalFilename = self::fitFilename($file->getClientOriginalName());

            // Lo store fisico avviene PRIMA del persist DB: se DB fallisce, cleanup manuale
            $storedPath = $this->storage->store($file, $type->value);

            try {
                $attachment = (new Attachment())
                    ->setOrganization($org)
                    ->setEntityType($type)
                    ->setEntityId($entityId)
                    ->setOriginalFilename($originalFilename)
                    ->setStoredPath($storedPath)
                    ->setMimeType($mime)
                    ->setSizeBytes($size)
                    ->setUploadedBy($user);

                $this->em->persist($attachment);
                $this->em->flush();
            } catch (\Throwable $e) {
                // Compensazione: il DB ha fallito, rimuoviamo il file
                $this->storage->delete($storedPath);
                throw $e;
            }

            return $this->jsonGroups($attachment, ['attachment:read'], 201);
        });

        return $response ?? $this->problem('upload.busy', 429);
    }

    /**
     * Download diretto: PHP fa l'autorizzazione e serve i byte in streaming
     * (BinaryFileResponse). Niente reverse-proxy nel mezzo che possa servire
     * il file al posto dell'app (no equivalente X-Accel-Redirect con FrankenPHP).
     */
    #[OA\Get(
        summary: 'Download attachment',
        description: 'Returns file bytes, streamed directly by the app.',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Binary file'),
            new OA\Response(response: 404, description: 'attachment.not_found / attachment.file_missing'),
        ],
    )]
    #[Route('/{id}', name: 'download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(int $id): Response
    {
        $attachment = $this->repo->findOneInOrganization($id, $this->orgResolver->resolve());
        if (!$attachment) {
            throw $this->createNotFoundException('attachment.not_found');
        }
        $this->denyAccessUnlessGranted(AttachmentVoter::VIEW, $attachment);

        if (!$this->storage->exists($attachment->getStoredPath())) {
            throw $this->createNotFoundException('attachment.file_missing');
        }

        // BinaryFileResponse è `public` di default: un allegato autorizzato non deve finire in una cache condivisa.
        $response = new BinaryFileResponse($this->storage->absolutePath($attachment->getStoredPath()), public: false);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('Content-Type', $attachment->getMimeType());
        // Il file è dell'utente: il browser non deve reinterpretarne il tipo.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $this->safeFilename($attachment->getOriginalFilename()),
        );
        return $response;
    }

    #[OA\Delete(
        summary: 'Delete attachment (DB + file)',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'attachment.not_found'),
        ],
    )]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $attachment = $this->repo->findOneInOrganization($id, $this->orgResolver->resolve());
        if (!$attachment) {
            throw $this->createNotFoundException('attachment.not_found');
        }
        $this->denyAccessUnlessGranted(AttachmentVoter::DELETE, $attachment);

        $path = $attachment->getStoredPath();
        $this->em->remove($attachment);
        $this->em->flush();
        $this->storage->delete($path);

        return new JsonResponse(null, 204);
    }

    private function resolveVehicleForEntity(AttachmentEntityType $type, int $id, \App\Entity\Organization $org): ?\App\Entity\Vehicle
    {
        return match ($type) {
            AttachmentEntityType::VEHICLE => $this->vehicleRepo->findOneInOrganization($id, $org),
            AttachmentEntityType::MAINTENANCE => $this->maintenanceRepo->findOneInOrganization($id, $org)?->getVehicle(),
            AttachmentEntityType::EXPENSE => $this->expenseRepo->findOneInOrganization($id, $org)?->getVehicle(),
            AttachmentEntityType::REFUELING => $this->refuelingRepo->findOneInOrganization($id, $org)?->getVehicle(),
            AttachmentEntityType::REMINDER => $this->reminderRepo->findOneInOrganization($id, $org)?->getVehicle(),
            AttachmentEntityType::COMPONENT => $this->componentRepo->findOneInOrganization($id, $org)?->getVehicle(),
        };
    }

    /** Accorcia il nome alla lunghezza della colonna `original_filename` mantenendo l'estensione. */
    private static function fitFilename(string $name): string
    {
        if (mb_strlen($name) <= self::MAX_FILENAME_LENGTH) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        // Un'"estensione" lunga quanto il nome stesso non è un'estensione: si taglia e basta.
        if ($extension === '' || mb_strlen($extension) > 10) {
            return mb_substr($name, 0, self::MAX_FILENAME_LENGTH);
        }

        $base = mb_substr($name, 0, self::MAX_FILENAME_LENGTH - mb_strlen($extension) - 1, 'UTF-8');

        return $base.'.'.$extension;
    }

    private function safeFilename(string $name): string
    {
        return preg_replace('/[\\\\"\r\n]/', '_', $name) ?? 'file';
    }

    private function jsonGroups(mixed $data, array $groups, int $status = 200): JsonResponse
    {
        return new JsonResponse($this->serializer->serialize($data, 'json', ['groups' => $groups]), $status, [], json: true);
    }
}
