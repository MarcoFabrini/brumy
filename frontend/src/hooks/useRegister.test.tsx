import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { testMe } from '@/test/authFixtures';
import { useRegister } from './useRegister';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

const payload = { email: 'a@b.it', password: 'segreta-123', firstName: 'Anna', lastName: 'Neri' };

function me(locale: User['locale']) {
  return testMe({ email: payload.email, firstName: 'Anna', lastName: 'Neri', locale, emailVerified: false, isInstanceAdmin: true });
}

/** Il backend risponde con il profilo nella lingua ricevuta nel payload di registrazione. */
function mockBackend() {
  let sentLocale: User['locale'] = 'it';
  mockedAuthFetch.mockImplementation(async (path, init) => {
    if (path === '/api/auth/register') {
      sentLocale = (JSON.parse(String(init?.body)) as { locale: User['locale'] }).locale;
      return { user: me(sentLocale) };
    }
    if (path === '/api/auth/me') return me(sentLocale);
    throw new Error(`unexpected fetch ${path}`);
  });
}

describe('useRegister', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    useAuthStore.setState({ status: 'unauthenticated', user: null, activeOrgId: null });
  });

  it.each([
    ['it', 'it'],
    ['en', 'en'],
    ['en-US', 'en'],
  ])('con UI in %s invia locale %s', async (language, expected) => {
    await i18n.changeLanguage(language);
    mockBackend();
    const { result } = renderHook(() => useRegister(), { wrapper });

    result.current.mutate(payload);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    const body = JSON.parse(String(mockedAuthFetch.mock.calls[0]?.[1]?.body)) as { locale: string };
    expect(body.locale).toBe(expected);
  });

  it("registrandosi in inglese la UI resta in inglese dopo il login automatico", async () => {
    await i18n.changeLanguage('en');
    mockBackend();
    const { result } = renderHook(() => useRegister(), { wrapper });

    result.current.mutate(payload);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(useAuthStore.getState().status).toBe('authenticated');
    expect(useAuthStore.getState().user?.locale).toBe('en');
    expect(i18n.language.startsWith('en')).toBe(true);
  });
});
