import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { ReminderBadge } from './ReminderBadge';
import type { Reminder } from '@/api/types/reminder';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

const kmReminder: Reminder = {
  id: 1,
  vehicleId: 3,
  type: 'oil_change',
  description: 'Cambio olio',
  dueDate: null,
  dueKm: 100_000,
  notifyDaysBefore: 7,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
};

function renderBadge(reminder: Reminder) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <ReminderBadge reminder={reminder} />
    </QueryClientProvider>,
  );
}

describe('ReminderBadge — promemoria a chilometri', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('km del veicolo oltre la soglia: "scaduto"', async () => {
    mockedAuthFetch.mockResolvedValue({ currentKm: 100_500 });
    renderBadge(kmReminder);

    expect(await screen.findByText('Scaduto')).toBeInTheDocument();
  });

  it('km vicini alla soglia: "in scadenza"', async () => {
    mockedAuthFetch.mockResolvedValue({ currentKm: 99_500 });
    renderBadge(kmReminder);

    expect(await screen.findByText('In scadenza')).toBeInTheDocument();
  });

  it('completato o solo a data: non chiede le statistiche del veicolo', () => {
    renderBadge({ ...kmReminder, completedAt: '2026-09-01T10:00:00+00:00' });
    renderBadge({ ...kmReminder, dueKm: null, dueDate: '2030-01-01' });

    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });
});
