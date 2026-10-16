import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useVerifyEmail, useResendVerification, useAcceptInvitation, useRegisterInvited, useInvitationPreview } from './useAccount';
import i18n from '@/i18n';
import { useAuthStore } from '@/stores/useAuthStore';
import { authFetch } from '@/api/client';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));

const mockedAuthFetch = vi.mocked(authFetch);

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useAccount mutations', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    useAuthStore.setState({ user: null });
  });

  it('useVerifyEmail posts the token to the verify endpoint', async () => {
    mockedAuthFetch.mockResolvedValue(undefined);
    const { result } = renderHook(() => useVerifyEmail(), { wrapper });

    result.current.mutate({ token: 'tok-1' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch).toHaveBeenCalledWith(
      '/api/auth/verify-email',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ token: 'tok-1' }) }),
    );
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
    const me = { id: 1, email: 'a@b.it', firstName: 'A', emailVerified: true } as never;
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
});
