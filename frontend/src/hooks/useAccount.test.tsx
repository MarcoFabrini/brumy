import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  useVerifyEmail,
  useResendVerification,
  useAcceptInvitation,
  useRegisterInvited,
  useInvitationPreview,
  useChangePassword,
  useUpdateProfile,
} from './useAccount';
import i18n from '@/i18n';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { authFetch } from '@/api/client';
import { resyncThisDevice } from '@/lib/pushResync';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
// La ri-registrazione push ha i suoi test (lib/pushResync.test.ts): qui conta solo quando viene chiesta.
vi.mock('@/lib/pushResync', () => ({ resyncThisDevice: vi.fn() }));

const mockedAuthFetch = vi.mocked(authFetch);
const mockedResync = vi.mocked(resyncThisDevice);

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function userFixture(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    email: 'a@b.it',
    firstName: 'A',
    lastName: 'B',
    locale: 'it',
    hasAvatar: false,
    emailVerified: true,
    pendingEmail: null,
    isInstanceAdmin: false,
    memberships: [],
    ...overrides,
  };
}

describe('useAccount mutations', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    mockedResync.mockReset().mockResolvedValue(undefined);
    useAuthStore.setState({ user: null });
  });

  it('useVerifyEmail posts the token to the verify endpoint', async () => {
    mockedAuthFetch.mockResolvedValue({ emailChanged: false });
    const { result } = renderHook(() => useVerifyEmail(), { wrapper });

    result.current.mutate({ token: 'tok-1' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toEqual({ emailChanged: false });
    expect(mockedAuthFetch).toHaveBeenCalledWith(
      '/api/auth/verify-email',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ token: 'tok-1' }) }),
    );
  });

  it('useVerifyEmail chiude la sessione locale quando il link confermava un cambio email', async () => {
    useAuthStore.setState({ status: 'authenticated', accessToken: 'tok-vecchio', user: userFixture() });
    mockedAuthFetch.mockResolvedValue({ emailChanged: true });
    const { result } = renderHook(() => useVerifyEmail(), { wrapper });

    result.current.mutate({ token: 'tok-1' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(useAuthStore.getState().status).toBe('unauthenticated');
    expect(useAuthStore.getState().accessToken).toBeNull();
    expect(mockedAuthFetch).toHaveBeenCalledTimes(1);
  });

  it('useVerifyEmail rifiuta una risposta senza emailChanged', async () => {
    mockedAuthFetch.mockResolvedValue(undefined);
    const { result } = renderHook(() => useVerifyEmail(), { wrapper });

    result.current.mutate({ token: 'tok-1' });

    await waitFor(() => expect(result.current.isError).toBe(true));
  });

  it('useResendVerification posts to the resend endpoint', async () => {
    mockedAuthFetch.mockResolvedValue(undefined);
    const { result } = renderHook(() => useResendVerification(), { wrapper });

    result.current.mutate();

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch).toHaveBeenCalledWith(
      '/api/auth/resend-verification',
      expect.objectContaining({ method: 'POST' }),
    );
  });

  it('useAcceptInvitation accepts then reloads /me into the store', async () => {
    const me = userFixture();
    mockedAuthFetch
      .mockResolvedValueOnce({ status: 'ok', organizationId: 7 })
      .mockResolvedValueOnce({ access_token: 'token-org-7' })
      .mockResolvedValueOnce(me);
    const { result } = renderHook(() => useAcceptInvitation(), { wrapper });

    result.current.mutate({ token: 'inv-1' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch).toHaveBeenNthCalledWith(
      1,
      '/api/auth/invitation/accept',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ token: 'inv-1' }) }),
    );
    // passa all'organizzazione appena accettata, poi ricarica /me
    expect(mockedAuthFetch).toHaveBeenNthCalledWith(
      2,
      '/api/auth/switch-org',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ organizationId: 7 }) }),
    );
    expect(mockedAuthFetch).toHaveBeenNthCalledWith(3, '/api/auth/me');
    expect(useAuthStore.getState().accessToken).toBe('token-org-7');
    expect(useAuthStore.getState().user).toBe(me);
  });

  it("useAcceptInvitation svuota la cache della vecchia organizzazione ma tiene l'anteprima dell'invito", async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    qc.setQueryData(['invitation', 'inv-1'], { organizationName: 'Officina' });
    qc.setQueryData(['vehicles', 'list'], [{ id: 1 }]);
    mockedAuthFetch
      .mockResolvedValueOnce({ organizationId: 7 })
      .mockResolvedValueOnce({ access_token: 'token-org-7' })
      .mockResolvedValueOnce({ id: 1 });
    const { result } = renderHook(() => useAcceptInvitation(), {
      wrapper: ({ children }: { children: ReactNode }) => <QueryClientProvider client={qc}>{children}</QueryClientProvider>,
    });

    result.current.mutate({ token: 'inv-1' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(qc.getQueryData(['vehicles', 'list'])).toBeUndefined();
    expect(qc.getQueryData(['invitation', 'inv-1'])).toEqual({ organizationName: 'Officina' });
  });

  it("l'anteprima dell'invito codifica il token: un valore con ../ o ? non cambia il percorso richiesto", async () => {
    mockedAuthFetch.mockResolvedValue({ organizationName: 'Officina' });
    const { result } = renderHook(() => useInvitationPreview('../me?x=1'), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch.mock.calls[0]?.[0]).toBe('/api/auth/invitation/..%2Fme%3Fx%3D1');
  });

  it.each([
    ['it', 'it'],
    ['en', 'en'],
  ])('useRegisterInvited con UI in %s invia locale %s e non cambia la lingua', async (language, expected) => {
    await i18n.changeLanguage(language);
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/invitation/register') return { access_token: 'tok' };
      if (path === '/api/auth/me') return { id: 1, locale: expected, memberships: [] };
      throw new Error(`unexpected fetch ${path}`);
    });
    const { result } = renderHook(() => useRegisterInvited(), { wrapper });

    result.current.mutate({ token: 't', firstName: 'A', lastName: 'B', password: 'segreta-123' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    const body = JSON.parse(String(mockedAuthFetch.mock.calls[0]?.[1]?.body)) as { locale: string };
    expect(body.locale).toBe(expected);
    expect(i18n.language.startsWith(language)).toBe(true);
  });
  describe('ri-registrazione della subscription push dopo una transizione di credenziali', () => {
    const storeUser = userFixture({ email: 'anna@test.it', firstName: 'Anna' });
    const profile = (email: string) => ({ email, firstName: 'Anna', lastName: 'Rossi', locale: 'it' as const });

    it('useChangePassword la chiede dopo il successo, col nuovo access token già nello store', async () => {
      let tokenAtResync: string | null = null;
      mockedResync.mockImplementation(async () => {
        tokenAtResync = useAuthStore.getState().accessToken;
      });
      mockedAuthFetch.mockResolvedValue({ access_token: 'tok-dopo-cambio', user: {} });
      const { result } = renderHook(() => useChangePassword(), { wrapper });

      result.current.mutate({ currentPassword: 'vecchia-123', newPassword: 'nuova-12345' });

      await waitFor(() => expect(result.current.isSuccess).toBe(true));
      expect(mockedResync).toHaveBeenCalledTimes(1);
      expect(tokenAtResync).toBe('tok-dopo-cambio');
    });

    it('useChangePassword non la chiede se il cambio fallisce', async () => {
      mockedAuthFetch.mockRejectedValue(new Error('auth.invalid_current_password'));
      const { result } = renderHook(() => useChangePassword(), { wrapper });

      result.current.mutate({ currentPassword: 'sbagliata', newPassword: 'nuova-12345' });

      await waitFor(() => expect(result.current.isError).toBe(true));
      expect(mockedResync).not.toHaveBeenCalled();
    });

    it("useUpdateProfile non la chiede alla richiesta di cambio email: la sessione non cambia finché il link non viene aperto", async () => {
      useAuthStore.setState({ user: storeUser });
      mockedAuthFetch.mockImplementation(async (path) => {
        if (path === '/api/auth/profile') return { access_token: 'tok-stessa-scadenza', user: { email: 'anna@test.it', pendingEmail: 'nuova@test.it' } };
        if (path === '/api/auth/me') return { id: 1, email: 'anna@test.it', pendingEmail: 'nuova@test.it', locale: 'it', memberships: [] };
        throw new Error(`unexpected fetch ${path}`);
      });
      const { result } = renderHook(() => useUpdateProfile(), { wrapper });

      result.current.mutate({ ...profile('nuova@test.it'), currentPassword: 'segreta-123' });

      await waitFor(() => expect(result.current.isSuccess).toBe(true));
      expect(useAuthStore.getState().user?.pendingEmail).toBe('nuova@test.it');
      expect(mockedResync).not.toHaveBeenCalled();
    });

    it("useUpdateProfile non la chiede se l'email non cambia", async () => {
      useAuthStore.setState({ user: storeUser });
      mockedAuthFetch.mockImplementation(async (path) => {
        if (path === '/api/auth/profile') return { access_token: 'tok-stessa-scadenza', user: { email: 'anna@test.it' } };
        if (path === '/api/auth/me') return { id: 1, email: 'anna@test.it', locale: 'it', memberships: [] };
        throw new Error(`unexpected fetch ${path}`);
      });
      const { result } = renderHook(() => useUpdateProfile(), { wrapper });

      result.current.mutate(profile('anna@test.it'));

      await waitFor(() => expect(result.current.isSuccess).toBe(true));
      expect(useAuthStore.getState().accessToken).toBe('tok-stessa-scadenza');
      expect(mockedResync).not.toHaveBeenCalled();
    });
  });
});
