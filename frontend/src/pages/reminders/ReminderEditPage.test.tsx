import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import type { Vehicle } from '@/api/types/vehicle';
import type { ReminderResponse } from '@/api/types/reminder';
import { ReminderEditPage } from './ReminderEditPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const reminder: ReminderResponse = {
  id: 3,
  vehicle: { id: 7, name: 'Golf', brand: 'VW', model: 'Golf' },
  type: 'inspection',
  description: 'Revisione biennale',
  dueDate: '2026-12-01T00:00:00+00:00',
  dueKm: null,
  notifyDaysBefore: 7,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
};

const vehicle = (overrides: Partial<Vehicle> = {}): Vehicle => ({
  id: 7,
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
  archivedAt: null,
  ownership: 'owned',
  permissions: { canEdit: true, canDelete: true, canShare: false },
  ...overrides,
});

function renderPage(vehicleResult: () => Promise<Vehicle>) {
  mockedAuthFetch.mockImplementation(async (path: string) => {
    if (path === '/api/reminders/3') return reminder;
    if (path === '/api/vehicles/7') return vehicleResult();
    throw new Error(`unexpected fetch ${path}`);
  });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/reminders/3/edit']}>
        <Routes>
          <Route path="/reminders/:id/edit" element={<ReminderEditPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ReminderEditPage — permessi sul veicolo', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('finché il veicolo carica il form non compare', async () => {
    renderPage(() => new Promise(() => {}));

    expect(await screen.findByRole('heading', { level: 1, name: 'Modifica promemoria' })).toBeInTheDocument();
    expect(screen.queryByLabelText(/Descrizione/)).not.toBeInTheDocument();
  });

  it('se il veicolo risponde 404 mostra l\'errore e nessun form', async () => {
    renderPage(() => Promise.reject(new ApiError('vehicle.not_found', 404)));

    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByLabelText(/Descrizione/)).not.toBeInTheDocument();
  });

  it('sola lettura: avviso e nessun form', async () => {
    renderPage(async () =>
      vehicle({ ownership: 'shared', permissions: { canEdit: false, canDelete: false, canShare: false } }),
    );

    expect(await screen.findByText(/condiviso con te in sola lettura/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Descrizione/)).not.toBeInTheDocument();
  });

  it('veicolo modificabile: il form compare precompilato', async () => {
    renderPage(async () => vehicle());

    expect(await screen.findByLabelText(/Descrizione/)).toHaveValue('Revisione biennale');
  });
});
