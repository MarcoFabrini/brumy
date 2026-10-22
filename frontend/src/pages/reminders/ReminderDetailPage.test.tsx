import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import type { ReminderResponse } from '@/api/types/reminder';
import { ReminderDetailPage } from './ReminderDetailPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const base: ReminderResponse = {
  id: 3,
  vehicle: { id: 7, name: 'Golf', brand: 'VW', model: 'Golf' },
  type: 'inspection',
  description: 'Revisione',
  dueDate: null,
  dueKm: null,
  notifyDaysBefore: 7,
  notifyKmBefore: 1000,
  recurrenceMonths: null,
  recurrenceKm: null,
  completedAt: null,
  completedOn: null,
  completedKm: null,
};

// Completato alle 00:30 del 1 ottobre a Roma (UTC+2): in UTC è ancora il 30 settembre.
const completed: ReminderResponse = { ...base, completedAt: '2026-09-30T22:30:00+00:00' };

/** Revisione ogni 2 anni, ancora da fare, su un veicolo modificabile. */
const recurring: ReminderResponse = {
  ...base,
  dueDate: '2026-12-15T00:00:00+00:00',
  recurrenceMonths: 24,
};

function LocationProbe() {
  return <p data-testid="location">{useLocation().pathname}</p>;
}

function renderPage(
  reminders: ReminderResponse[] = [completed],
  { canEdit = false, complete }: { canEdit?: boolean; complete?: () => unknown } = {},
) {
  mockedAuthFetch.mockImplementation(async (path: string, init?: RequestInit) => {
    if (path === '/api/reminders/3/complete' && init?.method === 'POST' && complete) return complete();
    const reminder = reminders.find((r) => path === `/api/reminders/${r.id}`);
    if (reminder) return reminder;
    if (path === '/api/vehicles/7') {
      return { id: 7, permissions: { canEdit, canDelete: canEdit, canShare: false } };
    }
    if (path === '/api/vehicles/7/stats') {
      return { consumption: {}, totals: { cost: '0.00', refuelings: 0, maintenances: 0, expenses: 0 }, currentKm: 50_000, kmDriven: 0, costPerKm: null };
    }
    return [];
  });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/reminders/3']}>
        <Routes>
          <Route path="/reminders/:id" element={<ReminderDetailPage />} />
        </Routes>
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const completeCalls = () => mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/reminders/3/complete');

/** Valore della riga del `<dl>` con quell'etichetta. */
async function row(label: string): Promise<HTMLElement> {
  const dt = await screen.findByText(label, { selector: 'dt' });
  const dd = dt.nextElementSibling;
  if (!(dd instanceof HTMLElement)) throw new Error(`nessun valore per ${label}`);
  return dd;
}

describe('ReminderDetailPage — data di completamento', () => {
  const originalTz = process.env.TZ;

  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    process.env.TZ = 'Europe/Rome';
  });

  afterEach(() => {
    if (originalTz === undefined) delete process.env.TZ;
    else process.env.TZ = originalTz;
  });

  it('mostra il giorno locale, non quello UTC', async () => {
    renderPage();

    expect(await screen.findByText(/^01 ott 2026$/)).toBeInTheDocument();
    expect(screen.queryByText(/30 set/)).not.toBeInTheDocument();
  });
});

describe('ReminderDetailPage — ricorrenza e completamento', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    process.env.TZ = 'Europe/Rome';
  });

  it('un promemoria ricorrente mostra la ricorrenza; una tantum lo dice', async () => {
    const { unmount } = renderPage([recurring]);
    expect(await row('Ricorrenza')).toHaveTextContent('ogni 2 anni');
    unmount();

    renderPage([{ ...base, dueDate: '2026-12-15T00:00:00+00:00' }]);
    expect(await row('Ricorrenza')).toHaveTextContent('Una tantum');
  });

  it('l\'anticipo a km compare solo con una scadenza a km', async () => {
    const { unmount } = renderPage([{ ...base, dueKm: 20_000, notifyKmBefore: 500 }]);
    expect(await row('Avvisa km prima')).toHaveTextContent('500 km');
    unmount();

    renderPage([recurring]);
    await row('Ricorrenza');
    expect(screen.queryByText('Avvisa km prima')).not.toBeInTheDocument();
  });

  it('completato con data e km effettivi: mostra quella data e i km', async () => {
    renderPage([{ ...completed, completedOn: '2026-09-28T00:00:00+00:00', completedKm: 109_800 }]);

    expect(await row('Completato il')).toHaveTextContent(/^28 set 2026$/);
    expect(await row('Km al completamento')).toHaveTextContent('109.800 km');
  });

  it('il bottone apre il dialog senza completare; con un successore si passa alla sua pagina', async () => {
    const user = userEvent.setup();
    const next: ReminderResponse = { ...recurring, id: 4, dueDate: '2028-10-01T00:00:00+00:00' };
    renderPage([recurring, next], {
      canEdit: true,
      complete: () => ({
        completed: { ...recurring, completedAt: '2026-10-01T08:00:00+00:00', completedOn: '2026-10-01T00:00:00+00:00', completedKm: 50_000 },
        next,
      }),
    });

    await user.click(await screen.findByRole('button', { name: 'Segna come completato' }));

    const dialog = await screen.findByRole('dialog', { name: 'Segna come completato' });
    expect(completeCalls()).toHaveLength(0);

    await user.click(within(dialog).getByRole('button', { name: 'Segna come completato' }));

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/reminders/4'));
    expect(completeCalls()).toHaveLength(1);
    expect(await row('Scadenza (data)')).toHaveTextContent('01 ott 2028');
  });
});
