import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePushSubscription } from './usePushSubscription';
import { authFetch } from '@/api/client';
import { stubLocalStorage } from '@/test/memoryStorage';
import { rememberPushEndpoint, rememberedPushEndpoint } from '@/lib/webPush';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));

const mockedAuthFetch = vi.mocked(authFetch);

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('usePushSubscription', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    stubLocalStorage();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    // serviceWorker eventualmente iniettato dai test
    if ('serviceWorker' in navigator) {
      // @ts-expect-error cleanup del property override di test
      delete navigator.serviceWorker;
    }
  });

  it('chiede la VAPID public key a runtime al mount', async () => {
    mockedAuthFetch.mockResolvedValue({ publicKey: 'abc' });
    renderHook(() => usePushSubscription(), { wrapper });
    await waitFor(() =>
      expect(mockedAuthFetch).toHaveBeenCalledWith('/api/push-subscriptions/vapid-public-key'),
    );
  });

  it('ritorna "unsupported" senza service worker / PushManager (jsdom)', async () => {
    mockedAuthFetch.mockResolvedValue({ publicKey: 'abc' });
    const { result } = renderHook(() => usePushSubscription(), { wrapper });
    await waitFor(() => expect(result.current.status).toBe('unsupported'));
  });

  it('ritorna "not-configured" quando la chiave runtime è vuota', async () => {
    // Simula un browser che supporta Push ma istanza senza VAPID configurata.
    vi.stubGlobal('PushManager', class {});
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: { ready: new Promise(() => {}) },
    });
    mockedAuthFetch.mockResolvedValue({ publicKey: '' });

    const { result } = renderHook(() => usePushSubscription(), { wrapper });
    await waitFor(() => expect(result.current.status).toBe('not-configured'));
  });

  describe('con Push API presente e chiave VAPID configurata', () => {
    function installServiceWorker(sw: Record<string, unknown>) {
      vi.stubGlobal('PushManager', class {});
      vi.stubGlobal('Notification', { permission: 'default' });
      // jsdom non implementa isSecureContext (nei browser reali è sempre definito).
      vi.stubGlobal('isSecureContext', true);
      Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: sw });
      mockedAuthFetch.mockResolvedValue({ publicKey: 'abc' });
    }

    function activeRegistration(subscription: unknown) {
      return { active: {}, pushManager: { getSubscription: vi.fn().mockResolvedValue(subscription) } };
    }

    it('"unsubscribed" (pulsante abilita visibile) con SW attivo e nessuna subscription', async () => {
      installServiceWorker({
        getRegistration: vi.fn().mockResolvedValue(activeRegistration(null)),
        register: vi.fn(),
        ready: new Promise(() => {}),
      });

      const { result } = renderHook(() => usePushSubscription(), { wrapper });
      await waitFor(() => expect(result.current.status).toBe('unsubscribed'));
    });

    it('"subscribed" se il browser ha già una subscription', async () => {
      installServiceWorker({
        getRegistration: vi.fn().mockResolvedValue(activeRegistration({ endpoint: 'https://push' })),
        register: vi.fn(),
        ready: new Promise(() => {}),
      });

      const { result } = renderHook(() => usePushSubscription(), { wrapper });
      await waitFor(() => expect(result.current.status).toBe('subscribed'));
    });

    it('se nessun SW è registrato lo registra da sé e usa `ready` (non resta muto)', async () => {
      const reg = activeRegistration(null);
      const register = vi.fn().mockResolvedValue({ active: null });
      installServiceWorker({
        getRegistration: vi.fn().mockResolvedValue(undefined),
        register,
        ready: Promise.resolve(reg),
      });

      const { result } = renderHook(() => usePushSubscription(), { wrapper });
      await waitFor(() => expect(result.current.status).toBe('unsubscribed'));
      expect(register).toHaveBeenCalledWith('/sw.js', { scope: '/' });
    });

    it('"sw-unavailable" (non "unsupported") se la registrazione fallisce', async () => {
      // Es. certificato non fidato / contesto insicuro: register() lancia SecurityError.
      installServiceWorker({
        getRegistration: vi.fn().mockResolvedValue(undefined),
        register: vi.fn().mockRejectedValue(new DOMException('insecure', 'SecurityError')),
        ready: new Promise(() => {}),
      });

      const { result } = renderHook(() => usePushSubscription(), { wrapper });
      await waitFor(() => expect(result.current.status).toBe('sw-unavailable'));
    });

    it('"sw-unavailable" senza secure context, senza nemmeno tentare la registrazione', async () => {
      const register = vi.fn();
      installServiceWorker({
        getRegistration: vi.fn().mockResolvedValue(undefined),
        register,
        ready: new Promise(() => {}),
      });
      vi.stubGlobal('isSecureContext', false);

      const { result } = renderHook(() => usePushSubscription(), { wrapper });
      await waitFor(() => expect(result.current.status).toBe('sw-unavailable'));
      expect(register).not.toHaveBeenCalled();
    });

    it('"denied" se il permesso è già negato: nessuna registrazione del service worker', async () => {
      const register = vi.fn();
      installServiceWorker({ getRegistration: vi.fn(), register, ready: new Promise(() => {}) });
      vi.stubGlobal('Notification', { permission: 'denied' });

      const { result } = renderHook(() => usePushSubscription(), { wrapper });

      await waitFor(() => expect(result.current.status).toBe('denied'));
      expect(register).not.toHaveBeenCalled();
    });

    it('scarta la subscription creata con una vecchia chiave VAPID (chiavi rigenerate) e riparte da "unsubscribed"', async () => {
      const stale = {
        endpoint: 'https://push/old',
        options: { applicationServerKey: new Uint8Array([9, 9, 9]).buffer },
        unsubscribe: vi.fn().mockResolvedValue(true),
      };
      installServiceWorker({
        getRegistration: vi.fn().mockResolvedValue(activeRegistration(stale)),
        register: vi.fn(),
        ready: new Promise(() => {}),
      });

      const { result } = renderHook(() => usePushSubscription(), { wrapper });

      await waitFor(() => expect(result.current.status).toBe('unsubscribed'));
      expect(stale.unsubscribe).toHaveBeenCalledTimes(1);
    });

    describe('azioni', () => {
      /** Esegue l'azione dentro act() e ne restituisce l'errore (act con un thenable non va dato a `rejects`). */
      async function actAndCatch(action: () => Promise<void>): Promise<unknown> {
        let caught: unknown;
        await act(async () => {
          try {
            await action();
          } catch (e) {
            caught = e;
          }
        });
        return caught;
      }

      function browserSubscription() {
        return {
          endpoint: 'https://push.example/endpoint-1',
          getKey: (name: string) => new TextEncoder().encode(name === 'p256dh' ? 'chiave' : 'auth').buffer,
          unsubscribe: vi.fn().mockResolvedValue(true),
        };
      }

      /** SW attivo senza subscription iniziale; `subscribe` del browser restituisce `created`. */
      function setupReady(created: ReturnType<typeof browserSubscription>, existing: unknown = null) {
        const pushManager = {
          getSubscription: vi.fn().mockResolvedValue(existing),
          subscribe: vi.fn().mockResolvedValue(created),
        };
        const registration = { active: {}, pushManager };
        installServiceWorker({
          getRegistration: vi.fn().mockResolvedValue(registration),
          register: vi.fn(),
          ready: Promise.resolve(registration),
        });
        return pushManager;
      }

      function routeFetch(onSubscriptionsPost: () => Promise<unknown>) {
        mockedAuthFetch.mockImplementation(async (path, init) => {
          if (path === '/api/push-subscriptions/vapid-public-key') return { publicKey: 'abc' };
          if (path === '/api/push-subscriptions' && init?.method === 'POST') return onSubscriptionsPost();
          if (path === '/api/push-subscriptions/unsubscribe') return onSubscriptionsPost();
          throw new Error(`unexpected fetch ${String(path)}`);
        });
      }

      it('iscrive: chiede il permesso, registra il device sul server con endpoint e chiavi, poi "subscribed"', async () => {
        const created = browserSubscription();
        setupReady(created);
        routeFetch(async () => undefined);
        vi.stubGlobal('Notification', { permission: 'default', requestPermission: vi.fn().mockResolvedValue('granted') });
        const { result } = renderHook(() => usePushSubscription(), { wrapper });
        await waitFor(() => expect(result.current.status).toBe('unsubscribed'));

        await act(() => result.current.subscribe());

        expect(result.current.status).toBe('subscribed');
        const post = mockedAuthFetch.mock.calls.find(([path]) => path === '/api/push-subscriptions');
        const body = JSON.parse(String(post?.[1]?.body)) as Record<string, string>;
        expect(body).toMatchObject({ platform: 'web', endpoint: created.endpoint });
        expect(atob(body.p256dh ?? '')).toBe('chiave');
        expect(atob(body.authSecret ?? '')).toBe('auth');
        expect(rememberedPushEndpoint()).toBe(created.endpoint);
      });

      it('accese qui e perse dal browser (iPhone dopo un aggiornamento): le ricrea da sola e mostra "subscribed"', async () => {
        rememberPushEndpoint('https://push.example/perso');
        const created = browserSubscription();
        const pushManager = setupReady(created);
        let current: unknown = null;
        pushManager.getSubscription.mockImplementation(async () => current);
        pushManager.subscribe.mockImplementation(async () => {
          current = created;
          return created;
        });
        routeFetch(async () => undefined);
        vi.stubGlobal('Notification', { permission: 'granted', requestPermission: vi.fn() });

        const { result } = renderHook(() => usePushSubscription(), { wrapper });

        await waitFor(() => expect(result.current.status).toBe('subscribed'));
        expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
        expect(mockedAuthFetch).toHaveBeenCalledWith('/api/push-subscriptions/unsubscribe', expect.objectContaining({
          body: JSON.stringify({ platform: 'web', endpoint: 'https://push.example/perso' }),
        }));
        expect(rememberedPushEndpoint()).toBe(created.endpoint);
      });

      it('mai accese qui: senza subscription resta "unsubscribed" e non ne crea', async () => {
        const pushManager = setupReady(browserSubscription());
        routeFetch(async () => undefined);
        vi.stubGlobal('Notification', { permission: 'granted', requestPermission: vi.fn() });

        const { result } = renderHook(() => usePushSubscription(), { wrapper });

        await waitFor(() => expect(result.current.status).toBe('unsubscribed'));
        expect(pushManager.subscribe).not.toHaveBeenCalled();
      });

      it.each([
        ['denied', 'denied'],
        ['default', 'unsubscribed'],
      ])('permesso "%s": stato %s e nessuna chiamata al server né al push manager', async (permission, expected) => {
        const pushManager = setupReady(browserSubscription());
        routeFetch(async () => undefined);
        vi.stubGlobal('Notification', { permission: 'default', requestPermission: vi.fn().mockResolvedValue(permission) });
        const { result } = renderHook(() => usePushSubscription(), { wrapper });
        await waitFor(() => expect(result.current.status).toBe('unsubscribed'));

        await act(() => result.current.subscribe());

        expect(result.current.status).toBe(expected);
        expect(pushManager.subscribe).not.toHaveBeenCalled();
        expect(mockedAuthFetch).not.toHaveBeenCalledWith('/api/push-subscriptions', expect.anything());
      });

      it('se il server rifiuta la registrazione annulla la subscription del browser e rilancia l\'errore', async () => {
        const created = browserSubscription();
        setupReady(created);
        routeFetch(async () => {
          throw new Error('500');
        });
        vi.stubGlobal('Notification', { permission: 'default', requestPermission: vi.fn().mockResolvedValue('granted') });
        const { result } = renderHook(() => usePushSubscription(), { wrapper });
        await waitFor(() => expect(result.current.status).toBe('unsubscribed'));

        const error = await actAndCatch(() => result.current.subscribe());
        expect(error).toEqual(new Error('500'));

        // Altrimenti il browser resterebbe iscritto senza che il server lo sappia
        expect(created.unsubscribe).toHaveBeenCalledTimes(1);
        expect(result.current.status).toBe('unsubscribed');
      });

      it('disiscrive: prima il server, poi il browser', async () => {
        const existing = browserSubscription();
        setupReady(browserSubscription(), existing);
        const calls: string[] = [];
        existing.unsubscribe.mockImplementation(async () => {
          calls.push('browser');
          return true;
        });
        routeFetch(async () => {
          calls.push('server');
        });
        const { result } = renderHook(() => usePushSubscription(), { wrapper });
        await waitFor(() => expect(result.current.status).toBe('subscribed'));

        await act(() => result.current.unsubscribe());

        expect(calls).toEqual(['server', 'browser']);
        expect(result.current.status).toBe('unsubscribed');
        expect(rememberedPushEndpoint()).toBeNull();
      });

      it('se il server fallisce nel disiscrivere si resta iscritti (stato coerente) e il browser non viene toccato', async () => {
        const existing = browserSubscription();
        setupReady(browserSubscription(), existing);
        routeFetch(async () => {
          throw new Error('503');
        });
        const { result } = renderHook(() => usePushSubscription(), { wrapper });
        await waitFor(() => expect(result.current.status).toBe('subscribed'));

        const error = await actAndCatch(() => result.current.unsubscribe());
        expect(error).toEqual(new Error('503'));

        expect(existing.unsubscribe).not.toHaveBeenCalled();
        expect(result.current.status).toBe('subscribed');
      });
    });
  });

  it('ritorna "not-configured" anche se la fetch della chiave fallisce', async () => {
    vi.stubGlobal('PushManager', class {});
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: { ready: new Promise(() => {}) },
    });
    mockedAuthFetch.mockRejectedValue(new Error('boom'));

    const { result } = renderHook(() => usePushSubscription(), { wrapper });
    await waitFor(() => expect(result.current.status).toBe('not-configured'));
  });
});
