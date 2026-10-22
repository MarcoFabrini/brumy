import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { authFetch, refreshAccessToken, ApiError, type RefreshResult } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { useAuthBootstrap } from './useAuthBootstrap';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
  refreshAccessToken: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);
const mockedRefresh = vi.mocked(refreshAccessToken);

const me: User = {
  id: 7,
  email: 'anna@test.it',
  firstName: 'Anna',
  lastName: 'Neri',
  locale: 'it',
  hasAvatar: false,
  emailVerified: true,
  pendingEmail: null,
  isInstanceAdmin: false,
  memberships: [],
};

/** Come il vero refreshAccessToken: se va a buon fine mette il nuovo access token nello store. */
function refreshResolves(result: RefreshResult) {
  mockedRefresh.mockImplementation(async () => {
    if (result === 'ok') useAuthStore.getState().setAccessToken('token-dal-refresh');
    return result;
  });
}

describe('useAuthBootstrap', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    mockedRefresh.mockReset();
    useAuthStore.setState({ status: 'loading', accessToken: null, user: null });
  });

  it('con refresh e /me riusciti ripristina la sessione (reload di pagina senza logout)', async () => {
    refreshResolves('ok');
    mockedAuthFetch.mockResolvedValue(me);

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('authenticated'));
    expect(useAuthStore.getState()).toMatchObject({ accessToken: 'token-dal-refresh', user: me });
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/auth/me');
  });

  it("un rifiuto esplicito del refresh (400/401) vale 'nessuna sessione' e /me non viene chiamato", async () => {
    refreshResolves('invalid');

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('unauthenticated'));
    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('server irraggiungibile al refresh: schermata "riprova", non logout', async () => {
    refreshResolves('unavailable');

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('unreachable'));
    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('refresh riuscito ma /me risponde 401: sessione finita', async () => {
    refreshResolves('ok');
    mockedAuthFetch.mockRejectedValue(new ApiError('http.401', 401));

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('unauthenticated'));
    expect(useAuthStore.getState().accessToken).toBeNull();
  });

  it.each([
    ['5xx', new ApiError('http.500', 500)],
    ['rete assente', new TypeError('Failed to fetch')],
  ])('refresh riuscito ma /me fallisce (%s): irraggiungibile, il token non si butta', async (_label, error) => {
    refreshResolves('ok');
    mockedAuthFetch.mockRejectedValue(error);

    renderHook(() => useAuthBootstrap());

    await waitFor(() => expect(useAuthStore.getState().status).toBe('unreachable'));
    expect(useAuthStore.getState().accessToken).toBe('token-dal-refresh');
  });

  it('se il componente si smonta durante il refresh, lo store non viene toccato', async () => {
    let finish: (r: RefreshResult) => void = () => {};
    mockedRefresh.mockReturnValue(new Promise<RefreshResult>((resolve) => (finish = resolve)));

    const { unmount } = renderHook(() => useAuthBootstrap());
    unmount();
    await act(async () => {
      finish('invalid');
    });

    expect(useAuthStore.getState().status).toBe('loading');
  });

  it('retry riporta a loading e ritenta il bootstrap fino a ristabilire la sessione', async () => {
    refreshResolves('unavailable');
    const { result } = renderHook(() => useAuthBootstrap());
    await waitFor(() => expect(result.current.status).toBe('unreachable'));

    refreshResolves('ok');
    mockedAuthFetch.mockResolvedValue(me);
    act(() => result.current.retry());

    expect(useAuthStore.getState().status).toBe('loading');
    await waitFor(() => expect(result.current.status).toBe('authenticated'));
    expect(mockedRefresh).toHaveBeenCalledTimes(2);
  });
});
