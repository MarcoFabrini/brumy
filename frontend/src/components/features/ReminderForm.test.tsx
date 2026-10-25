import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import i18n from '@/i18n';
import { ApiError } from '@/api/client';
import type { Reminder } from '@/api/types/reminder';
import { ReminderForm } from './ReminderForm';

function renderForm(props: Partial<React.ComponentProps<typeof ReminderForm>> = {}) {
  const onSubmit = vi.fn();
  const utils = render(<ReminderForm vehicleId={7} onSubmit={onSubmit} {...props} />);
  return { onSubmit, ...utils };
}

/** Tagliando già compilato (tipo incluso), così i test non devono passare dal Select del tipo. */
const service: Partial<Reminder> = {
  type: 'service',
  description: 'Tagliando',
  dueDate: '2026-12-31',
  dueKm: 10_000,
};

async function chooseUnit(user: ReturnType<typeof userEvent.setup>, unit: 'Mesi' | 'Anni') {
  await user.click(screen.getByRole('combobox', { name: 'Unità' }));
  await user.click(await screen.findByRole('option', { name: unit }));
}

describe('ReminderForm — ricorrenza e anticipo a km', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
    // Radix Select in jsdom: mancano alcune API del puntatore
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => undefined;
    Element.prototype.releasePointerCapture = () => undefined;
    Element.prototype.scrollIntoView = () => undefined;
  });

  it('compilato da zero invia ricorrenza in mesi e anticipo a km', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.click(screen.getByRole('combobox', { name: /Tipo/ }));
    await user.click(await screen.findByRole('option', { name: 'Tagliando' }));
    await user.type(screen.getByLabelText(/Descrizione/), 'Tagliando completo');
    await user.type(screen.getByLabelText(/Scadenza \(data\)/), '2026-12-31');
    await user.type(screen.getByLabelText(/Scadenza \(km\)/), '10000');
    await user.type(screen.getByLabelText('Ripeti ogni'), '2');
    await chooseUnit(user, 'Anni');
    await user.type(screen.getByLabelText('Ripeti ogni (km)'), '10000');
    const notifyKm = screen.getByLabelText(/Avvisa km prima/);
    await user.clear(notifyKm);
    await user.type(notifyKm, '500');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toEqual({
      vehicleId: 7,
      type: 'service',
      description: 'Tagliando completo',
      dueDate: '2026-12-31',
      dueKm: 10_000,
      notifyDaysBefore: 30,
      notifyKmBefore: 500,
      recurrenceMonths: 24,
      recurrenceKm: 10_000,
      notes: null,
    });
  });

  it('in anni accetta solo numeri interi: 1,5 non diventa 18 mesi', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: service });

    await chooseUnit(user, 'Anni');
    fireEvent.change(screen.getByLabelText('Ripeti ogni'), { target: { value: '1.5' } });

    expect(screen.getByRole('combobox', { name: 'Unità' })).toHaveTextContent('Anni');
    await user.click(screen.getByRole('button', { name: 'Salva' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ recurrenceMonths: 12 });
  });

  it('il suggerimento sotto "Ripeti ogni" occupa tutta la riga e descrive il campo', () => {
    renderForm({ defaultValues: service });

    const hint = screen.getByText(/2 anni per la revisione/);
    expect(hint).toHaveClass('col-span-2');
    expect(screen.getByLabelText('Ripeti ogni')).toHaveAttribute('aria-describedby', hint.id);
  });

  it('in mesi il numero resta quello scritto', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: service });

    await user.type(screen.getByLabelText('Ripeti ogni'), '6');
    await chooseUnit(user, 'Mesi');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ recurrenceMonths: 6 });
  });

  it('campi di ricorrenza vuoti → una tantum (null), anticipo a km di default 1000', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: service });

    expect(screen.getByLabelText(/Avvisa km prima/)).toHaveValue(1000);
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ recurrenceMonths: null, recurrenceKm: null, notifyKmBefore: 1000 });
  });

  it('cancellare il numero toglie la ricorrenza a tempo', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: { ...service, recurrenceMonths: 24 } });

    await user.clear(screen.getByLabelText('Ripeti ogni'));
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ recurrenceMonths: null });
  });

  it('in modifica mostra la ricorrenza nell\'unità naturale (24 → 2 anni, 18 → 18 mesi)', () => {
    const { unmount } = renderForm({ defaultValues: { ...service, recurrenceMonths: 24 } });
    expect(screen.getByLabelText('Ripeti ogni')).toHaveValue(2);
    expect(screen.getByRole('combobox', { name: 'Unità' })).toHaveTextContent('Anni');
    unmount();

    renderForm({ defaultValues: { ...service, recurrenceMonths: 18 } });
    expect(screen.getByLabelText('Ripeti ogni')).toHaveValue(18);
    expect(screen.getByRole('combobox', { name: 'Unità' })).toHaveTextContent('Mesi');
  });

  it('cambiare unità mantiene il numero scritto: 2 mesi → 2 anni', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: { ...service, recurrenceMonths: 2 } });

    await chooseUnit(user, 'Anni');
    expect(screen.getByLabelText('Ripeti ogni')).toHaveValue(2);
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ recurrenceMonths: 24 });
  });

  it('oltre 10 anni è un errore sotto il campo e non invia', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: service });

    await user.type(screen.getByLabelText('Ripeti ogni'), '11');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    expect(await screen.findByText('Al massimo 10 anni (120 mesi)')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('ricorrenza a tempo senza data di scadenza: errore sul campo', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm({ defaultValues: { ...service, dueDate: null } });

    await user.type(screen.getByLabelText('Ripeti ogni'), '1');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    expect(await screen.findByText('Per ripetere a tempo serve una scadenza a data')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('un 422 del backend su recurrenceMonths compare sotto il campo, senza errore generico', async () => {
    renderForm({
      defaultValues: { ...service, recurrenceMonths: 12 },
      error: new ApiError('validation_failed', 422, undefined, [
        { field: 'recurrenceMonths', message: 'reminder.recurrence_months_requires_due_date' },
      ]),
    });

    const message = await screen.findByText('Per ripetere a tempo serve una scadenza a data');
    expect(screen.getByLabelText('Ripeti ogni')).toHaveAttribute('aria-describedby', message.id);
    expect(screen.queryByText(/validation_failed|Errore inatteso/)).not.toBeInTheDocument();
  });
});
