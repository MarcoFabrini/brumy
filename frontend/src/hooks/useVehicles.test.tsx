import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { dashboardKeys } from '@/hooks/useDashboardCharts';
import { reminderKeys } from '@/hooks/useReminders';
import {
  useArchivedVehicles,
  useArchiveVehicle,
  useUnarchiveVehicle,
  useVehicles,
  vehicleKeys,
} from './useVehicles';
import type { Vehicle } from '@/api/types/vehicle';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

const vehicle = (id: number, archivedAt: string | null): Vehicle => ({
  id,
  name: `Auto ${id}`,
  brand: 'Fiat',
  model: 'Panda',
  year: 2020,
  licensePlate: null,
  vin: null,
  type: 'car',
  fuelType: 'gasoline',
  secondaryFuelType: null,
  initialKm: 0,
  notes: null,
  archivedAt,
  ownership: 'owned',
  permissions: { canEdit: true, canDelete: true, canShare: true },
});

const active = [vehicle(1, null), vehicle(2, null)];
const archived = [vehicle(3, '2026-09-01T10:00:00+02:00')];

function setup() {
  mockedAuthFetch.mockImplementation(async (path: string) => {
    if (path === '/api/vehicles') return active;
    if (path === '/api/vehicles?archived=1') return archived;
    return {};
  });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return { qc, wrapper };
}

describe('useVehicles / useArchivedVehicles', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
  });

  it('la chiave degli archiviati sta sotto lists() ma è diversa da quella di useVehicles', () => {
    expect(vehicleKeys.archivedList()).not.toEqual(vehicleKeys.lists());
    expect(vehicleKeys.archivedList().slice(0, vehicleKeys.lists().length)).toEqual(vehicleKeys.lists());
  });

  it('useVehicles continua a ricevere solo gli attivi anche con gli archiviati in cache', async () => {
    const { qc, wrapper } = setup();

    const both = renderHook(() => ({ vehicles: useVehicles(), archived: useArchivedVehicles() }), { wrapper });
    await waitFor(() => expect(both.result.current.archived.isSuccess).toBe(true));
    await waitFor(() => expect(both.result.current.vehicles.isSuccess).toBe(true));

    expect(both.result.current.vehicles.data?.map((v) => v.id)).toEqual([1, 2]);
    expect(both.result.current.archived.data?.map((v) => v.id)).toEqual([3]);
    expect(qc.getQueryData(vehicleKeys.lists())).toEqual(active);
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles');
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles?archived=1');

    // Un secondo consumatore di useVehicles (selettore, aggiunta rapida, dashboard) non vede mai gli archiviati
    const picker = renderHook(() => useVehicles(), { wrapper });
    await waitFor(() => expect(picker.result.current.isSuccess).toBe(true));
    expect(picker.result.current.data?.map((v) => v.id)).toEqual([1, 2]);
  });

  it.each([
    ['archiviare', () => useArchiveVehicle()],
    ['ripristinare', () => useUnarchiveVehicle()],
  ])('%s invalida lista attivi, lista archiviati, dashboard e scadenze in arrivo', async (_name, useMutationHook) => {
    const { qc, wrapper } = setup();
    qc.setQueryData(vehicleKeys.lists(), active);
    qc.setQueryData(vehicleKeys.archivedList(), archived);
    qc.setQueryData(dashboardKeys.charts(12), { some: 'charts' });
    qc.setQueryData(reminderKeys.overview(), []);

    const { result } = renderHook(useMutationHook, { wrapper });
    await act(async () => {
      await result.current.mutateAsync(1);
    });

    expect(qc.getQueryState(vehicleKeys.lists())?.isInvalidated).toBe(true);
    expect(qc.getQueryState(vehicleKeys.archivedList())?.isInvalidated).toBe(true);
    expect(qc.getQueryState(dashboardKeys.charts(12))?.isInvalidated).toBe(true);
    expect(qc.getQueryState(reminderKeys.overview())?.isInvalidated).toBe(true);
  });
});
