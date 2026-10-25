import { describe, it, expect, vi, beforeEach } from 'vitest';
import { cleanup, render as rtlRender, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { RefuelingForm } from './RefuelingForm';

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

/** Il veicolo 7 di ogni test: per default benzina e GPL. */
let vehicleFuels: { fuelType: string; secondaryFuelType: string | null } = { fuelType: 'gasoline', secondaryFuelType: 'lpg' };

beforeEach(() => {
  vehicleFuels = { fuelType: 'gasoline', secondaryFuelType: 'lpg' };
  mockedAuthFetch.mockImplementation(async (path) =>
    path === '/api/vehicles/7'
      ? { id: 7, name: 'Panda', brand: 'Fiat', model: 'Panda', year: 2019, type: 'car', initialKm: 0, notes: null, licensePlate: null, vin: null, ...vehicleFuels }
      : [
          { id: 1, name: 'Q8 Via Roma' },
          { id: 2, name: 'Eni A1' },
        ],
  );
});

function renderForm(props: Partial<React.ComponentProps<typeof RefuelingForm>> = {}) {
  const onSubmit = vi.fn();
  const utils = render(<RefuelingForm vehicleId={7} onSubmit={onSubmit} {...props} />);
  return { onSubmit, ...utils };
}

describe('RefuelingForm', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('accetta la virgola decimale della tastiera italiana e invia col punto', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.clear(screen.getByLabelText(/Km/));
    await user.type(screen.getByLabelText(/Km/), '45000');
    await user.type(screen.getByLabelText(/Litri/), '42,5');
    await user.type(screen.getByLabelText(/€\/L/), '1,859');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ liters: '42.5', pricePerLiter: '1.859', km: 45000 });
  });

  it('il totale in anteprima si calcola anche con la virgola', async () => {
    const user = userEvent.setup();
    renderForm();

    await user.type(screen.getByLabelText(/Litri/), '40,0');
    await user.type(screen.getByLabelText(/€\/L/), '1,5');

    expect(await screen.findByText(/60,00/)).toBeInTheDocument();
  });

  it('ha il campo Note e lo invia (vuoto → null)', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Litri/), '30');
    await user.type(screen.getByLabelText(/€\/L/), '1,8');
    await user.type(screen.getByLabelText(/Note/), 'Distributore in autostrada');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ notes: 'Distributore in autostrada' });
  });

  it('testo non numerico: errore sul campo, nessun invio', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Litri/), 'abc');
    await user.type(screen.getByLabelText(/€\/L/), '1,8');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    expect(await screen.findByText(/numero/i)).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('gli errori di validazione del backend compaiono sul campo giusto', async () => {
    const error = new ApiError('validation_failed', 422, undefined, [
      { field: 'station', message: 'Nome distributore troppo lungo' },
    ]);
    renderForm({ error });

    expect(await screen.findByText('Nome distributore troppo lungo')).toBeInTheDocument();
  });

  it('precompila il km con defaultKm invece che con 0', () => {
    renderForm({ defaultKm: 45230 });

    expect(screen.getByLabelText(/Km/)).toHaveValue(45230);
  });

  it('aggiorna il km precompilato quando defaultKm arriva dopo il mount (query async)', async () => {
    const onSubmit = vi.fn();
    const { rerender } = render(
      <RefuelingForm vehicleId={7} onSubmit={onSubmit} />,
    );

    expect(screen.getByLabelText(/Km/)).toHaveValue(0);

    rerender(<RefuelingForm vehicleId={7} onSubmit={onSubmit} defaultKm={45230} />);

    await waitFor(() => expect(screen.getByLabelText(/Km/)).toHaveValue(45230));
  });

  it('non sovrascrive il km se l\'utente lo ha già modificato prima che defaultKm arrivi', async () => {
    const onSubmit = vi.fn();
    const user = userEvent.setup();
    const { rerender } = render(
      <RefuelingForm vehicleId={7} onSubmit={onSubmit} />,
    );

    await user.clear(screen.getByLabelText(/Km/));
    await user.type(screen.getByLabelText(/Km/), '12000');

    rerender(<RefuelingForm vehicleId={7} onSubmit={onSubmit} defaultKm={45230} />);

    expect(screen.getByLabelText(/Km/)).toHaveValue(12000);
  });

  it('in modifica, il km del rifornimento esistente ha priorità su defaultKm', () => {
    renderForm({ defaultKm: 45230, defaultValues: { km: 30000 } });

    expect(screen.getByLabelText(/Km/)).toHaveValue(30000);
  });
});

describe('RefuelingForm — suggerimenti del distributore', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('toccando un suggerimento il payload ha quel distributore', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Litri/), '30');
    await user.type(screen.getByLabelText(/€\/L/), '1,8');
    await user.click(screen.getByLabelText(/Distributore/));
    await user.click(await screen.findByRole('option', { name: 'Q8 Via Roma' }));
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ station: 'Q8 Via Roma' });
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/7/values/station');
  });

  it('distributore lasciato vuoto → null', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Litri/), '30');
    await user.type(screen.getByLabelText(/€\/L/), '1,8');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ station: null });
  });

  it('carburante: solo quelli del veicolo; con uno solo è già impostato e non si sceglie', async () => {
    // Radix Select in jsdom: mancano alcune API del puntatore
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => undefined;
    Element.prototype.releasePointerCapture = () => undefined;
    Element.prototype.scrollIntoView = () => undefined;
    const user = userEvent.setup();
    renderForm();
    await user.click(await screen.findByRole('combobox', { name: /Carburante/ }));
    const options = (await screen.findAllByRole('option')).map((o) => o.textContent);
    expect(options).toEqual(['Benzina', 'GPL']);
    cleanup();

    vehicleFuels = { fuelType: 'diesel', secondaryFuelType: null };
    const { onSubmit } = renderForm(); // proposto 'gasoline' (default): il veicolo è diesel
    const field = await screen.findByDisplayValue('Diesel');
    expect(field).toHaveAttribute('readonly');
    await user.type(screen.getByLabelText(/Litri/), '40');
    await user.type(screen.getByLabelText(/€\/L/), '1,8');
    await user.click(screen.getByRole('button', { name: 'Salva' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ fuelType: 'diesel' });
  });
});
