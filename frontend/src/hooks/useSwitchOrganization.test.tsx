import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { useAuthStore } from '@/stores/useAuthStore';
import { useSwitchOrganization } from './useSwitchOrganization';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return (
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/vehicles/42']}>{children}</MemoryRouter>
    </QueryClientProvider>
  );
}

function useHarness() {
  return { ...useSwitchOrganization(), pathname: useLocation().pathname };
}

describe('useSwitchOrganization', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    useAuthStore.setState({ activeOrgId: 1 });
    await i18n.changeLanguage('it');
  });

  it('invia organizationId a switch-org, salva la nuova org attiva e va alla home', async () => {
    mockedAuthFetch.mockResolvedValue({ activeOrganizationId: 5 });
    const { result } = renderHook(useHarness, { wrapper });

    act(() => result.current.switchTo(5));

    await waitFor(() => expect(useAuthStore.getState().activeOrgId).toBe(5));
    expect(mockedAuthFetch).toHaveBeenCalledWith(
      '/api/auth/switch-org',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ organizationId: 5 }) }),
    );
    await waitFor(() => expect(result.current.pathname).toBe('/'));
  });

  it('due chiamate nello stesso tick inviano una sola richiesta', async () => {
    mockedAuthFetch.mockResolvedValue({ activeOrganizationId: 5 });
    const { result } = renderHook(useHarness, { wrapper });

    act(() => {
      result.current.switchTo(5);
      result.current.switchTo(5);
    });

    await waitFor(() => expect(result.current.pathname).toBe('/'));
    expect(mockedAuthFetch).toHaveBeenCalledTimes(1);
  });

  it('in errore tiene org e pagina, e dopo si può riprovare', async () => {
    mockedAuthFetch.mockRejectedValueOnce(new ApiError('auth.not_member', 403));
    const { result } = renderHook(useHarness, { wrapper });

    act(() => result.current.switchTo(5));
    await waitFor(() => expect(result.current.isPending).toBe(false));

    expect(useAuthStore.getState().activeOrgId).toBe(1);
    expect(result.current.pathname).toBe('/vehicles/42');

    mockedAuthFetch.mockResolvedValueOnce({ activeOrganizationId: 5 });
    act(() => result.current.switchTo(5));
    await waitFor(() => expect(useAuthStore.getState().activeOrgId).toBe(5));
    expect(mockedAuthFetch).toHaveBeenCalledTimes(2);
  });
});
