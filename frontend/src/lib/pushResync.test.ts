import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { resyncPushOnSessionStart, resyncThisDevice, sessionStarted } from './pushResync';
import { urlBase64ToUint8Array } from './webPush';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));

const mockedAuthFetch = vi.mocked(authFetch);

const VAPID = 'BPx6hRZ0Kq3qkXH2y2pN1Q';
const OTHER_VAPID = 'BAAAAAAAAAAAAAAAAAAAAA';
const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/this-device';

const bytes = (...values: number[]): ArrayBuffer => new Uint8Array(values).buffer;

function browserSubscription(vapidKey: string) {
  return {
    endpoint: ENDPOINT,
    options: { applicationServerKey: urlBase64ToUint8Array(vapidKey).buffer },
    getKey: (name: string) => (name === 'p256dh' ? bytes(1, 2, 3) : bytes(4, 5)),
  };
}

function installPush({ permission = 'granted', subscription = browserSubscription(VAPID) as unknown }: { permission?: NotificationPermission; subscription?: unknown } = {}) {
  vi.stubGlobal('PushManager', class {});
  vi.stubGlobal('Notification', { permission });
  Object.defineProperty(navigator, 'serviceWorker', {
    configurable: true,
    value: { getRegistration: async () => ({ pushManager: { getSubscription: async () => subscription } }) },
  });
}

function serverKey(publicKey: string) {
  mockedAuthFetch.mockImplementation(async (path) => {
    if (path === '/api/push-subscriptions/vapid-public-key') return { publicKey };
    if (path === '/api/push-subscriptions') return { id: 1 };
    throw new Error(`unexpected fetch ${path}`);
  });
}

const postCalls = () => mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/push-subscriptions');

describe('resyncThisDevice', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    if ('serviceWorker' in navigator) {
      // @ts-expect-error cleanup del property override di test
      delete navigator.serviceWorker;
    }
  });

  it('ri-invia endpoint e chiavi della subscription esistente quando permesso, subscription e VAPID coincidono', async () => {
    installPush();
    serverKey(VAPID);

    await resyncThisDevice();

    expect(postCalls()).toHaveLength(1);
    const [, init] = postCalls()[0] ?? [];
    expect(init?.method).toBe('POST');
    expect(JSON.parse(String(init?.body))).toEqual({
      platform: 'web',
      endpoint: ENDPOINT,
      p256dh: btoa(String.fromCharCode(1, 2, 3)),
      authSecret: btoa(String.fromCharCode(4, 5)),
      deviceLabel: navigator.userAgent.split(' ').slice(-1)[0],
    });
  });

  it('senza subscription nel browser non chiama il server', async () => {
    installPush({ subscription: null });
    serverKey(VAPID);

    await resyncThisDevice();

    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it.each<NotificationPermission>(['default', 'denied'])('con permesso %s non chiama il server', async (permission) => {
    installPush({ permission });
    serverKey(VAPID);

    await resyncThisDevice();

    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('con una chiave VAPID diversa (rigenerata) non ri-registra la subscription', async () => {
    installPush();
    serverKey(OTHER_VAPID);

    await resyncThisDevice();

    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/push-subscriptions/vapid-public-key');
    expect(postCalls()).toHaveLength(0);
  });

  it('con VAPID non configurata sul server non ri-registra', async () => {
    installPush();
    serverKey('');

    await resyncThisDevice();

    expect(postCalls()).toHaveLength(0);
  });

  it('senza supporto Push nel browser non fa nulla', async () => {
    serverKey(VAPID);

    await resyncThisDevice();

    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('non lancia se il server fallisce', async () => {
    installPush();
    mockedAuthFetch.mockRejectedValue(new Error('network down'));

    await expect(resyncThisDevice()).resolves.toBeUndefined();
  });

  it('non lancia se la risposta della chiave VAPID non ha la forma attesa', async () => {
    installPush();
    mockedAuthFetch.mockResolvedValue({ unexpected: true });

    await expect(resyncThisDevice()).resolves.toBeUndefined();
    expect(postCalls()).toHaveLength(0);
  });
});

const user = (id: number): User => ({
  id,
  email: `u${id}@test.it`,
  firstName: 'A',
  lastName: 'B',
  locale: 'it',
  hasAvatar: false,
  emailVerified: true,
  isInstanceAdmin: false,
  memberships: [],
});

describe('sessionStarted', () => {
  it('scatta al passaggio verso authenticated e al cambio utente', () => {
    expect(sessionStarted({ status: 'authenticated', user: { id: 1 } }, { status: 'loading', user: null })).toBe(true);
    expect(sessionStarted({ status: 'authenticated', user: { id: 1 } }, { status: 'unauthenticated', user: null })).toBe(true);
    expect(sessionStarted({ status: 'authenticated', user: { id: 2 } }, { status: 'authenticated', user: { id: 1 } })).toBe(true);
  });

  it('non scatta restando nella stessa sessione o uscendo', () => {
    expect(sessionStarted({ status: 'authenticated', user: { id: 1 } }, { status: 'authenticated', user: { id: 1 } })).toBe(false);
    expect(sessionStarted({ status: 'unauthenticated', user: null }, { status: 'authenticated', user: { id: 1 } })).toBe(false);
    expect(sessionStarted({ status: 'loading', user: null }, { status: 'unauthenticated', user: null })).toBe(false);
  });
});

describe('resyncPushOnSessionStart', () => {
  let stop: () => void;
  const resync = vi.fn<() => Promise<void>>();

  beforeEach(() => {
    resync.mockReset().mockResolvedValue(undefined);
    useAuthStore.setState({ status: 'loading', accessToken: null, user: null });
    stop = resyncPushOnSessionStart(resync);
  });

  afterEach(() => {
    stop();
    useAuthStore.setState({ status: 'loading', accessToken: null, user: null });
  });

  it('ri-registra al login (o al bootstrap) e al cambio di utente', () => {
    useAuthStore.getState().setAccessToken('tok-1'); // useLogin imposta il token prima di /me
    expect(resync).not.toHaveBeenCalled();

    useAuthStore.getState().setAuthenticated('tok-1', user(1));
    expect(resync).toHaveBeenCalledTimes(1);

    useAuthStore.getState().setAuthenticated('tok-2', user(2));
    expect(resync).toHaveBeenCalledTimes(2);
  });

  it('non ri-registra su refresh o switch-org (sostituzione del solo access token) né su setUser', () => {
    useAuthStore.getState().setAuthenticated('tok-1', user(1));
    resync.mockClear();

    useAuthStore.getState().setAccessToken('tok-refresh');
    useAuthStore.getState().setUser({ ...user(1), firstName: 'Nuovo' });

    expect(resync).not.toHaveBeenCalled();
  });

  it('ri-registra di nuovo dopo logout e nuovo login', () => {
    useAuthStore.getState().setAuthenticated('tok-1', user(1));
    useAuthStore.getState().logout();
    useAuthStore.getState().setAuthenticated('tok-1b', user(1));

    expect(resync).toHaveBeenCalledTimes(2);
  });

  it('dopo lo stop non reagisce più', () => {
    stop();
    useAuthStore.getState().setAuthenticated('tok-1', user(1));
    expect(resync).not.toHaveBeenCalled();
  });
});
