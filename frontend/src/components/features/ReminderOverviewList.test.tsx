import { describe, expect, it, beforeEach, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { MemoryRouter } from 'react-router-dom';
import i18n from '@/i18n';
import { ReminderOverviewList } from './ReminderOverviewList';
import type { ReminderOverviewItem } from '@/api/types/reminder';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));

const item = (over: Partial<ReminderOverviewItem>): ReminderOverviewItem => ({
  id: 1,
  vehicleId: 1,
  vehicleName: 'Panda',
  type: 'custom',
  description: 'Revisione',
  dueDate: null,
  dueKm: null,
  notifyDaysBefore: 30,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
  urgency: 'ok',
  currentKm: 0,
  ...over,
});

function renderList(items: ReminderOverviewItem[]) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ReminderOverviewList items={items} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ReminderOverviewList (tab Scadenze)', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('divide le scadenze in Scadute, In scadenza e Più avanti, con il conteggio, e omette le sezioni vuote', () => {
    renderList([
      item({ id: 1, description: 'Revisione', urgency: 'overdue', dueDate: '2026-10-19' }),
      item({ id: 2, description: 'Bollo', urgency: 'overdue', dueDate: '2026-10-20' }),
      item({ id: 3, description: 'Tagliando', urgency: 'ok', dueDate: '2027-05-01' }),
    ]);

    const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(headings).toEqual(['Scadute · 2', 'Più avanti · 1']);
  });

  it('ogni riga porta al promemoria e dice veicolo, data e km mancanti', () => {
    renderList([
      item({ id: 7, vehicleName: 'Golf', description: 'Cambio olio', urgency: 'soon', dueKm: 20_000, currentKm: 19_600 }),
    ]);

    const link = screen.getByRole('link', { name: /Cambio olio/ });
    expect(link).toHaveAttribute('href', '/reminders/7');
    expect(within(link).getByText('Golf · a 20.000 km (mancano 400 km)')).toBeInTheDocument();
  });

  it('una scadenza a km superata dice di quanto', () => {
    renderList([item({ urgency: 'overdue', dueKm: 20_000, currentKm: 20_250 })]);

    expect(screen.getByText('Panda · a 20.000 km (superati di 250 km)')).toBeInTheDocument();
  });

  it('"Completa" (scorrendo la riga) apre il foglio di completamento di quella scadenza', async () => {
    vi.mocked(authFetch).mockResolvedValue({ currentKm: 19_600 });
    renderList([item({ id: 7, vehicleId: 2, description: 'Cambio olio', urgency: 'soon', dueKm: 20_000, recurrenceKm: 10_000 })]);

    await userEvent.click(screen.getByRole('button', { name: 'Completa' }));

    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(await screen.findByLabelText(/Km al completamento/)).toBeInTheDocument();
  });
});
