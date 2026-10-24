import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { AttachmentUploader } from './AttachmentUploader';
import { AttachmentList } from './AttachmentList';
import type { AttachmentEntityType } from '@/api/types/attachment';

export interface AttachmentsSectionProps {
  entityType: AttachmentEntityType;
  entityId: number;
  /** Veicolo in sola lettura (condiviso): niente upload né eliminazione. */
  readOnly?: boolean;
}

/**
 * Sezione allegati riusabile (veicolo, manutenzione, rifornimento): titoletto come quello delle liste, le
 * miniature e sotto la riga "Aggiungi foto o documenti" che apre il selettore file (sul telefono anche la fotocamera).
 */
export function AttachmentsSection({ entityType, entityId, readOnly = false }: AttachmentsSectionProps) {
  const { t } = useTranslation();
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className="space-y-1.5">
      <h2 id={headingId} className="px-4 text-[0.8125rem] uppercase tracking-wide text-muted-foreground">
        {t('attachment.title')}
      </h2>
      <AttachmentList entityType={entityType} entityId={entityId} readOnly={readOnly} />
      {!readOnly && <AttachmentUploader entityType={entityType} entityId={entityId} />}
    </section>
  );
}
