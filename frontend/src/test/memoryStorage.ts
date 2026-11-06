import { vi } from 'vitest';

/** In questo ambiente di test `localStorage` non esiste: ne mette uno in memoria (tolto da `vi.unstubAllGlobals`). */
export function stubLocalStorage(): void {
  const memory = new Map<string, string>();
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => memory.get(k) ?? null,
    setItem: (k: string, v: string) => void memory.set(k, v),
    removeItem: (k: string) => void memory.delete(k),
  });
}
