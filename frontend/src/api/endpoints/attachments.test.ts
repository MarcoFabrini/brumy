import { describe, it, expect, vi, beforeEach } from 'vitest';
import { authFetch, authFetchBlob } from '@/api/client';
import type { AttachmentResponse } from '@/api/types/attachment';
import { deleteAttachment, downloadAttachment, listAttachments, uploadAttachment } from './attachments';

vi.mock('@/api/client', () => ({ authFetch: vi.fn(), authFetchBlob: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);
const mockedBlob = vi.mocked(authFetchBlob);

const response: AttachmentResponse = {
  id: 5,
  entityType: 'maintenance',
  entityId: '12',
  originalFilename: 'fattura.pdf',
  mimeType: 'application/pdf',
  sizeBytes: 2048,
  createdAt: '2026-03-01T10:00:00+00:00',
};

describe('endpoint allegati', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    mockedBlob.mockReset();
  });

  it('elenca per tipo e id del record e adatta le risposte', async () => {
    mockedAuthFetch.mockResolvedValue([response]);

    const items = await listAttachments('maintenance', 12);

    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/attachments?entityType=maintenance&entityId=12');
    expect(items).toHaveLength(1);
    expect(items[0]).toMatchObject({ id: 5, originalFilename: 'fattura.pdf', mimeType: 'application/pdf' });
  });

  it('upload: multipart con file, tipo e id, SENZA Content-Type (lo mette il browser col boundary)', async () => {
    mockedAuthFetch.mockResolvedValue(response);
    const file = new File(['%PDF-1.4'], 'fattura.pdf', { type: 'application/pdf' });

    await uploadAttachment({ entityType: 'maintenance', entityId: 12, file });

    const [path, init] = mockedAuthFetch.mock.calls[0] ?? [];
    expect(path).toBe('/api/attachments');
    expect(init?.method).toBe('POST');
    expect(init?.headers).toBeUndefined();
    const form = init?.body;
    expect(form).toBeInstanceOf(FormData);
    expect((form as FormData).get('entityType')).toBe('maintenance');
    expect((form as FormData).get('entityId')).toBe('12');
    expect((form as FormData).get('file')).toBeInstanceOf(File);
    expect(((form as FormData).get('file') as File).name).toBe('fattura.pdf');
  });

  it('cancella con DELETE sull\'id', async () => {
    mockedAuthFetch.mockResolvedValue(undefined);

    await deleteAttachment(5);

    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/attachments/5', { method: 'DELETE' });
  });

  it('il download passa per la fetch blob autenticata', async () => {
    const blob = new Blob(['x'], { type: 'image/png' });
    mockedBlob.mockResolvedValue(blob);

    await expect(downloadAttachment(5)).resolves.toBe(blob);

    expect(mockedBlob).toHaveBeenCalledWith('/api/attachments/5');
    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });
});
