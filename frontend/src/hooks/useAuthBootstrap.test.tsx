import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { authFetch, ApiError } from '@/api/client';
import { useAuthStore } from '@/stores/useAuthStore';
import { testMe } from '@/test/authFixtures';
import { useAuthBootstrap } from './useAuthBootstrap';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const me = testMe({ id: 7, email: 'anna@test.it', activeOrganizationId: 10 });

describe('useAuthBootstrap', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    useAuthStore.setState({ status: 'loading', user: null, activeOrgId: null });
  });

  it('con /me 200 la sessione del cookie vale: autenticato, con utente e org attiva (reload senza logout)', async () => {
    mockedAuthFetch.mockResolvedValue(me);

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('authenticated'));
    expect(useAuthStore.getState().activeOrgId).toBe(10);
    expect(useAuthStore.getState().user).toMatchObject({ id: 7, email: 'anna@test.it' });
    expect(useAuthStore.getState().user).not.toHaveProperty('activeOrganizationId');
    // Chiamata anonima: il 401 lo interpreta il bootstrap, non il client.
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/auth/me', { anonymous: true });
  });

  it('401 auth.two_factor_required: password già data, si va al secondo passo del login', async () => {
    mockedAuthFetch.mockRejectedValue(new ApiError('auth.two_factor_required', 401));

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('two_factor'));
  });

  it('qualunque altro 401: nessuna sessione', async () => {
    mockedAuthFetch.mockRejectedValue(new ApiError('http.401', 401));

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('unauthenticated'));
  });

  it.each([
    ['5xx', new ApiError('http.500', 500)],
    ['rete assente', new TypeError('Failed to fetch')],
  ])('/me fallisce (%s): irraggiungibile, non logout', async (_label, error) => {
    mockedAuthFetch.mockRejectedValue(error);

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('unreachable'));
  });

  it('se il componente si smonta durante la richiesta, lo store non viene toccato', async () => {
    let finish: (value: unknown) => void = () => {};
    mockedAuthFetch.mockReturnValue(new Promise((resolve) => (finish = resolve)));

    const { unmount } = renderHook(() => useAuthBootstrap());
    unmount();
    await act(async () => {
      finish(me);
    });

    expect(useAuthStore.getState().status).toBe('loading');
  });

  it('retry riporta a loading e ritenta il bootstrap fino a ristabilire la sessione', async () => {
    mockedAuthFetch.mockRejectedValue(new TypeError('Failed to fetch'));
    const { result } = renderHook(() => useAuthBootstrap());
    await waitFor(() => expect(result.current.status).toBe('unreachable'));

    mockedAuthFetch.mockResolvedValue(me);
    act(() => result.current.retry());

    expect(useAuthStore.getState().status).toBe('loading');
    await waitFor(() => expect(result.current.status).toBe('authenticated'));
  });
});
