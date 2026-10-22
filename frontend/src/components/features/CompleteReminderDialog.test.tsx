import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import type { Reminder, ReminderResponse } from '@/api/types/reminder';
import { Toaster } from '@/components/ui';
import { vehicleStatsKey } from '@/hooks/useVehicles';
import { todayIso } from '@/lib/format';
import { CompleteReminderDialog } from './CompleteReminderDialog';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const STATS_URL = '/api/vehicles/7/stats';
const COMPLETE_URL = '/api/reminders/3/complete';

const reminderResponse = (over: Partial<ReminderResponse> = {}): ReminderResponse => ({
  id: 3,
  vehicle: { id: 7, name: 'Golf', brand: 'VW', model: 'Golf' },
  type: 'service',
  description: 'Tagliando',
  dueDate: null,
  dueKm: 10_000,
  notifyDaysBefore: 30,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: 10_000,
  completedAt: null,
  completedOn: null,
  completedKm: null,
  ...over,
});

const recurring: Pick<Reminder, 'id' | 'vehicleId' | 'recurrenceMonths' | 'recurrenceKm'> = {
  id: 3,
  vehicleId: 7,
  recurrenceMonths: null,
  recurrenceKm: 10_000,
};

const stats = (currentKm: number) => ({
  consumption: {},
  totals: { cost: '0.00', refuelings: 1, maintenances: 0, expenses: 0 },
  currentKm,
  kmDriven: 0,
  costPerKm: null,
});

/** Le stats possono arrivare in ritardo: `releaseStats()` le consegna. */
function mockApi(complete: () => unknown = () => ({ completed: reminderResponse(), next: null })) {
  let releaseStats: (value: unknown) => void = () => {};
  const statsPromise = new Promise((resolve) => {
    releaseStats = resolve;
  });
  mockedAuthFetch.mockImplementation(async (path: string, init?: RequestInit) => {
    if (path === STATS_URL) return statsPromise;
    if (path === COMPLETE_URL && init?.method === 'POST') return complete();
    throw new Error(`unexpected fetch ${path}`);
  });
  return { releaseStats: (km: number) => releaseStats(stats(km)) };
}

function renderDialog(reminder = recurring) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const onOpenChange = vi.fn();
  const onCompleted = vi.fn();
  render(
    <QueryClientProvider client={qc}>
      <CompleteReminderDialog open onOpenChange={onOpenChange} reminder={reminder} onCompleted={onCompleted} />
      <Toaster />
    </QueryClientProvider>,
  );
  return { qc, onOpenChange, onCompleted };
}

const completeCalls = () => mockedAuthFetch.mock.calls.filter(([path]) => path === COMPLETE_URL);

