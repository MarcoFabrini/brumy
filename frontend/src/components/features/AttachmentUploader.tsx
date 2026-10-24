import { useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Paperclip } from 'lucide-react';
import { ListRow, Spinner } from '@/components/ui';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useUploadAttachment } from '@/hooks/useAttachments';
import {
  ATTACHMENT_ACCEPT,
  ATTACHMENT_MAX_BYTES,
  type AttachmentEntityType,
} from '@/api/types/attachment';

export interface AttachmentUploaderProps {
  entityType: AttachmentEntityType;
  entityId: number;
}

/**
 * Riga "Aggiungi foto o documenti" che apre il file picker nativo (su mobile include la fotocamera).
 * Valida la size client-side prima dell'upload e mostra toast.
 */
export function AttachmentUploader({ entityType, entityId }: AttachmentUploaderProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const inputRef = useRef<HTMLInputElement>(null);
  const upload = useUploadAttachment(entityType, entityId);

  const onChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = ''; // reset → stesso file ri-selezionabile
    if (!file) return;

    if (file.size > ATTACHMENT_MAX_BYTES) {
      toast({ title: t('errors.upload.file_too_large'), variant: 'error' });
      return;
    }

    upload.mutate(file, {
      onSuccess: () => toast({ title: t('attachment.uploaded'), variant: 'success' }),
      onError: (err) => toast({ title: errorMessage(err), variant: 'error' }),
    });
  };

  return (
    <>
      <input
        ref={inputRef}
        type="file"
        accept={ATTACHMENT_ACCEPT}
        className="hidden"
        onChange={onChange}
      />
      <div className="overflow-hidden rounded-xl bg-card [&_.list-row-body]:border-b-0">
        <ListRow
          as="button"
          type="button"
          disabled={upload.isPending || entityId <= 0}
          onClick={() => inputRef.current?.click()}
          icon={upload.isPending ? <Spinner size="sm" /> : <Paperclip />}
          iconClassName="bg-[#8e8e93]"
          label={<span className="text-primary">{t('attachment.add')}</span>}
          className="disabled:opacity-60"
        />
      </div>
    </>
  );
}
