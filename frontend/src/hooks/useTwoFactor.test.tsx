import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useEnableTwoFactor } from './useTwoFactor';
import { authFetch } from '@/api/client';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useEnableTwoFactor', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
  });

  it('se /me fallisce subito dopo, l\'attivazione resta riuscita e i codici di recupero arrivano', async () => {
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/2fa/enable') return { backupCodes: ['abcd-efgh-jkmn'] };
      throw new Error('rete giù');
    });
    const { result } = renderHook(() => useEnableTwoFactor(), { wrapper });

    result.current.mutate({ password: 'secret', authCode: '123456' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toEqual(['abcd-efgh-jkmn']);
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/auth/me', expect.anything());
  });
});
