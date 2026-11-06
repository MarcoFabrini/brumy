import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { testUser } from '@/test/authFixtures';
import { stubLocalStorage } from '@/test/memoryStorage';
import { resyncPushOnSessionStart, resyncThisDevice, sessionStarted } from './pushResync';
import { rememberPushEndpoint, rememberedPushEndpoint, urlBase64ToUint8Array } from './webPush';

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
  const pushManager = {
    getSubscription: vi.fn(async () => subscription),
    subscribe: vi.fn(async () => ({ ...browserSubscription(VAPID), endpoint: NEW_ENDPOINT })),
  };
  Object.defineProperty(navigator, 'serviceWorker', {
    configurable: true,
    value: { getRegistration: async () => ({ pushManager }) },
  });
  return pushManager;
}

const NEW_ENDPOINT = 'https://web.push.apple.com/new-endpoint';

function serverKey(publicKey: string) {
  mockedAuthFetch.mockImplementation(async (path) => {
    if (path === '/api/push-subscriptions/vapid-public-key') return { publicKey };
    if (path === '/api/push-subscriptions') return { id: 1 };
    if (path === '/api/push-subscriptions/unsubscribe') return undefined;
    throw new Error(`unexpected fetch ${path}`);
  });
}

const postCalls = () => mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/push-subscriptions');
const unsubscribeCalls = () => mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/push-subscriptions/unsubscribe');

describe('resyncThisDevice', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    stubLocalStorage();
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

  it('si ricorda che su questo dispositivo le notifiche sono accese', async () => {
    installPush();
    serverKey(VAPID);

    await resyncThisDevice();

    expect(rememberedPushEndpoint()).toBe(ENDPOINT);
  });

  it('ricrea la subscription persa dal browser se qui erano accese, e toglie dal server quella vecchia', async () => {
    rememberPushEndpoint(ENDPOINT);
    const pushManager = installPush({ subscription: null });
    serverKey(VAPID);

    await resyncThisDevice();

    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
    expect(pushManager.subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true }));
    expect(JSON.parse(String(postCalls()[0]?.[1]?.body))).toMatchObject({ endpoint: NEW_ENDPOINT });
    expect(JSON.parse(String(unsubscribeCalls()[0]?.[1]?.body))).toEqual({ platform: 'web', endpoint: ENDPOINT });
    expect(rememberedPushEndpoint()).toBe(NEW_ENDPOINT);
  });

  it('due chiamate insieme ricreano la subscription una volta sola', async () => {
    rememberPushEndpoint(ENDPOINT);
    const pushManager = installPush({ subscription: null });
    serverKey(VAPID);

    await Promise.all([resyncThisDevice(), resyncThisDevice()]);

    expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
  });

  it('con il permesso non più concesso non ricrea niente', async () => {
    rememberPushEndpoint(ENDPOINT);
    const pushManager = installPush({ permission: 'default', subscription: null });
    serverKey(VAPID);

    await resyncThisDevice();

    expect(pushManager.subscribe).not.toHaveBeenCalled();
    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('se il browser rifiuta di ricrearla non lancia e resta il segno per il prossimo giro', async () => {
    rememberPushEndpoint(ENDPOINT);
    const pushManager = installPush({ subscription: null });
    pushManager.subscribe.mockRejectedValue(new DOMException('gesture', 'NotAllowedError'));
    serverKey(VAPID);

    await expect(resyncThisDevice()).resolves.toBeUndefined();

    expect(postCalls()).toHaveLength(0);
    expect(rememberedPushEndpoint()).toBe(ENDPOINT);
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

const user = (id: number): User => testUser({ id, email: `u${id}@test.it`, memberships: [] });

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
    useAuthStore.setState({ status: 'loading', user: null, activeOrgId: null });
    stop = resyncPushOnSessionStart(resync);
  });

  afterEach(() => {
    stop();
    useAuthStore.setState({ status: 'loading', user: null, activeOrgId: null });
  });

  it('ri-registra al login (o al bootstrap) e al cambio di utente', () => {
    useAuthStore.getState().setTwoFactorPending(); // password data, codice ancora da inserire: non è una sessione
    expect(resync).not.toHaveBeenCalled();

    useAuthStore.getState().setAuthenticated(user(1), 1);
    expect(resync).toHaveBeenCalledTimes(1);

    useAuthStore.getState().setAuthenticated(user(2), 1);
    expect(resync).toHaveBeenCalledTimes(2);
  });

  it('non ri-registra su switch-org (cambio della sola org attiva) né su setUser', () => {
    useAuthStore.getState().setAuthenticated(user(1), 1);
    resync.mockClear();

    useAuthStore.getState().setActiveOrgId(2);
    useAuthStore.getState().setUser({ ...user(1), firstName: 'Nuovo' });

    expect(resync).not.toHaveBeenCalled();
  });

  it('ri-registra di nuovo dopo logout e nuovo login', () => {
    useAuthStore.getState().setAuthenticated(user(1), 1);
    useAuthStore.getState().logout();
    useAuthStore.getState().setAuthenticated(user(1), 1);

    expect(resync).toHaveBeenCalledTimes(2);
  });

  it('dopo lo stop non reagisce più', () => {
    stop();
    useAuthStore.getState().setAuthenticated(user(1), 1);
    expect(resync).not.toHaveBeenCalled();
  });
});
