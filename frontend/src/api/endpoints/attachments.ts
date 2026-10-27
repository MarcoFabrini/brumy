import { authFetch, authFetchBlob } from '@/api/client';
import {
  adaptAttachment,
  type Attachment,
  type AttachmentEntityType,
  type AttachmentResponse,
} from '@/api/types/attachment';

export async function listAttachments(
  entityType: AttachmentEntityType,
  entityId: number,
): Promise<Attachment[]> {
  const raw = await authFetch<AttachmentResponse[]>(
    `/api/attachments?entityType=${entityType}&entityId=${entityId}`,
  );
  return raw.map(adaptAttachment);
}

export interface UploadAttachmentInput {
  entityType: AttachmentEntityType;
  entityId: number;
  file: File;
}

export async function uploadAttachment(input: UploadAttachmentInput): Promise<Attachment> {
  const form = new FormData();
  form.append('file', input.file);
  form.append('entityType', input.entityType);
  form.append('entityId', String(input.entityId));
  // NIENTE Content-Type: il browser imposta multipart/form-data + boundary.
  const raw = await authFetch<AttachmentResponse>('/api/attachments', {
    method: 'POST',
    body: form,
  });
  return adaptAttachment(raw);
}

export function deleteAttachment(id: number) {
  return authFetch<void>(`/api/attachments/${id}`, { method: 'DELETE' });
}

/** Download bytes (immagine o PDF) come blob: stessa gestione della sessione di authFetch, poi object URL. */
export function downloadAttachment(id: number): Promise<Blob> {
  return authFetchBlob(`/api/attachments/${id}`);
}
