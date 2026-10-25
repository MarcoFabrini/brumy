import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render as rtlRender, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import type { Expense } from '@/api/types/expense';
import { ExpenseForm } from './ExpenseForm';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

/** I form chiedono i suggerimenti con una query: serve un QueryClient. */
const render: typeof rtlRender = (ui, options) =>
  rtlRender(ui, {
    wrapper: ({ children }) => (
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        {children}
      </QueryClientProvider>
    ),
    ...options,
  });

beforeEach(() => {
  mockedAuthFetch.mockResolvedValue([
    { id: 1, name: 'Q8 Via Roma' },
    { id: 2, name: 'Eni A1' },
  ]);
});

function renderForm(props: Partial<React.ComponentProps<typeof ExpenseForm>> = {}) {
  const onSubmit = vi.fn();
  const utils = render(<ExpenseForm vehicleId={7} section="other" onSubmit={onSubmit} {...props} />);
  return { onSubmit, ...utils };
}

/** Spesa mensile già compilata, così i test non devono passare dal Select Radix. */
const recurringDefaults: Partial<Expense> = {
  occurredAt: '2026-01-15',
  category: 'Abbonamento',
  description: 'Telepass',
  amount: '50.00',
  recurring: true,
  recurringPeriod: 'monthly',
};

describe('ExpenseForm — data di fine della ricorrenza', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('il campo "fino al" compare solo se la spesa è ricorrente', async () => {
    const user = userEvent.setup();
    renderForm();

    expect(screen.queryByLabelText(/Fino al/)).not.toBeInTheDocument();

    await user.click(screen.getByRole('switch', { name: /Spesa ricorrente/ }));

    expect(screen.getByLabelText(/Fino al/)).toBeInTheDocument();

    await user.click(screen.getByRole('switch', { name: /Spesa ricorrente/ }));

    expect(screen.queryByLabelText(/Fino al/)).not.toBeInTheDocument();
  });

  it('invia la data di fine in formato ISO', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: recurringDefaults });

    fireEvent.change(screen.getByLabelText(/Fino al/), { target: { value: '2026-06-15' } });
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({
      recurring: true,
      recurringPeriod: 'monthly',
      recurringUntil: '2026-06-15',
    });
  });

  it('senza data di fine invia null (spesa in corso)', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: recurringDefaults });

    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ recurring: true, recurringUntil: null });
  });

  it('in modifica precompila la data di fine esistente', () => {
    renderForm({ defaultValues: { ...recurringDefaults, recurringUntil: '2026-09-30' } });

    expect(screen.getByLabelText(/Fino al/)).toHaveValue('2026-09-30');
  });

  it('una data di fine prima della data della spesa è un errore sul campo e non invia', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: recurringDefaults });

    fireEvent.change(screen.getByLabelText(/Fino al/), { target: { value: '2026-01-14' } });
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    expect(await screen.findByText('La data di fine non può precedere la data della spesa')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('la data di fine uguale a quella della spesa è valida', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: recurringDefaults });

    fireEvent.change(screen.getByLabelText(/Fino al/), { target: { value: '2026-01-15' } });
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ recurringUntil: '2026-01-15' });
  });

  it('togliendo "ricorrente" periodo e data di fine non vengono inviati', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: { ...recurringDefaults, recurringUntil: '2026-09-30' } });

    await user.click(screen.getByRole('switch', { name: /Spesa ricorrente/ }));
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({
      recurring: false,
      recurringPeriod: null,
      recurringUntil: null,
    });
  });

  it("l'errore di validazione del backend compare sul campo e in italiano", async () => {
    const error = new ApiError('validation_failed', 422, undefined, [
      { field: 'recurringUntil', message: 'expense.recurring_until_before_start' },
    ]);
    renderForm({ defaultValues: recurringDefaults, error });

    expect(await screen.findByText('La data di fine non può precedere il primo addebito')).toBeInTheDocument();
    // L'errore ha un campo visibile: niente avviso generico.
    expect(screen.queryByText(/Errore inatteso/)).not.toBeInTheDocument();
  });
});

