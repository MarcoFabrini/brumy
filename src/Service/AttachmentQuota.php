<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Enum\AttachmentEntityType;
use App\Repository\AttachmentRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Limiti di spazio degli allegati: un massimo di file per singolo record e un tetto di byte per
 * organizzazione (`ATTACHMENTS_ORG_QUOTA_MB`, 0 = nessun limite). Senza, un membro con permesso di modifica
 * potrebbe riempire il volume dell'istanza caricando file da 10 MB all'infinito.
 *
 * Il controllo avviene prima di salvare il file. Per non farlo sforare da upload concorrenti, controllo e
 * inserimento girano dentro {@see self::serialized()}, che serializza gli upload della stessa organizzazione
 * con un lock del database (`GET_LOCK`, come la registrazione del primo utente).
 */
final class AttachmentQuota
{
    public const MAX_FILES_PER_RECORD = 20;

    public const TOO_MANY_FILES = 'upload.too_many_files';
    public const QUOTA_EXCEEDED = 'upload.quota_exceeded';

    public function __construct(
        private readonly AttachmentRepository $repo,
        private readonly Connection $connection,
        #[Autowire(env: 'int:default:app_attachments_org_quota_mb_default:ATTACHMENTS_ORG_QUOTA_MB')]
        private readonly int $orgQuotaMb,
    ) {
    }

    /**
     * Esegue `$work` tenendo un lock per organizzazione: due upload della stessa org non si sovrappongono tra il
     * controllo della quota e l'inserimento. Se il lock non arriva in 10 secondi restituisce `null` senza eseguire.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T|null
     */
    public function serialized(Organization $org, \Closure $work): mixed
    {
        $name = 'brumy_attachments_org_'.$org->getId();
        $acquired = $this->connection->fetchOne('SELECT GET_LOCK(:name, 10)', ['name' => $name]);
        if ((int) $acquired !== 1) {
            return null;
        }

        try {
            return $work();
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => $name]);
        }
    }

    /**
     * Chiave i18n dell'errore se aggiungere un file di `$newBytes` al record supera un limite, null se ci sta.
     * Il limite è inclusivo: il 20° file e l'ultimo byte della quota sono ammessi.
     */
    public function violation(Organization $org, AttachmentEntityType $type, int $entityId, int $newBytes): ?string
    {
        if ($this->repo->countByEntity($type, $entityId, $org) >= self::MAX_FILES_PER_RECORD) {
            return self::TOO_MANY_FILES;
        }

        if ($this->orgQuotaMb > 0
            && $this->repo->sumSizeBytesByOrganization($org) + $newBytes > $this->orgQuotaMb * 1024 * 1024
        ) {
            return self::QUOTA_EXCEEDED;
        }

        return null;
    }
}
