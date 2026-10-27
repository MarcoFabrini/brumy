import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useAuthStore } from '@/stores/useAuthStore';
import { unsubscribeThisDevice } from './pushCleanup';

// Qui authFetch è quello vero: si simula solo la rete (fetch).
const fetchMock = vi.fn<typeof fetch>();
const unsubscribe = vi.fn<() => Promise<boolean>>();

function installServiceWorker(withSubscription = true) {
  const sub = withSubscription ? { endpoint: 'https://push.example/abc', unsubscribe } : null;
  Object.defineProperty(navigator, 'serviceWorker', {
    configurable: true,
    value: { getRegistration: async () => ({ pushManager: { getSubscription: async () => sub } }) },
  });
}

function json(status: number, body: unknown = {}): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const urls = () => fetchMock.mock.calls.map(([url]) => String(url));

describe('unsubscribeThisDevice', () => {
  beforeEach(() => {
    fetchMock.mockReset();
    unsubscribe.mockReset().mockResolvedValue(true);
    vi.stubGlobal('fetch', fetchMock);
    useAuthStore.setState({ status: 'authenticated', user: null, activeOrgId: null });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    // @ts-expect-error cleanup del property override di test
    delete navigator.serviceWorker;
  });

  it('con la sessione ancora aperta disiscrive sul server e chiude la sottoscrizione del browser', async () => {
    installServiceWorker();
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));

    await unsubscribeThisDevice();

    expect(urls()).toEqual(['/api/push-subscriptions/unsubscribe']);
    const init = fetchMock.mock.calls[0]?.[1];
    expect(init?.credentials).toBe('same-origin');
    expect(new Headers(init?.headers).has('Authorization')).toBe(false);
    expect(JSON.parse(String(init?.body))).toEqual({ platform: 'web', endpoint: 'https://push.example/abc' });
    expect(unsubscribe).toHaveBeenCalledTimes(1);
  });

  it('se il server fallisce chiude comunque la sottoscrizione del browser, senza lanciare', async () => {
    installServiceWorker();
    fetchMock.mockResolvedValue(json(500, { title: 'boom' }));

    await expect(unsubscribeThisDevice()).resolves.toBeUndefined();

    expect(unsubscribe).toHaveBeenCalledTimes(1);
  });

  it('se la rete cade chiude comunque la sottoscrizione del browser', async () => {
    installServiceWorker();
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

    await expect(unsubscribeThisDevice()).resolves.toBeUndefined();

    expect(unsubscribe).toHaveBeenCalledTimes(1);
  });

  it('senza sottoscrizione non chiama il server', async () => {
    installServiceWorker(false);

    await unsubscribeThisDevice();

    expect(fetchMock).not.toHaveBeenCalled();
    expect(unsubscribe).not.toHaveBeenCalled();
  });
});
