import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { Toaster } from '@/components/ui';
import { dashboardKeys } from '@/hooks/useDashboardCharts';
import { reminderKeys } from '@/hooks/useReminders';
import { vehicleChartsKeys, vehicleKeys, vehicleStatsKey } from '@/hooks/useVehicles';
import type { MemberRole } from '@/hooks/useOrganizationMembers';
import { TransferVehicleDialog } from './TransferVehicleDialog';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const CANDIDATES = [
  { id: 10, firstName: 'Carlo', lastName: 'Alberti' },
  { id: 11, firstName: 'Anna', lastName: 'Bianchi' },
];

function mockApi({
  candidates = CANDIDATES as unknown,
  transfer = (): unknown => undefined,
}: { candidates?: unknown; transfer?: () => unknown } = {}) {
  mockedAuthFetch.mockImplementation(async (path: string, init?: RequestInit) => {
    if (init?.method === 'POST') return transfer();
    if (path === '/api/vehicles/7/transfer-candidates') return candidates;
    throw new Error(`unexpected fetch ${path}`);
  });
}

const transferCalls = () => mockedAuthFetch.mock.calls.filter(([, init]) => init?.method === 'POST');

function renderDialog(orgRole: MemberRole | undefined = 'admin') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const onOpenChange = vi.fn();
  const onAccessLost = vi.fn();
  render(
    <QueryClientProvider client={qc}>
      <TransferVehicleDialog
        open
        onOpenChange={onOpenChange}
        vehicleId={7}
        orgRole={orgRole}
        onAccessLost={onAccessLost}
      />
      <Toaster />
    </QueryClientProvider>,
  );
  return { qc, onOpenChange, onAccessLost };
}

async function pick(user: ReturnType<typeof userEvent.setup>, option: string) {
  await user.click(await screen.findByRole('combobox', { name: /Nuovo proprietario/ }));
  await user.click(await screen.findByRole('option', { name: option }));
}

