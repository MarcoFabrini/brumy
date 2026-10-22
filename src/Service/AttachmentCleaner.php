<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\Expense;
use App\Entity\Maintenance;
use App\Entity\Organization;
use App\Entity\Refueling;
use App\Entity\Reminder;
use App\Entity\Vehicle;
use App\Enum\AttachmentEntityType;
use App\Service\Storage\AttachmentStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Gli allegati sono polimorfici (`entityType` + `entityId`, senza FK): cancellare un veicolo o un
 * record collegato NON li rimuove da solo. Qui righe e file fisici vengono eliminati insieme
 * all'entità: le righe nella stessa transazione, i file dopo il commit (un file già cancellato
 * con un rollback sarebbe irrecuperabile, un file rimasto orfano no).
 *
 * Record e veicoli si cancellano sotto lo stesso lock per organizzazione degli upload
 * ({@see AttachmentQuota::serialized()}), con la transazione committata prima del rilascio: un upload in coda
 * ricontrolla il genitore dopo aver preso il lock e non può inserire un allegato per un'entità appena cancellata
 * (resterebbe invisibile e non cancellabile, ma conterebbe nella quota dell'org).
 */
final class AttachmentCleaner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentStorageInterface $storage,
        private readonly AttachmentQuota $quota,
    ) {
    }

    /** Elimina un record collegato a un veicolo (manutenzione, spesa, rifornimento, promemoria) con i suoi allegati. */
    public function removeRecord(Maintenance|Expense|Refueling|Reminder $record): void
    {
        $type = match (true) {
            $record instanceof Maintenance => AttachmentEntityType::MAINTENANCE,
            $record instanceof Expense => AttachmentEntityType::EXPENSE,
            $record instanceof Refueling => AttachmentEntityType::REFUELING,
            $record instanceof Reminder => AttachmentEntityType::REMINDER,
        };

        $paths = [];
        $this->inTransactionUnderUploadLock($record->getOrganization(), function () use ($record, $type, &$paths): void {
            $paths = $this->detach($record->getOrganization(), [$type->value => [(int) $record->getId()]]);
            $this->em->remove($record);
        });
        $this->deleteFiles($paths);
    }

    /** Elimina il veicolo, i suoi figli (cascade DB) e tutti gli allegati collegati. */
    public function removeVehicle(Vehicle $vehicle): void
    {
        $paths = [];
        $this->inTransactionUnderUploadLock($vehicle->getOrganization(), function () use ($vehicle, &$paths): void {
            $paths = $this->detach($vehicle->getOrganization(), [
                AttachmentEntityType::VEHICLE->value => [(int) $vehicle->getId()],
                AttachmentEntityType::MAINTENANCE->value => $this->childIds(Maintenance::class, $vehicle),
                AttachmentEntityType::EXPENSE->value => $this->childIds(Expense::class, $vehicle),
                AttachmentEntityType::REFUELING->value => $this->childIds(Refueling::class, $vehicle),
                AttachmentEntityType::REMINDER->value => $this->childIds(Reminder::class, $vehicle),
            ]);
            $this->em->remove($vehicle);
        });
        $this->deleteFiles($paths);
    }

    /** Elimina l'organizzazione (le righe vanno via col cascade DB) e i file di tutti i suoi allegati. */
    public function removeOrganization(Organization $org): void
    {
        $paths = $this->organizationPaths($org);
        $this->em->remove($org);
        $this->em->flush();
        $this->deleteFiles($paths);
    }

    /**
     * Path dei file di tutti gli allegati dell'organizzazione (da raccogliere PRIMA della rimozione
     * dell'org, poi passare a {@see self::deleteFiles()} a commit avvenuto).
     *
     * @return list<string>
     */
    public function organizationPaths(Organization $org): array
    {
        /** @var list<string> $paths */
        $paths = $this->em->createQuery('SELECT a.storedPath FROM '.Attachment::class.' a WHERE a.organization = :org')
            ->setParameter('org', $org)
            ->getSingleColumnResult();

        return $paths;
    }

    /** @param list<string> $paths */
    public function deleteFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $this->storage->delete($path);
            } catch (\Throwable) {
                // File già assente o non rimovibile: il DB è coerente, non blocchiamo la richiesta.
            }
        }
    }

    /**
     * Esegue `$work` in una transazione tenendo il lock degli upload dell'org; 429 `upload.busy` se il lock non arriva.
     *
     * @param \Closure(): void $work
     */
    private function inTransactionUnderUploadLock(Organization $org, \Closure $work): void
    {
        $done = $this->quota->serialized($org, function () use ($work): true {
            $this->em->wrapInTransaction($work);

            return true;
        });
        if ($done === null) {
            throw new TooManyRequestsHttpException(null, 'upload.busy');
        }
    }

    /**
     * @param array<string, list<int>> $idsByType entityType => id delle entità
     *
     * @return list<string> path dei file degli allegati eliminati
     */
    private function detach(Organization $org, array $idsByType): array
    {
        $paths = [];
        foreach ($idsByType as $type => $ids) {
            if ($ids === []) {
                continue;
            }
            $params = [
                'org' => $org,
                'type' => AttachmentEntityType::from($type),
                'ids' => array_map('strval', $ids),
            ];
            /** @var list<string> $found */
            $found = $this->em->createQuery(
                'SELECT a.storedPath FROM '.Attachment::class.' a WHERE a.organization = :org AND a.entityType = :type AND a.entityId IN (:ids)',
            )->setParameters($params)->getSingleColumnResult();
            array_push($paths, ...$found);

            $this->em->createQuery(
                'DELETE FROM '.Attachment::class.' a WHERE a.organization = :org AND a.entityType = :type AND a.entityId IN (:ids)',
            )->setParameters($params)->execute();
        }

        return $paths;
    }

    /**
     * @param class-string $entity
     *
     * @return list<int>
     */
    private function childIds(string $entity, Vehicle $vehicle): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->em->createQuery('SELECT e.id FROM '.$entity.' e WHERE e.vehicle = :vehicle')
            ->setParameter('vehicle', $vehicle)
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }
}
