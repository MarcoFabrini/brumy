import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactElement } from 'react';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import type { DashboardCharts as DashboardChartsData } from '@/api/types/dashboardCharts';
import { VehicleCharts } from './VehicleCharts';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

// In jsdom ResponsiveContainer misura 0×0 e non disegna nulla: dimensione fissa.
vi.mock('recharts', async (importOriginal) => {
  const { cloneElement } = await import('react');
  return {
    ...(await importOriginal<typeof import('recharts')>()),
    ResponsiveContainer: ({ children }: { children: ReactElement<{ width?: number; height?: number }> }) =>
      cloneElement(children, { width: 400, height: 240 }),
  };
});

const charts: DashboardChartsData = {
  from: '2026-10',
  to: '2026-10',
  fuelTypes: ['diesel'],
  months: [
    {
      month: '2026-10',
      spending: { refuelings: '84.58', scheduled: '0.00', unscheduled: '0.00', inspection: '0.00', taxes: '0.00', insurance: '0.00', expenses: '0.00', total: '84.58' },
      kmDriven: 900,
      consumption: { diesel: 19.5 },
    },
  ],
  spendingByCategory: [{ category: 'fuel', name: null, amount: '84.58' }],
  totals: { spending: '84.58', kmDriven: 900 },
};

function renderCharts(vehicleId: number) {
  mockedAuthFetch.mockImplementation((path: string) => {
    if (/^\/api\/vehicles\/\d+\/charts\?months=12$/.test(path)) return Promise.resolve(charts);
    return Promise.reject(new Error(`unexpected fetch ${path}`));
  });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <VehicleCharts vehicleId={vehicleId} />
    </QueryClientProvider>,
  );
}

describe('VehicleCharts', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('mostra i 4 grafici del solo veicolo sotto il titoletto "Andamento"', async () => {
    renderCharts(1);

    expect(await screen.findByRole('heading', { name: 'Andamento' })).toBeInTheDocument();
    expect(await screen.findByRole('table', { name: 'Spese mensili' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Spese per categoria' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Km percorsi' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Consumo medio (km/l)' })).toBeInTheDocument();
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/1/charts?months=12');
  });
});
