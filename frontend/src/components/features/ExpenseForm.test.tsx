import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import i18n from '@/i18n';
import { ApiError } from '@/api/client';
import type { Expense } from '@/api/types/expense';
import { ExpenseForm } from './ExpenseForm';

function renderForm(props: Partial<React.ComponentProps<typeof ExpenseForm>> = {}) {
  const onSubmit = vi.fn();
  const utils = render(<ExpenseForm vehicleId={7} onSubmit={onSubmit} {...props} />);
  return { onSubmit, ...utils };
}

/** Spesa mensile già compilata, così i test non devono passare dal Select Radix. */
const recurringDefaults: Partial<Expense> = {
  occurredAt: '2026-01-15',
  category: 'subscription',
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
    expect(screen.getByText(/Lascia vuoto se la spesa continua finché non la disdici/)).toBeInTheDocument();

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
