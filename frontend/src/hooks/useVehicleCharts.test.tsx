import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ZodError } from 'zod';
import { authFetch } from '@/api/client';
import { dashboardKeys } from './useDashboardCharts';
import {
  invalidateVehicleStats,
  useArchiveVehicle,
  useDeleteVehicle,
  useUnarchiveVehicle,
  useVehicleCharts,
  vehicleChartsKeys,
} from './useVehicles';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

const validCharts = {
  from: '2026-10',
  to: '2026-10',
  fuelTypes: ['diesel'],
  months: [
    {
      month: '2026-10',
      spending: { refuelings: '10.00', scheduled: '0.00', unscheduled: '0.00', inspection: '0.00', taxes: '0.00', insurance: '0.00', expenses: '0.00', total: '10.00' },
      kmDriven: 100,
      consumption: { diesel: 12.5 },
    },
  ],
  spendingByCategory: [{ category: 'fuel', name: null, amount: '10.00' }],
  totals: { spending: '10.00', kmDriven: 100 },
};

function newClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function wrapperFor(qc: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

describe('useVehicleCharts', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
  });

  it('chiede i 12 mesi del solo veicolo', async () => {
    mockedAuthFetch.mockResolvedValue(validCharts);

    const { result } = renderHook(() => useVehicleCharts(5), { wrapper: wrapperFor(newClient()) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/5/charts?months=12');
    expect(result.current.data).toEqual(validCharts);
  });

  it('con enabled: false non fa richieste', () => {
    renderHook(() => useVehicleCharts(5, 12, { enabled: false }), { wrapper: wrapperFor(newClient()) });

    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('payload malformato → errore della query', async () => {
    mockedAuthFetch.mockResolvedValue({ ...validCharts, months: undefined });

    const { result } = renderHook(() => useVehicleCharts(5), { wrapper: wrapperFor(newClient()) });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toBeInstanceOf(ZodError);
  });
});

describe('invalidazione dei grafici del veicolo', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    mockedAuthFetch.mockResolvedValue(validCharts);
  });

  it('invalidateVehicleStats invalida i grafici di quel veicolo e della dashboard, non di altri veicoli', () => {
    const qc = newClient();
    qc.setQueryData(vehicleChartsKeys.window(1, 12), validCharts);
    qc.setQueryData(vehicleChartsKeys.window(2, 12), validCharts);
    qc.setQueryData(dashboardKeys.charts(12), validCharts);

    invalidateVehicleStats(qc, 1);

    expect(qc.getQueryState(vehicleChartsKeys.window(1, 12))?.isInvalidated).toBe(true);
    expect(qc.getQueryState(dashboardKeys.charts(12))?.isInvalidated).toBe(true);
    expect(qc.getQueryState(vehicleChartsKeys.window(2, 12))?.isInvalidated).toBe(false);
  });

  it.each([
    ['archiviazione', () => useArchiveVehicle()],
    ['ripristino', () => useUnarchiveVehicle()],
  ])('%s del veicolo invalida i suoi grafici', async (_case, useMutationHook) => {
    const qc = newClient();
    qc.setQueryData(vehicleChartsKeys.window(1, 12), validCharts);
    const { result } = renderHook(useMutationHook, { wrapper: wrapperFor(qc) });

    await act(() => result.current.mutateAsync(1));

    expect(qc.getQueryState(vehicleChartsKeys.window(1, 12))?.isInvalidated).toBe(true);
  });

  it('eliminazione del veicolo rimuove i suoi grafici dalla cache', async () => {
    const qc = newClient();
    qc.setQueryData(vehicleChartsKeys.window(1, 12), validCharts);
    const { result } = renderHook(() => useDeleteVehicle(), { wrapper: wrapperFor(qc) });

    await act(() => result.current.mutateAsync(1));

    expect(qc.getQueryData(vehicleChartsKeys.window(1, 12))).toBeUndefined();
  });
});
