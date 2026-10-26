import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  UPDATE_CHECK_INTERVAL_MS,
  getUpdateAvailable,
  refreshApp,
  refreshInPlace,
  resetUpdateStateForTests,
  subscribeUpdateAvailable,
  watchForUpdates,
  type WatchEnv,
} from './pwaUpdate';

function makeEnv(hasController: boolean) {
  const containerHandlers = new Map<string, () => void>();
  const docHandlers = new Map<string, () => void>();
  const doc = { visibilityState: 'visible' as DocumentVisibilityState };
  const intervals: Array<{ handler: () => void; ms: number }> = [];
  const env = {
    container: {
      controller: hasController ? ({} as ServiceWorker) : null,
      addEventListener: (type: string, cb: () => void) => containerHandlers.set(type, cb),
      removeEventListener: (type: string) => containerHandlers.delete(type),
    },
    doc: Object.assign(doc, {
      addEventListener: (type: string, cb: () => void) => docHandlers.set(type, cb),
      removeEventListener: (type: string) => docHandlers.delete(type),
    }),
    setInterval: (handler: () => void, ms: number) => intervals.push({ handler, ms }),
    clearInterval: vi.fn(),
  } as unknown as WatchEnv;
  return { env, doc, containerHandlers, docHandlers, intervals };
}

describe('watchForUpdates', () => {
  beforeEach(() => resetUpdateStateForTests());
  afterEach(() => resetUpdateStateForTests());

  it('controlla quando l\'app torna visibile, non quando va in background', () => {
    const { env, doc, docHandlers } = makeEnv(true);
    const registration = { update: vi.fn().mockResolvedValue(undefined), waiting: null };
    watchForUpdates(registration, env);

    doc.visibilityState = 'hidden';
    docHandlers.get('visibilitychange')?.();
    expect(registration.update).not.toHaveBeenCalled();

    doc.visibilityState = 'visible';
    docHandlers.get('visibilitychange')?.();
    expect(registration.update).toHaveBeenCalledTimes(1);
  });

  it('controlla ogni ora mentre è aperta e visibile', () => {
    const { env, intervals } = makeEnv(true);
    const registration = { update: vi.fn().mockResolvedValue(undefined), waiting: null };
    watchForUpdates(registration, env);

    expect(intervals).toHaveLength(1);
    expect(intervals[0]?.ms).toBe(UPDATE_CHECK_INTERVAL_MS);
    intervals[0]?.handler();
    expect(registration.update).toHaveBeenCalledTimes(1);
  });

  it('un controllo che fallisce (offline) non solleva errori', async () => {
    const { env, docHandlers } = makeEnv(true);
    const registration = { update: vi.fn().mockRejectedValue(new Error('offline')), waiting: null };
    watchForUpdates(registration, env);
    docHandlers.get('visibilitychange')?.();
    await Promise.resolve();
    expect(getUpdateAvailable()).toBe(false);
  });

  it('segnala la nuova versione quando un nuovo worker prende il controllo', () => {
    const { env, containerHandlers } = makeEnv(true);
    const listener = vi.fn();
    subscribeUpdateAvailable(listener);
    watchForUpdates({ update: vi.fn(), waiting: null }, env);

    containerHandlers.get('controllerchange')?.();
    expect(getUpdateAvailable()).toBe(true);
    expect(listener).toHaveBeenCalledTimes(1);

    containerHandlers.get('controllerchange')?.();
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('alla prima installazione il controllerchange è il claim iniziale: nessun avviso', () => {
    const { env, containerHandlers } = makeEnv(false);
    watchForUpdates({ update: vi.fn(), waiting: null }, env);

    containerHandlers.get('controllerchange')?.();
    expect(getUpdateAvailable()).toBe(false);

    // dopo il claim la pagina è controllata: un cambio successivo è una vera nuova versione
    containerHandlers.get('controllerchange')?.();
    expect(getUpdateAvailable()).toBe(true);
  });

  it('un worker già in attesa su una pagina controllata è una nuova versione', () => {
    const { env } = makeEnv(true);
    watchForUpdates({ update: vi.fn(), waiting: {} as ServiceWorker }, env);
    expect(getUpdateAvailable()).toBe(true);
  });

  it('la funzione di cleanup toglie listener e timer', () => {
    const { env, containerHandlers, docHandlers } = makeEnv(true);
    const stop = watchForUpdates({ update: vi.fn(), waiting: null }, env);
    stop();
    expect(containerHandlers.size).toBe(0);
    expect(docHandlers.size).toBe(0);
    expect(env.clearInterval).toHaveBeenCalledTimes(1);
  });
});

describe('refreshApp', () => {
  afterEach(() => resetUpdateStateForTests());

  it('ricarica anche senza service worker (dev, browser senza supporto)', async () => {
    const reload = vi.fn();
    await refreshApp(reload);
    expect(reload).toHaveBeenCalledTimes(1);
  });
});

describe('refreshInPlace', () => {
  afterEach(() => resetUpdateStateForTests());

  it('senza versioni nuove aggiorna solo i dati, senza ricaricare la pagina', async () => {
    const refetch = vi.fn().mockResolvedValue(undefined);
    const reload = vi.fn();
    await refreshInPlace(refetch, reload);
    expect(refetch).toHaveBeenCalledTimes(1);
    expect(reload).not.toHaveBeenCalled();
  });

  it('con una versione nuova già segnalata ricarica invece di aggiornare i dati', async () => {
    const { env, containerHandlers } = makeEnv(true);
    watchForUpdates({ update: vi.fn(), waiting: null }, env);
    containerHandlers.get('controllerchange')?.();

    const refetch = vi.fn();
    const reload = vi.fn();
    await refreshInPlace(refetch, reload);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(refetch).not.toHaveBeenCalled();
  });
});
