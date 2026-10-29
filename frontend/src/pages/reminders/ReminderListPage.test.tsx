import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import type { Vehicle } from '@/api/types/vehicle';
import { ReminderListPage } from './ReminderListPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const golf: Vehicle = {
  id: 1,
  name: 'Golf',
  brand: 'VW',
  model: 'Golf',
  year: 2020,
  licensePlate: null,
  vin: null,
  type: 'car',
  fuelType: 'diesel',
  secondaryFuelType: null,
  initialKm: 0,
  notes: null,
  ownership: 'owned',
  permissions: { canEdit: true, canDelete: true, canShare: true },
};

const item = (id: number, type: string, description: string) => ({
  id,
  vehicle: { id: 1, name: 'Golf', brand: 'VW', model: 'Golf' },
  type,
  description,
  dueDate: '2026-03-12',
  dueKm: null,
  notifyDaysBefore: 30,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
  urgency: 'soon',
  currentKm: 1000,
});

/** Chiamate alla panoramica, con i parametri come li legge il server. */
function overviewCalls(): URLSearchParams[] {
  return mockedAuthFetch.mock.calls
    .map(([path]) => path)
    .filter((path) => path.startsWith('/api/reminders/overview'))
    .map((path) => new URLSearchParams(path.split('?')[1] ?? ''));
}

function renderOverview(url = '/reminders') {
  mockedAuthFetch.mockImplementation(async (path: string) => {
    if (path === '/api/vehicles') return [golf];
    if (path.startsWith('/api/reminders/overview')) {
      const q = new URLSearchParams(path.split('?')[1] ?? '').get('q');
      if (q === null) return [item(1, 'road_tax', 'Bollo'), item(2, 'insurance', 'Polizza')];
      if (q === 'bollo') return [item(1, 'road_tax', 'Bollo')];
      return [];
    }
    throw new Error(`unexpected fetch ${path}`);
  });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[url]}>
        <Routes>
          <Route path="/reminders" element={<ReminderListPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ReminderListPage — panoramica "Scadenze" con ricerca', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('cerca nella panoramica di tutti i veicoli, con i tipi tradotti, dopo il ritardo', async () => {
    renderOverview();
    const user = userEvent.setup();
    expect(await screen.findByText('Polizza')).toBeInTheDocument();

    await user.type(screen.getByRole('searchbox', { name: "Cerca nell'elenco" }), 'bollo');

    await waitFor(() => expect(screen.queryByText('Polizza')).not.toBeInTheDocument());
    expect(screen.getByText('Bollo')).toBeInTheDocument();
    expect(overviewCalls().map((p) => p.get('q'))).toEqual([null, 'bollo']);
    expect(overviewCalls()[1]?.getAll('hint[0][]')).toEqual(['reminder_type:road_tax']);
  });

  it('senza risultati lo dice e la richiesta della lista del veicolo non parte', async () => {
    renderOverview('/reminders?q=zzz');

    expect(await screen.findByRole('status')).toHaveTextContent('Nessun risultato per «zzz»');
    expect(mockedAuthFetch.mock.calls.some(([path]) => path.startsWith('/api/reminders?'))).toBe(false);
  });
});
