import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render as rtlRender, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { MaintenanceForm } from './MaintenanceForm';

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
  mockedAuthFetch.mockImplementation(async (path) =>
    path.startsWith('/api/components')
      ? [{ id: 30, vehicle: { id: 7 }, name: 'Filtro olio Mann', code: null, shop: null, purchasedAt: null, price: null }]
      : [
          { id: 1, name: 'Q8 Via Roma' },
          { id: 2, name: 'Eni A1' },
        ],
  );
});

function renderForm(props: Partial<React.ComponentProps<typeof MaintenanceForm>> = {}) {
  const onSubmit = vi.fn();
  const utils = render(<MaintenanceForm vehicleId={7} category="scheduled" onSubmit={onSubmit} {...props} />);
  return { onSubmit, ...utils };
}

describe('MaintenanceForm', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('precompila il km con defaultKm invece che con 0', () => {
    renderForm({ defaultKm: 45230 });

    expect(screen.getByLabelText(/Km/)).toHaveValue(45230);
  });

  it('aggiorna il km precompilato quando defaultKm arriva dopo il mount (query async)', async () => {
    const onSubmit = vi.fn();
    const { rerender } = render(<MaintenanceForm vehicleId={7} category="scheduled" onSubmit={onSubmit} />);

    expect(screen.getByLabelText(/Km/)).toHaveValue(0);

    rerender(<MaintenanceForm vehicleId={7} category="scheduled" onSubmit={onSubmit} defaultKm={45230} />);

    await waitFor(() => expect(screen.getByLabelText(/Km/)).toHaveValue(45230));
  });

  it("non sovrascrive il km se l'utente lo ha già modificato prima che defaultKm arrivi", async () => {
    const onSubmit = vi.fn();
    const user = userEvent.setup();
    const { rerender } = render(<MaintenanceForm vehicleId={7} category="scheduled" onSubmit={onSubmit} />);

    await user.clear(screen.getByLabelText(/Km/));
    await user.type(screen.getByLabelText(/Km/), '12000');

    rerender(<MaintenanceForm vehicleId={7} category="scheduled" onSubmit={onSubmit} defaultKm={45230} />);

    expect(screen.getByLabelText(/Km/)).toHaveValue(12000);
  });

  it('in modifica, il km della manutenzione esistente ha priorità su defaultKm', () => {
    renderForm({ defaultKm: 45230, defaultValues: { km: 30000 } });

    expect(screen.getByLabelText(/Km/)).toHaveValue(30000);
  });
});

describe('MaintenanceForm — suggerimenti dell\'officina', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('toccando un suggerimento il payload ha quell\'officina', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Descrizione/), 'Tagliando');
    await user.click(screen.getByLabelText(/Officina/));
    await user.click(await screen.findByRole('option', { name: 'Eni A1' }));
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ workshop: 'Eni A1' });
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/7/values/workshop');
  });

  it('officina lasciata vuota → null', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Descrizione/), 'Tagliando');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ workshop: null, shop: null });
  });

  it('il negozio ha i suoi suggerimenti e vale anche senza officina (lavoro fatto in proprio)', async () => {
    mockedAuthFetch.mockImplementation(async (path) =>
      path.startsWith('/api/components') ? [] : path.endsWith('/values/shop') ? [{ id: 5, name: 'Ricambi Bianchi' }] : [{ id: 2, name: 'Eni A1' }],
    );
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Descrizione/), 'Pastiglie montate da me');
    await user.click(screen.getByLabelText(/Negozio/));
    expect(screen.queryByRole('option', { name: 'Eni A1' })).not.toBeInTheDocument();
    await user.click(await screen.findByRole('option', { name: 'Ricambi Bianchi' }));
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ workshop: null, shop: 'Ricambi Bianchi' });
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/7/values/shop');
  });

  it('la sezione decide la categoria: niente selettore, e la revisione non ha il tipo', async () => {
    const user = userEvent.setup();
    const { onSubmit, unmount } = renderForm({ category: 'unscheduled' });
    expect(screen.queryByLabelText(/^Categoria/)).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Tipo intervento/)).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Descrizione/), 'Foratura');
    await user.click(screen.getByRole('button', { name: 'Salva' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ category: 'unscheduled', type: 'oil_change' });
    unmount();

    const inspection = renderForm({ category: 'inspection' });
    expect(screen.queryByLabelText(/Tipo intervento/)).not.toBeInTheDocument();
    await user.type(screen.getByLabelText(/Descrizione/), 'Revisione biennale');
    await user.click(screen.getByRole('button', { name: 'Salva' }));
    await waitFor(() => expect(inspection.onSubmit).toHaveBeenCalledTimes(1));
    expect(inspection.onSubmit.mock.calls[0]?.[0]).toMatchObject({ category: 'inspection', type: 'inspection' });
  });

  it('i componenti: uno dai suggerimenti, uno nuovo scritto, e i nuovi vengono segnalati', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Descrizione/), 'Tagliando');
    const input = screen.getByLabelText(/Componenti/);
    await user.click(input);
    await user.click(await screen.findByRole('option', { name: 'Filtro olio Mann' }));
    await user.type(input, 'Olio 5W30{Enter}');
    expect(screen.getByRole('button', { name: 'Togli Olio 5W30' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ components: ['Filtro olio Mann', 'Olio 5W30'] });
    expect(onSubmit.mock.calls[0]?.[1]).toEqual({ newComponents: ['Olio 5W30'] });
  });

  it('revisione nuova: promemoria acceso, due anni dopo la data della revisione; modificando niente promemoria', async () => {
    const user = userEvent.setup();
    const { onSubmit, unmount } = renderForm({ category: 'inspection' });

    expect(screen.getByRole('switch', { name: /Ricordami la prossima scadenza/ })).toBeChecked();
    const date = screen.getByLabelText(/^Data/);
    await user.clear(date);
    await user.type(date, '2026-05-31');
    expect(screen.getByLabelText(/Prossima scadenza/)).toHaveValue('2028-05-31');
    await user.type(screen.getByLabelText(/Descrizione/), 'Revisione');
    await user.click(screen.getByRole('button', { name: 'Salva' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ category: 'inspection', nextDueDate: '2028-05-31' });
    unmount();

    renderForm({ category: 'scheduled' });
    expect(screen.queryByRole('switch', { name: /Ricordami/ })).not.toBeInTheDocument();
  });
});
