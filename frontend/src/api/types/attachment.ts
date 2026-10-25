export const ATTACHMENT_ENTITY_TYPES = [
  'vehicle',
  'maintenance',
  'expense',
  'refueling',
  'reminder',
  'component',
] as const;
export type AttachmentEntityType = (typeof ATTACHMENT_ENTITY_TYPES)[number];

/** Whitelist allineata al backend (AttachmentController::ALLOWED_MIME). */
export const ATTACHMENT_ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';
export const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

export interface AttachmentResponse {
  id: number;
  entityType: AttachmentEntityType;
  entityId: string; // bigint serializzato come stringa
  originalFilename: string;
  mimeType: string;
  sizeBytes: number;
  createdAt: string; // ISO datetime
}

export interface Attachment {
  id: number;
  entityType: AttachmentEntityType;
  entityId: number;
  originalFilename: string;
  mimeType: string;
  sizeBytes: number;
  createdAt: string;
  isImage: boolean;
}

export function adaptAttachment(raw: AttachmentResponse): Attachment {
  return {
    id: raw.id,
    entityType: raw.entityType,
    entityId: Number(raw.entityId),
    originalFilename: raw.originalFilename,
    mimeType: raw.mimeType,
    sizeBytes: raw.sizeBytes,
    createdAt: raw.createdAt,
    isImage: raw.mimeType.startsWith('image/'),
  };
}
