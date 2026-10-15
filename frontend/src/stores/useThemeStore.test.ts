import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { applyTheme } from './useThemeStore';

function mockSystemDark(dark: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn().mockImplementation((query: string) => ({
      matches: query === '(prefers-color-scheme: dark)' ? dark : false,
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })),
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('applyTheme', () => {
  beforeEach(() => {
    document.documentElement.classList.remove('dark');
  });

  it("'dark' e 'light' vincono sulla preferenza di sistema", () => {
    mockSystemDark(false);
    applyTheme('dark');
    expect(document.documentElement).toHaveClass('dark');

    mockSystemDark(true);
    applyTheme('light');
    expect(document.documentElement).not.toHaveClass('dark');
  });

  it("'system' segue prefers-color-scheme", () => {
    mockSystemDark(true);
    applyTheme('system');
    expect(document.documentElement).toHaveClass('dark');

    mockSystemDark(false);
    applyTheme('system');
    expect(document.documentElement).not.toHaveClass('dark');
  });
});

describe('useThemeStore (persistenza)', () => {
  // In questo ambiente di test `localStorage` non esiste: il middleware persist si disattiva in silenzio
  // se non lo trova alla creazione dello store. Lo si finge PRIMA di importare il modulo.
  let memory: Map<string, string>;

  async function freshStore() {
    memory = new Map();
    vi.stubGlobal('localStorage', {
      getItem: (k: string) => memory.get(k) ?? null,
      setItem: (k: string, v: string) => void memory.set(k, v),
      removeItem: (k: string) => void memory.delete(k),
    });
    vi.resetModules();
    return (await import('./useThemeStore')).useThemeStore;
  }

  it('di default segue il sistema', async () => {
    const store = await freshStore();
    expect(store.getState().theme).toBe('system');
  });

  it('la scelta viene salvata sotto la chiave stabile (sopravvive al reload)', async () => {
    const store = await freshStore();

    store.getState().setTheme('dark');

    const saved = JSON.parse(memory.get('brumy-theme') ?? 'null') as { state: { theme: string } } | null;
    expect(saved?.state.theme).toBe('dark');
  });

  it('ripristina la preferenza salvata al caricamento', async () => {
    const store = await freshStore();
    memory.set('brumy-theme', JSON.stringify({ state: { theme: 'light' }, version: 0 }));

    await store.persist.rehydrate();

    expect(store.getState().theme).toBe('light');
  });
});
