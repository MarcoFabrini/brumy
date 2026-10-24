import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactElement } from 'react';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import type { Vehicle } from '@/api/types/vehicle';
import { DashboardPage } from './DashboardPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

vi.mock('recharts', async (importOriginal) => {
  const { cloneElement } = await import('react');
  return {
    ...(await importOriginal<typeof import('recharts')>()),
    ResponsiveContainer: ({ children }: { children: ReactElement<{ width?: number; height?: number }> }) =>
      cloneElement(children, { width: 400, height: 240 }),
  };
});

const CHARTS_URL = '/api/dashboard/charts?months=12';

const vehicle = (id: number, ownership: Vehicle['ownership']): Vehicle => ({
  id,
  name: `Auto ${id}`,
  brand: 'Fiat',
  model: 'Panda',
  year: 2020,
  licensePlate: null,
  vin: null,
  type: 'car',
  fuelType: 'diesel',
  secondaryFuelType: null,
  initialKm: 0,
  notes: null,
  ownership,
  permissions: { canEdit: ownership !== 'shared', canDelete: ownership !== 'shared', canShare: ownership !== 'shared' },
});

const ym = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const now = new Date();
const THIS_MONTH = ym(now);
const LAST_MONTH = ym(new Date(now.getFullYear(), now.getMonth() - 1, 1));

const spending = (fuel: string, maintenance: string, total: string) => ({
  refuelings: fuel,
  maintenances: maintenance,
  expenses: '0.00',
  total,
});

const reminder = (id: number, description: string, urgency: 'ok' | 'soon' | 'overdue') => ({
  id,
  vehicle: { id: 1, name: 'Auto 1', brand: 'Fiat', model: 'Panda' },
  type: 'inspection',
  description,
  dueDate: '2026-11-30T00:00:00+00:00',
  dueKm: null,
  notifyDaysBefore: 30,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
  urgency,
  currentKm: 12000,
});

function mockApi(vehicles: Vehicle[], reminders: ReturnType<typeof reminder>[] = []) {
  mockedAuthFetch.mockImplementation(async (path: string) => {
    if (path === '/api/vehicles') return vehicles;
    if (/^\/api\/vehicles\/\d+\/stats$/.test(path)) {
      return { consumption: {}, totals: { cost: '0.00', refuelings: 0, maintenances: 0, expenses: 0 }, currentKm: 12000, kmDriven: 0, costPerKm: null };
    }
    if (path === '/api/reminders/overview') return reminders;
    if (path === CHARTS_URL) {
      return {
        from: LAST_MONTH,
        to: THIS_MONTH,
        fuelTypes: ['diesel'],
        months: [
          { month: LAST_MONTH, spending: spending('100.00', '0.00', '100.00'), kmDriven: 900, consumption: { diesel: null } },
          { month: THIS_MONTH, spending: spending('40.00', '20.00', '60.00'), kmDriven: 450, consumption: { diesel: null } },
        ],
        spendingByCategory: [],
        totals: { spending: '160.00', kmDriven: 1350 },
      };
    }
    throw new Error(`unexpected fetch ${path}`);
  });
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('DashboardPage', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('solo veicoli condivisi o di altri membri: niente spese, scadenze né grafico, e nessuna richiesta', async () => {
    mockApi([vehicle(1, 'shared'), vehicle(2, 'organization')]);
    renderPage();

    expect(await screen.findByText('2 veicoli che vedi ma non sono tuoi sono nella tab Veicoli.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Aggiungi un veicolo' })).toHaveAttribute('href', '/vehicles/new');
    expect(screen.queryByText('Speso questo mese')).not.toBeInTheDocument();
    expect(mockedAuthFetch).not.toHaveBeenCalledWith(CHARTS_URL);
    expect(mockedAuthFetch).not.toHaveBeenCalledWith('/api/reminders/overview');
  });

  it('la spesa del mese corrente, col confronto sul mese prima e la ripartizione', async () => {
    mockApi([vehicle(1, 'owned')]);
    renderPage();

    const month = await screen.findByRole('region', { name: 'Speso questo mese' });
    expect(within(month).getByText(/60,00/)).toBeInTheDocument();
    expect(within(month).getByText(/40,00.*in meno del mese scorso/)).toBeInTheDocument();
    expect(within(month).getByText('Carburante').nextSibling).toHaveTextContent('40,00');
    expect(within(month).getByText('Km percorsi').nextSibling).toHaveTextContent('450 km');
    expect((await screen.findAllByText('Spese mensili')).length).toBeGreaterThan(0);
  });

  it('"Da fare" mostra solo le scadenze urgenti; il veicolo dice km attuali e stato', async () => {
    mockApi([vehicle(1, 'owned')], [reminder(1, 'Revisione', 'overdue'), reminder(2, 'Bollo', 'ok')]);
    renderPage();

    const todo = await screen.findByRole('region', { name: 'Da fare' });
    expect(within(todo).getByRole('link', { name: /Revisione/ })).toHaveAttribute('href', '/reminders/1');
    expect(within(todo).queryByText('Bollo')).not.toBeInTheDocument();
    expect(within(todo).getByRole('link', { name: 'Vedi tutte le scadenze' })).toHaveAttribute('href', '/reminders');

    const vehicles = screen.getByRole('region', { name: 'Veicoli' });
    const row = within(vehicles).getByRole('link', { name: /Auto 1/ });
    expect(row).toHaveAttribute('href', '/vehicles/1');
    expect(row).toHaveTextContent('12.000 km');
    expect(row).toHaveTextContent('Scaduto');
  });

  it('senza scadenze urgenti lo dice, invece di una lista vuota', async () => {
    mockApi([vehicle(1, 'owned')], [reminder(2, 'Bollo', 'ok')]);
    renderPage();

    const todo = await screen.findByRole('region', { name: 'Da fare' });
    expect(within(todo).getByText('Nessuna scadenza urgente')).toBeInTheDocument();
    expect(within(todo).queryByText('Bollo')).not.toBeInTheDocument();
  });

  it('ha esattamente un h1 (sr-only, con il nome della pagina)', async () => {
    mockApi([vehicle(1, 'owned')]);
    renderPage();

    await screen.findByText('Speso questo mese');
    const h1 = screen.getAllByRole('heading', { level: 1 });
    expect(h1).toHaveLength(1);
    expect(h1[0]).toHaveTextContent('Dashboard');
  });
});
