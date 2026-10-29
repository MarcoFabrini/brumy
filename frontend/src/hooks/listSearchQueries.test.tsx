import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { maintenanceKeys, useMaintenances } from '@/hooks/useMaintenances';
import { refuelingKeys, useRefuelingList, useRefuelings } from '@/hooks/useRefuelings';
import { reminderKeys, useReminderOverview, useReminders } from '@/hooks/useReminders';
import { useArchivedVehicles, useVehicles, vehicleKeys } from '@/hooks/useVehicles';
import { LIST_PAGE_SIZE } from '@/lib/pagination';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

const SEARCH = 'q=tagl&hint%5B0%5D%5B%5D=maintenance_type%3Ainspection';

function setup() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  return { qc, wrapper };
}

const refueling = (id: number) => ({
  id,
  vehicle: { id: 1, name: 'Golf', brand: 'VW', model: 'Golf' },
  refueledAt: '2026-01-15T00:00:00+00:00',
  km: 1000 + id,
  liters: '40.000',
  pricePerLiter: '1.8000',
  totalCost: '72.00',
  fuelType: 'diesel',
  fullTank: true,
  description: null,
  notes: null,
});

describe('elenchi: chiavi e URL con e senza ricerca', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
  });

  it('useRefuelings (aggiunta rapida) resta identico: stesso URL, stessa chiave', async () => {
    mockedAuthFetch.mockResolvedValue([]);
    const { qc, wrapper } = setup();

    const { result } = renderHook(() => useRefuelings(1), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/refuelings?vehicleId=1');
    expect(qc.getQueryCache().find({ queryKey: refuelingKeys.byVehicle(1), exact: true })).toBeDefined();
  });

  it('useVehicles e useReminderOverview senza ricerca: URL e chiavi di sempre', async () => {
    mockedAuthFetch.mockResolvedValue([]);
    const { qc, wrapper } = setup();

    renderHook(() => [useVehicles(), useArchivedVehicles(), useReminderOverview()], { wrapper });

    await waitFor(() => expect(mockedAuthFetch).toHaveBeenCalledTimes(3));
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles');
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles?archived=1');
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/reminders/overview');
    for (const key of [vehicleKeys.lists(), vehicleKeys.archivedList(), reminderKeys.overview()]) {
      expect(qc.getQueryCache().find({ queryKey: key, exact: true })).toBeDefined();
    }
  });

  it('con una ricerca la chiave è figlia di quella di sempre e le invalidazioni esistenti la coprono', async () => {
    mockedAuthFetch.mockResolvedValue([]);
    const { qc, wrapper } = setup();

    renderHook(() => [useVehicles(SEARCH), useReminderOverview({ search: SEARCH }), useReminders(1, SEARCH)], { wrapper });

    await waitFor(() => expect(mockedAuthFetch).toHaveBeenCalledTimes(3));
    expect(mockedAuthFetch).toHaveBeenCalledWith(`/api/vehicles?${SEARCH}`);
    expect(mockedAuthFetch).toHaveBeenCalledWith(`/api/reminders/overview?${SEARCH}`);
    expect(mockedAuthFetch).toHaveBeenCalledWith(`/api/reminders?vehicleId=1&${SEARCH}`);

    await act(() =>
      Promise.all([
        qc.invalidateQueries({ queryKey: vehicleKeys.lists() }),
        qc.invalidateQueries({ queryKey: reminderKeys.overview() }),
        qc.invalidateQueries({ queryKey: reminderKeys.byVehicle(1) }),
      ]),
    );
    expect(mockedAuthFetch).toHaveBeenCalledTimes(6);
  });

  it('useMaintenances chiede pagine di 20 con sezione e ricerca, sotto byVehicle(id)', async () => {
    mockedAuthFetch.mockResolvedValue([]);
    const { qc, wrapper } = setup();

    const { result } = renderHook(() => useMaintenances(1, 'inspection', SEARCH), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockedAuthFetch).toHaveBeenCalledWith(
      `/api/maintenances?vehicleId=1&category=inspection&page=1&limit=${LIST_PAGE_SIZE}&${SEARCH}`,
    );
    expect(qc.getQueryCache().findAll({ queryKey: maintenanceKeys.byVehicle(1) })).toHaveLength(1);
    expect(result.current.hasNextPage).toBe(false);
  });

  it('pagine successive: caricate a richiesta e ricaricate tutte da un\'invalidazione di byVehicle(id)', async () => {
    mockedAuthFetch.mockImplementation(async (path: string) => {
      if (path.includes('page=1&')) return Array.from({ length: LIST_PAGE_SIZE }, (_, i) => refueling(i + 1));
      if (path.includes('page=2&')) return [refueling(100)];
      throw new Error(`unexpected fetch ${path}`);
    });
    const { qc, wrapper } = setup();

    const { result } = renderHook(() => useRefuelingList(1), { wrapper });

    await waitFor(() => expect(result.current.hasNextPage).toBe(true));
    await act(() => result.current.fetchNextPage());
    await waitFor(() => expect(result.current.data?.pages.flat()).toHaveLength(LIST_PAGE_SIZE + 1));
    expect(result.current.hasNextPage).toBe(false);
    expect(mockedAuthFetch).toHaveBeenCalledWith(`/api/refuelings?vehicleId=1&page=2&limit=${LIST_PAGE_SIZE}`);

    mockedAuthFetch.mockClear();
    await act(() => qc.invalidateQueries({ queryKey: refuelingKeys.byVehicle(1) }));
    expect(mockedAuthFetch.mock.calls.map(([path]) => path)).toEqual([
      `/api/refuelings?vehicleId=1&page=1&limit=${LIST_PAGE_SIZE}`,
      `/api/refuelings?vehicleId=1&page=2&limit=${LIST_PAGE_SIZE}`,
    ]);
  });
});
