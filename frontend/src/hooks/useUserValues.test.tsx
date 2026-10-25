import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { useDeleteExpense } from './useExpenses';
import { useRenameUserValue, useUserValues, useValueSuggestions } from './useUserValues';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

function setup() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const spy = vi.spyOn(qc, 'invalidateQueries');
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return { spy, wrapper };
}

describe('useUserValues', () => {
  beforeEach(() => mockedAuthFetch.mockReset());

  it('useRenameUserValue invalida voci, record, grafici e veicoli', async () => {
    mockedAuthFetch.mockResolvedValue({ id: 1, name: 'Q8 Via Roma', usageCount: 2 });
    const { spy, wrapper } = setup();
    const { result } = renderHook(() => useRenameUserValue(), { wrapper });

    await act(() => result.current.mutateAsync({ id: 1, name: 'Q8 Via Roma' }));

    const keys = spy.mock.calls.map(([filters]) => filters?.queryKey);
    expect(keys).toEqual(
      expect.arrayContaining([['user-values'], ['expenses'], ['refuelings'], ['maintenances'], ['vehicles']]),
    );
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/values/1', expect.objectContaining({ method: 'PATCH' }));
  });

  it('useValueSuggestions legge i suggerimenti del veicolo e valida il payload', async () => {
    mockedAuthFetch.mockResolvedValue([{ id: 3, name: 'Eni' }]);
    const { wrapper } = setup();
    const { result } = renderHook(() => useValueSuggestions(7, 'station'), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toEqual([{ id: 3, name: 'Eni' }]);
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/7/values/station');
  });

  it('useUserValues rifiuta un payload malformato', async () => {
    mockedAuthFetch.mockResolvedValue([{ id: 3, name: 'Eni' }]);
    const { wrapper } = setup();
    const { result } = renderHook(() => useUserValues('workshop'), { wrapper });

    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('record mutations', () => {
  it('useDeleteExpense invalida le voci (usageCount cambia)', async () => {
    mockedAuthFetch.mockResolvedValue(undefined);
    const { spy, wrapper } = setup();
    const { result } = renderHook(() => useDeleteExpense(7), { wrapper });

    await act(() => result.current.mutateAsync(1));

    expect(spy.mock.calls.map(([filters]) => filters?.queryKey)).toContainEqual(['user-values']);
  });
});
