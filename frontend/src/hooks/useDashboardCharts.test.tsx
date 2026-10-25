import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ZodError } from 'zod';
import { authFetch } from '@/api/client';
import type { CreateVehicleDto } from '@/api/types/vehicle';
import { dashboardKeys, useDashboardCharts } from './useDashboardCharts';
import {
  invalidateVehicleStats,
  useArchiveVehicle,
  useCreateVehicle,
  useDeleteVehicle,
  useUnarchiveVehicle,
  useUpdateVehicle,
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

const vehicleDto: CreateVehicleDto = {
  name: 'Panda',
  brand: 'Fiat',
  model: 'Panda',
  year: 2020,
  type: 'car',
  fuelType: 'diesel',
  initialKm: 0,
};

function newClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

function wrapperFor(qc: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

/** Mette in cache i grafici e restituisce una funzione che dice se sono stati invalidati. */
function seedCharts(qc: QueryClient) {
  qc.setQueryData(dashboardKeys.charts(12), validCharts);
  return () => qc.getQueryState(dashboardKeys.charts(12))?.isInvalidated;
}

describe('useDashboardCharts', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
  });

  it('chiede la finestra di 12 mesi e restituisce i dati validati', async () => {
    mockedAuthFetch.mockResolvedValue(validCharts);

    const { result } = renderHook(() => useDashboardCharts(), { wrapper: wrapperFor(newClient()) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/dashboard/charts?months=12');
    expect(result.current.data).toEqual(validCharts);
  });

  it('passa la finestra richiesta nella query string', async () => {
    mockedAuthFetch.mockResolvedValue(validCharts);

    const { result } = renderHook(() => useDashboardCharts(3), { wrapper: wrapperFor(newClient()) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/dashboard/charts?months=3');
  });

  it.each([
    ['importo non stringa', { ...validCharts, totals: { spending: 10, kmDriven: 100 } }],
    ['consumption come lista', { ...validCharts, months: [{ ...validCharts.months[0], consumption: [] }] }],
    ['mese mancante', { ...validCharts, months: undefined }],
  ])('payload malformato (%s) → errore della query, non dati', async (_case, payload) => {
    mockedAuthFetch.mockResolvedValue(payload);

    const { result } = renderHook(() => useDashboardCharts(), { wrapper: wrapperFor(newClient()) });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toBeInstanceOf(ZodError);
    expect(result.current.data).toBeUndefined();
  });
});

describe('invalidazione dei grafici', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    mockedAuthFetch.mockResolvedValue({});
  });

  it('invalidateVehicleStats (mutazioni di rifornimenti, manutenzioni, spese) invalida anche i grafici', () => {
    const qc = newClient();
    const isInvalidated = seedCharts(qc);

    invalidateVehicleStats(qc, 1);

    expect(isInvalidated()).toBe(true);
  });

  type Wrapper = ReturnType<typeof wrapperFor>;
  const vehicleMutations: Array<[string, (wrapper: Wrapper) => Promise<void>]> = [
    ['creazione', async (wrapper) => {
      const { result } = renderHook(() => useCreateVehicle(), { wrapper });
      await act(() => result.current.mutateAsync(vehicleDto));
    }],
    ['modifica', async (wrapper) => {
      const { result } = renderHook(() => useUpdateVehicle(1), { wrapper });
      await act(() => result.current.mutateAsync(vehicleDto));
    }],
    ['archiviazione', async (wrapper) => {
      const { result } = renderHook(() => useArchiveVehicle(), { wrapper });
      await act(() => result.current.mutateAsync(1));
    }],
    ['ripristino', async (wrapper) => {
      const { result } = renderHook(() => useUnarchiveVehicle(), { wrapper });
      await act(() => result.current.mutateAsync(1));
    }],
    ['eliminazione', async (wrapper) => {
      const { result } = renderHook(() => useDeleteVehicle(), { wrapper });
      await act(() => result.current.mutateAsync(1));
    }],
  ];

  it.each(vehicleMutations)('%s di un veicolo invalida i grafici', async (_case, mutate) => {
    const qc = newClient();
    const isInvalidated = seedCharts(qc);

    await mutate(wrapperFor(qc));

    expect(isInvalidated()).toBe(true);
  });
});