describe('CompleteReminderDialog', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('precompila oggi e i km attuali anche se arrivano dopo l\'apertura', async () => {
    const { releaseStats } = mockApi();
    renderDialog();

    expect(screen.getByLabelText(/Data del completamento/)).toHaveValue(todayIso());
    expect(screen.getByLabelText(/Km al completamento/)).toHaveValue(null);

    releaseStats(9800);

    await waitFor(() => expect(screen.getByLabelText(/Km al completamento/)).toHaveValue(9800));
  });

  it('non sovrascrive i km se l\'utente li ha già scritti', async () => {
    const user = userEvent.setup();
    const { releaseStats } = mockApi();
    const { qc } = renderDialog();

    await user.type(screen.getByLabelText(/Km al completamento/), '9500');
    releaseStats(9800);

    // Stats in cache e un giro di render/effect dopo: il valore digitato resta.
    await waitFor(() => expect(qc.getQueryData(vehicleStatsKey(7))).toBeDefined());
    await act(() => new Promise((r) => setTimeout(r, 0)));
    expect(screen.getByLabelText(/Km al completamento/)).toHaveValue(9500);
  });

  it('il submit manda data e km e passa il risultato alla pagina', async () => {
    const user = userEvent.setup();
    const next = reminderResponse({ id: 4, dueKm: 19_800 });
    const { releaseStats } = mockApi(() => ({
      completed: reminderResponse({ completedAt: '2026-10-01T08:00:00+00:00', completedOn: '2026-10-01T00:00:00+00:00', completedKm: 9800 }),
      next,
    }));
    const { onCompleted, onOpenChange } = renderDialog();
    releaseStats(9800);
    await waitFor(() => expect(screen.getByLabelText(/Km al completamento/)).toHaveValue(9800));

    await user.click(screen.getByRole('button', { name: 'Segna come completato' }));

    await waitFor(() => expect(onCompleted).toHaveBeenCalled());
    expect(completeCalls()).toHaveLength(1);
    expect(JSON.parse(String(completeCalls()[0]?.[1]?.body))).toEqual({ completedOn: todayIso(), completedKm: 9800 });
    expect(onCompleted.mock.calls[0]?.[0]).toMatchObject({ completed: { id: 3, completedKm: 9800 }, next: { id: 4, dueKm: 19_800 } });
    expect(onOpenChange).toHaveBeenCalledWith(false);
    expect(await screen.findByText('Promemoria completato, creata la prossima scadenza')).toBeInTheDocument();
  });

  it('un 422 sulla data mostra il messaggio sotto il campo', async () => {
    const user = userEvent.setup();
    const { releaseStats } = mockApi(() => {
      throw new ApiError('validation_failed', 422, undefined, [
        { field: 'completedOn', message: 'reminder.completed_on_future' },
      ]);
    });
    const { onCompleted } = renderDialog();
    releaseStats(9800);
    await waitFor(() => expect(screen.getByLabelText(/Km al completamento/)).toHaveValue(9800));

    await user.click(screen.getByRole('button', { name: 'Segna come completato' }));

    expect(await screen.findByText('La data di completamento non può essere nel futuro')).toBeInTheDocument();
    expect(onCompleted).not.toHaveBeenCalled();
  });

  it('una data futura è fermata già nel form, senza chiamare il backend', async () => {
    const user = userEvent.setup();
    const { releaseStats } = mockApi();
    renderDialog();
    releaseStats(9800);
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);

    const date = screen.getByLabelText(/Data del completamento/);
    await user.clear(date);
    await user.type(date, todayIso(tomorrow));
    await user.click(screen.getByRole('button', { name: 'Segna come completato' }));

    expect(await screen.findByText('La data di completamento non può essere nel futuro')).toBeInTheDocument();
    expect(completeCalls()).toHaveLength(0);
  });

  it('già completato (409): toast tradotto, il dialog si chiude e i promemoria si ricaricano', async () => {
    const user = userEvent.setup();
    const { releaseStats } = mockApi(() => {
      throw new ApiError('reminder.already_completed', 409);
    });
    const { qc, onCompleted, onOpenChange } = renderDialog();
    const invalidate = vi.spyOn(qc, 'invalidateQueries');
    releaseStats(9800);
    await waitFor(() => expect(screen.getByLabelText(/Km al completamento/)).toHaveValue(9800));

    await user.click(screen.getByRole('button', { name: 'Segna come completato' }));

    expect(await screen.findByText('Promemoria già completato')).toBeInTheDocument();
    expect(onCompleted).not.toHaveBeenCalled();
    expect(onOpenChange).toHaveBeenCalledWith(false);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['reminders'] });
  });

  it('un promemoria ricorrente mostra la nota sulla prossima scadenza', () => {
    mockApi();
    renderDialog();

    expect(screen.getByText('Verrà creata la prossima scadenza a partire da questi valori.')).toBeInTheDocument();
  });

  it('una tantum: niente nota e km facoltativi', async () => {
    const user = userEvent.setup();
    mockApi();
    const { onCompleted } = renderDialog({ id: 3, vehicleId: 7, recurrenceMonths: null, recurrenceKm: null });

    expect(screen.queryByText('Verrà creata la prossima scadenza a partire da questi valori.')).not.toBeInTheDocument();

    // Stats mai arrivate: si completa con la sola data.
    await user.click(screen.getByRole('button', { name: 'Segna come completato' }));

    await waitFor(() => expect(onCompleted).toHaveBeenCalled());
    expect(JSON.parse(String(completeCalls()[0]?.[1]?.body))).toEqual({ completedOn: todayIso(), completedKm: null });
  });
});