describe('TransferVehicleDialog', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    // Radix Select in jsdom: mancano alcune API del puntatore
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => undefined;
    Element.prototype.releasePointerCapture = () => undefined;
    Element.prototype.scrollIntoView = () => undefined;
  });

  it('è un dialog accessibile con titolo e descrizione, avvisa sullo storico e non mostra email', async () => {
    mockApi();
    renderDialog();

    const dialog = await screen.findByRole('dialog', { name: 'Trasferire la proprietà?' });
    expect(dialog).toHaveAccessibleDescription(/nuovo proprietario del veicolo/);
    expect(await within(dialog).findByText(/Totali e grafici seguono il proprietario ATTUALE/)).toBeInTheDocument();
    expect(dialog).toHaveTextContent(/intero storico/);
    expect(dialog).toHaveTextContent(/promemoria già in scadenza/);
    expect(dialog).not.toHaveTextContent('@');
  });

  it('elenca i candidati per nome, keepAccess è selezionato di default e il submit parte disattivato', async () => {
    mockApi();
    const user = userEvent.setup();
    renderDialog();

    expect(await screen.findByRole('checkbox', { name: /Mantieni l'accesso in sola lettura/ })).toBeChecked();
    expect(screen.getByRole('button', { name: 'Trasferisci' })).toBeDisabled();

    await user.click(screen.getByRole('combobox', { name: /Nuovo proprietario/ }));
    const options = (await screen.findAllByRole('option')).map((o) => o.textContent);
    expect(options).toEqual(['Carlo Alberti', 'Anna Bianchi']);
  });

  it('invia {userId, keepAccess: true} di default, mostra il toast, chiude e invalida veicoli, dashboard e promemoria', async () => {
    mockApi();
    const user = userEvent.setup();
    const { qc, onOpenChange } = renderDialog('admin');
    const keys = {
      list: vehicleKeys.lists(),
      detail: vehicleKeys.detail(7),
      stats: vehicleStatsKey(7),
      charts: vehicleChartsKeys.window(7, 12),
      shares: ['vehicles', 7, 'shares'] as const,
      dashboard: dashboardKeys.charts(12),
      reminders: reminderKeys.overview(),
      remindersByVehicle: reminderKeys.byVehicle(7),
      members: ['organizations', 1, 'members'] as const,
    };
    for (const key of Object.values(keys)) qc.setQueryData(key, []);

    await pick(user, 'Carlo Alberti');
    await user.click(screen.getByRole('button', { name: 'Trasferisci' }));

    await waitFor(() => expect(transferCalls()).toHaveLength(1));
    expect(transferCalls()[0]).toEqual([
      '/api/vehicles/7/transfer',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ userId: 10, keepAccess: true }),
      },
    ]);
    expect((await screen.findAllByText('Proprietà trasferita')).length).toBeGreaterThan(0);
    expect(onOpenChange).toHaveBeenCalledWith(false);

    const invalidated = (key: readonly unknown[]) => qc.getQueryState(key)?.isInvalidated;
    for (const key of [keys.list, keys.detail, keys.stats, keys.charts, keys.shares, keys.dashboard, keys.reminders, keys.remindersByVehicle]) {
      expect(invalidated(key)).toBe(true);
    }
    expect(invalidated(keys.members)).toBe(false);
  });

  it('deselezionando la checkbox invia keepAccess: false', async () => {
    mockApi();
    const user = userEvent.setup();
    renderDialog('admin');

    await pick(user, 'Anna Bianchi');
    await user.click(screen.getByRole('checkbox', { name: /Mantieni l'accesso in sola lettura/ }));
    await user.click(screen.getByRole('button', { name: 'Trasferisci' }));

    await waitFor(() => expect(transferCalls()).toHaveLength(1));
    expect(transferCalls()[0]?.[1]?.body).toBe(JSON.stringify({ userId: 11, keepAccess: false }));
  });

  describe("notifica della perdita d'accesso", () => {
    async function submit(orgRole: MemberRole | undefined, keepAccess: boolean) {
      mockApi();
      const user = userEvent.setup();
      const { onAccessLost } = renderDialog(orgRole);
      await pick(user, 'Carlo Alberti');
      if (!keepAccess) await user.click(screen.getByRole('checkbox', { name: /Mantieni l'accesso/ }));
      await user.click(screen.getByRole('button', { name: 'Trasferisci' }));
      await waitFor(() => expect(transferCalls()).toHaveLength(1));
      await screen.findAllByText('Proprietà trasferita');
      return onAccessLost;
    }

    it('member senza accesso in sola lettura: avvisa la pagina', async () => {
      expect(await submit('member', false)).toHaveBeenCalledTimes(1);
    });

    it.each([
      ['member', true],
      ['admin', false],
      ['owner', false],
    ] as const)('ruolo %s con keepAccess %s: non avvisa', async (role, keepAccess) => {
      expect(await submit(role, keepAccess)).not.toHaveBeenCalled();
    });

    it('un errore non avvisa la pagina', async () => {
      mockApi({
        transfer: () => {
          throw new ApiError('transfer.same_owner', 409);
        },
      });
      const user = userEvent.setup();
      const { onAccessLost } = renderDialog('member');
      await pick(user, 'Carlo Alberti');
      await user.click(screen.getByRole('checkbox', { name: /Mantieni l'accesso/ }));
      await user.click(screen.getByRole('button', { name: 'Trasferisci' }));
      await screen.findByRole('alert');
      expect(onAccessLost).not.toHaveBeenCalled();
    });
  });

  it.each([
    ['transfer.same_owner', 409, 'Questo membro è già il proprietario del veicolo.'],
    ['transfer.ownership_changed', 409, /La proprietà del veicolo è cambiata nel frattempo/],
    ['transfer.recipient_invalid', 422, 'Seleziona un membro valido dell\'organizzazione.'],
    ['vehicle.not_found', 404, /./],
  ])('errore %s: messaggio tradotto nel dialog, che resta aperto senza toast di successo', async (title, status, message) => {
    mockApi({
      transfer: () => {
        throw new ApiError(title, status);
      },
    });
    const user = userEvent.setup();
    const { onOpenChange } = renderDialog();
    // Lo store dei toast è globale e perde tra i test: si confronta il numero, non l'assenza.
    const successToasts = screen.queryAllByText('Proprietà trasferita').length;

    await pick(user, 'Carlo Alberti');
    await user.click(screen.getByRole('button', { name: 'Trasferisci' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(message);
    expect(alert).not.toHaveTextContent(title);
    expect(onOpenChange).not.toHaveBeenCalled();
    expect(screen.queryAllByText('Proprietà trasferita')).toHaveLength(successToasts);
    // Riprovabile: il submit torna attivo.
    expect(screen.getByRole('button', { name: 'Trasferisci' })).toBeEnabled();
  });

  it('un 422 di validazione sul campo destinatario appare sotto il select', async () => {
    mockApi({
      transfer: () => {
        throw new ApiError('validation_failed', 422, undefined, [{ field: 'userId', message: 'common.positive' }]);
      },
    });
    const user = userEvent.setup();
    renderDialog();

    await pick(user, 'Carlo Alberti');
    await user.click(screen.getByRole('button', { name: 'Trasferisci' }));

    await waitFor(() => expect(transferCalls()).toHaveLength(1));
    // L'errore di campo sta sotto il select e non c'è un secondo alert generico.
    expect(await screen.findAllByRole('alert')).toHaveLength(1);
  });

  it('senza candidati mostra il testo informativo, senza select né pulsante di invio', async () => {
    mockApi({ candidates: [] });
    renderDialog();

    expect(await screen.findByText(/Nessun altro membro dell'organizzazione a cui cedere il veicolo/)).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Trasferisci' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Annulla' })).toBeInTheDocument();
  });

  it("se i candidati non si caricano mostra l'errore tradotto", async () => {
    mockedAuthFetch.mockRejectedValue(new ApiError('vehicle.not_found', 404));
    renderDialog();

    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('i testi esistono anche in inglese', async () => {
    await i18n.changeLanguage('en');
    mockApi();
    renderDialog();

    expect(await screen.findByRole('dialog', { name: 'Transfer ownership?' })).toBeInTheDocument();
    expect(await screen.findByText(/Totals and charts follow the CURRENT owner/)).toBeInTheDocument();
  });
});
