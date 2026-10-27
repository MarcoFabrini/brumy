import { authFetch, authFetchBlob } from '@/api/client';

/** Foto profilo dell'utente corrente, scaricata come blob (stessa gestione della sessione di authFetch) e mostrata da object URL. */
export function fetchAvatar(): Promise<Blob> {
  return authFetchBlob('/api/auth/avatar');
}

export function uploadAvatar(file: File): Promise<{ hasAvatar: boolean }> {
  const form = new FormData();
  form.append('file', file);
  // NIENTE Content-Type: il browser imposta multipart/form-data + boundary.
  return authFetch<{ hasAvatar: boolean }>('/api/auth/avatar', {
    method: 'POST',
    body: form,
  });
}

export function deleteAvatar(): Promise<void> {
  return authFetch<void>('/api/auth/avatar', { method: 'DELETE' });
}
