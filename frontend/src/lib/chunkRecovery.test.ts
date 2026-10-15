import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  clearChunkReloadFlag,
  installChunkErrorRecovery,
  isChunkLoadError,
  reloadOnceForChunkError,
} from './chunkRecovery';
import { mockLocationReload } from '@/test/mockReload';

const KEY = 'brumy:chunk-reload-at';

describe('reloadOnceForChunkError', () => {
  beforeEach(() => {
    clearChunkReloadFlag();
  });
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('ricarica la prima volta e lascia il segno in sessionStorage', () => {
    const reload = vi.fn();

    expect(reloadOnceForChunkError(reload, 1_000)).toBe(true);

    expect(reload).toHaveBeenCalledTimes(1);
    expect(window.sessionStorage.getItem(KEY)).toBe('1000');
  });

  it('con il flag già impostato (anche dopo un reload vero) non ricarica una seconda volta', () => {
    const reload = vi.fn();
    window.sessionStorage.setItem(KEY, '1000');

    expect(reloadOnceForChunkError(reload, 5_000)).toBe(false);

    expect(reload).not.toHaveBeenCalled();
  });

  it('più errori nella stessa pagina (chunk multipli) producono un solo reload', () => {
    const reload = vi.fn();

    reloadOnceForChunkError(reload, 1_000);
    reloadOnceForChunkError(reload, 1_001);

    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('un flag vecchio (un deploy precedente) non blocca un nuovo recupero', () => {
    const reload = vi.fn();
    window.sessionStorage.setItem(KEY, '1000');

    expect(reloadOnceForChunkError(reload, 1_000 + 10 * 60_000)).toBe(true);

    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('clearChunkReloadFlag riarma il recupero', () => {
    const reload = vi.fn();
    reloadOnceForChunkError(reload, 1_000);

    clearChunkReloadFlag();

    expect(window.sessionStorage.getItem(KEY)).toBeNull();
    expect(reloadOnceForChunkError(reload, 2_000)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(2);
  });

  it('con sessionStorage inutilizzabile ricarica comunque una volta, senza lanciare', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    const reload = vi.fn();

    expect(() => reloadOnceForChunkError(reload, 1_000)).not.toThrow();
    expect(reloadOnceForChunkError(reload, 1_001)).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(() => clearChunkReloadFlag()).not.toThrow();
  });
});

describe('isChunkLoadError', () => {
  it.each([
    'Failed to fetch dynamically imported module: https://x.it/assets/Page-abc.js',
    'error loading dynamically imported module',
    'Importing a module script failed.',
    'Unable to preload CSS for /assets/index-abc.css',
  ])('riconosce "%s"', (message) => {
    expect(isChunkLoadError(new TypeError(message))).toBe(true);
  });

  it('riconosce il ChunkLoadError dei bundler', () => {
    const error = new Error('boom');
    error.name = 'ChunkLoadError';
    expect(isChunkLoadError(error)).toBe(true);
  });

  it.each([new Error('Cannot read properties of undefined'), 'Failed to fetch dynamically imported module', null])(
    'ignora gli altri errori (%s)',
    (error) => {
      expect(isChunkLoadError(error)).toBe(false);
    },
  );
});

describe('installChunkErrorRecovery', () => {
  beforeEach(() => {
    clearChunkReloadFlag();
  });

  it('su vite:preloadError ricarica e blocca la propagazione dell\'errore; la seconda volta la lascia passare', () => {
    const reload = mockLocationReload();
    const stop = installChunkErrorRecovery();

    const first = new Event('vite:preloadError', { cancelable: true });
    window.dispatchEvent(first);
    const second = new Event('vite:preloadError', { cancelable: true });
    window.dispatchEvent(second);
    stop();

    expect(first.defaultPrevented).toBe(true);
    expect(second.defaultPrevented).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
  });
});
