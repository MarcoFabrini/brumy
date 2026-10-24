import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { useDashboard } from './useDashboard';
import type { Vehicle } from '@/api/types/vehicle';
import type { VehicleStats } from '@/api/types/vehicleStats';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

const vehicle = (id: number, ownership: Vehicle['ownership']): Vehicle => ({
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
  ownership,
  permissions: { canEdit: ownership !== 'shared', canDelete: ownership !== 'shared', canShare: ownership !== 'shared' },
});

const stats = (cost: string, refuelings: number): VehicleStats => ({
  consumption: {},
  totals: { cost, refuelings, maintenances: 1, expenses: 0 },
  currentKm: 0,
  kmDriven: 0,
  costPerKm: null,
});

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useDashboard', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
  });

  it('solo i veicoli propri, con le loro statistiche: né quelli condivisi né quelli di altri membri', async () => {
    mockedAuthFetch.mockImplementation(async (path: string) => {
      if (path === '/api/vehicles') {
        return [vehicle(1, 'owned'), vehicle(2, 'shared'), vehicle(3, 'organization'), vehicle(4, 'owned')];
      }
      if (path === '/api/vehicles/1/stats') return stats('100.50', 2);
      if (path === '/api/vehicles/4/stats') return stats('20.00', 1);
      throw new Error(`unexpected fetch ${path}`);
    });

    const { result } = renderHook(() => useDashboard(), { wrapper });

    await waitFor(() => expect(result.current.statsById.size).toBe(2));
    expect(result.current.vehicles.map((v) => v.id)).toEqual([1, 4]);
    expect(result.current.excludedCount).toBe(2);
    expect(result.current.statsById.get(1)?.totals.cost).toBe('100.50');
    expect(result.current.statsById.get(4)?.totals.cost).toBe('20.00');
    // Le statistiche dei veicoli non propri non vengono nemmeno chieste.
    expect(mockedAuthFetch).not.toHaveBeenCalledWith('/api/vehicles/2/stats');
    expect(mockedAuthFetch).not.toHaveBeenCalledWith('/api/vehicles/3/stats');
  });
});
