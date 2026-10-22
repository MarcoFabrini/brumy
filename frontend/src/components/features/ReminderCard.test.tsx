import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import type { Reminder } from '@/api/types/reminder';
import { ReminderCard } from './ReminderCard';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const reminder = (overrides: Partial<Reminder>): Reminder => ({
  id: 1,
  vehicleId: 7,
  type: 'service',
  description: 'Tagliando',
  dueDate: null,
  dueKm: null,
  notifyDaysBefore: 7,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
  ...overrides,
});

function renderCard(r: Reminder) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ReminderCard reminder={r} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ReminderCard', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    mockedAuthFetch.mockResolvedValue([]);
    await i18n.changeLanguage('it');
  });

  afterEach(async () => {
    cleanup();
    await i18n.changeLanguage('it');
  });

  it('con scadenza a 0 km mostra "0 km", non uno "0" nudo', () => {
    renderCard(reminder({ dueKm: 0 }));

    expect(screen.getByText('0 km')).toBeInTheDocument();
    expect(screen.queryByText('0')).not.toBeInTheDocument();
  });

  it('data e km a 0: entrambi con il separatore', () => {
    renderCard(reminder({ dueDate: '2026-12-01', dueKm: 0 }));

    expect(screen.getByText('0 km')).toBeInTheDocument();
    expect(screen.getByText('·')).toBeInTheDocument();
    expect(screen.queryByText('0')).not.toBeInTheDocument();
  });

  it('senza km di scadenza non mostra nulla accanto alla data', () => {
    renderCard(reminder({ dueDate: '2026-12-01', dueKm: null }));

    expect(screen.queryByText('·')).not.toBeInTheDocument();
    expect(screen.queryByText(/km$/)).not.toBeInTheDocument();
  });

  it('un promemoria ricorrente mostra la ricorrenza a tempo e a km', () => {
    renderCard(reminder({ dueDate: '2026-12-01', dueKm: 10_000, recurrenceMonths: 12, recurrenceKm: 10_000 }));

    expect(screen.getByText('ogni anno · ogni 10.000 km')).toBeInTheDocument();
  });

  it('ricorrenza a soli mesi o a soli anni', () => {
    renderCard(reminder({ dueDate: '2026-12-01', recurrenceMonths: 24 }));
    expect(screen.getByText('ogni 2 anni')).toBeInTheDocument();

    cleanup();
    renderCard(reminder({ dueDate: '2026-12-01', recurrenceMonths: 6 }));
    expect(screen.getByText('ogni 6 mesi')).toBeInTheDocument();
  });

  it('una tantum: nessun testo di ricorrenza', () => {
    renderCard(reminder({ dueDate: '2026-12-01', dueKm: 10_000 }));

    expect(screen.queryByText(/ogni/)).not.toBeInTheDocument();
  });

  it('in inglese', async () => {
    await i18n.changeLanguage('en');
    renderCard(reminder({ dueDate: '2026-12-01', dueKm: 10_000, recurrenceMonths: 12, recurrenceKm: 10_000 }));

    expect(screen.getByText('every year · every 10,000 km')).toBeInTheDocument();
  });
});