describe('ExpenseForm — categoria con suggerimenti', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('la categoria è obbligatoria: vuota mostra l\'errore expense.category.required', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Descrizione/), 'Telepass');
    await user.type(screen.getByLabelText(/Importo/), '50');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    expect(await screen.findByText('Scrivi la categoria')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('toccando un suggerimento il payload ha quella categoria', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Descrizione/), 'Telepass');
    await user.type(screen.getByLabelText(/Importo/), '50');
    await user.click(screen.getByLabelText(/Categoria/));
    await user.click(await screen.findByRole('option', { name: 'Eni A1' }));
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ category: 'Eni A1' });
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/7/values/expense_category');
  });
});

describe('ExpenseForm — focus sull\'errore', () => {
  it('inviando con categoria vuota il focus va sul campo categoria', async () => {
    const user = userEvent.setup();
    renderForm();

    await user.type(screen.getByLabelText(/Descrizione/), 'Telepass');
    await user.type(screen.getByLabelText(/Importo/), '50');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(screen.getByLabelText(/Categoria/)).toHaveFocus());
  });

  it('tasse: il campo è il tipo di tassa, con i suoi suggerimenti e il suo messaggio', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ section: 'tax' });

    expect(screen.queryByLabelText(/^Categoria/)).not.toBeInTheDocument();
    await user.type(screen.getByLabelText(/Descrizione/), 'Bollo 2026');
    await user.type(screen.getByLabelText(/Importo/), '200');
    await user.click(screen.getByRole('button', { name: 'Salva' }));
    expect(await screen.findByText('Scrivi il tipo di tassa')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/7/values/tax_type');
  });

  it('assicurazione: compagnia facoltativa, nessuna categoria', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ section: 'insurance' });

    expect(screen.getByLabelText(/Compagnia/)).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Descrizione/), 'RCA');
    await user.type(screen.getByLabelText(/Importo/), '400');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ section: 'insurance', category: '', insurer: null });
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/7/values/insurer');
  });

  it('assicurazione nuova: promemoria della prossima scadenza acceso, un anno dopo, data modificabile', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ section: 'insurance' });

    expect(screen.getByRole('switch', { name: /Ricordami la prossima scadenza/ })).toBeChecked();
    const date = screen.getByLabelText(/Data/);
    await user.clear(date);
    await user.type(date, '2026-03-10');
    expect(screen.getByLabelText(/Prossima scadenza/)).toHaveValue('2027-03-10');
    const next = screen.getByLabelText(/Prossima scadenza/);
    await user.clear(next);
    await user.type(next, '2027-02-28');
    await user.type(screen.getByLabelText(/Descrizione/), 'RCA');
    await user.type(screen.getByLabelText(/Importo/), '400');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ nextDueDate: '2027-02-28' });
  });

  it('tasse: il promemoria solo per bollo e superbollo (anche rinominati), mai modificando', async () => {
    mockedAuthFetch.mockResolvedValue([
      { id: 1, name: 'Bollo auto', starterKey: 'road_tax' },
      { id: 2, name: 'Passaggio di proprietà', starterKey: 'ownership_transfer' },
    ]);
    const user = userEvent.setup();
    const { unmount } = renderForm({ section: 'tax' });
    const type = screen.getByLabelText(/Tipo di tassa/);

    await user.type(type, 'Passaggio di proprietà');
    expect(screen.queryByRole('switch', { name: /Ricordami/ })).not.toBeInTheDocument();
    await user.clear(type);
    await user.type(type, 'bollo auto');
    expect(await screen.findByRole('switch', { name: /Ricordami/ })).toBeChecked();
    unmount();

    renderForm({ section: 'insurance', defaultValues: { id: 5, section: 'insurance', category: '', insurer: null, description: 'RCA', amount: '400.00' } });
    expect(screen.queryByRole('switch', { name: /Ricordami/ })).not.toBeInTheDocument();
  });

  it('spegnendo il promemoria non si manda nessuna scadenza', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ section: 'insurance' });

    await user.click(screen.getByRole('switch', { name: /Ricordami la prossima scadenza/ }));
    expect(screen.queryByLabelText(/Prossima scadenza/)).not.toBeInTheDocument();
    await user.type(screen.getByLabelText(/Descrizione/), 'RCA');
    await user.type(screen.getByLabelText(/Importo/), '400');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ nextDueDate: null });
  });
});
