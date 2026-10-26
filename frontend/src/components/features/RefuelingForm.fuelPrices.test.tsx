import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render as rtlRender, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { todayIso } from '@/lib/format';
import { RefuelingForm } from './RefuelingForm';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const render: typeof rtlRender = (ui, options) =>
  rtlRender(ui, {
    wrapper: ({ children }) => (
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        {children}
      </QueryClientProvider>
    ),
    ...options,
  });

const station = (id: number, label: string, self: string | null, served: string | null, distanceKm: number | null = null) => ({
  id,
  label,
  brand: 'Q8',
  address: 'Via Emilia Est 1420',
  city: 'Modena',
  province: 'MO',
  distanceKm,
  prices: {
    self: self ? { price: self, communicatedAt: '2026-10-24' } : null,
    served: served ? { price: served, communicatedAt: '2026-10-24' } : null,
  },
});

let enabled = true;

beforeEach(async () => {
  await i18n.changeLanguage('it');
  enabled = true;
  mockedAuthFetch.mockImplementation(async (path) => {
    if (path === '/api/vehicles/7') {
      return { id: 7, name: 'Golf', brand: 'VW', model: 'Golf', year: 2019, type: 'car', initialKm: 0, notes: null, licensePlate: null, vin: null, fuelType: 'diesel', secondaryFuelType: null };
    }
    if (path === '/api/fuel-prices/status') return { enabled, extractedOn: '2026-10-25' };
    if (path === '/api/vehicles/7/values/station') {
      return [
        { id: 1, name: 'Q8 sotto casa', fuelStationId: 59183 },
        { id: 2, name: 'Eni A1', fuelStationId: null },
      ];
    }
    if (path === '/api/fuel-prices/stations/59183?fuel=diesel') return station(59183, 'Q8 · Via Emilia Est 1420, Modena', '1.729', '1.899');
    if (String(path).startsWith('/api/fuel-prices/nearby')) {
      return [station(59183, 'Q8 · Via Emilia Est 1420, Modena', '1.729', '1.899', 0.4), station(600, 'Eni · Via Giardini 300, Modena', '1.759', null, 2.1)];
    }
    return [];
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

function renderForm(props: Partial<React.ComponentProps<typeof RefuelingForm>> = {}) {
  const onSubmit = vi.fn();
  render(<RefuelingForm vehicleId={7} defaultFuelType="diesel" onSubmit={onSubmit} {...props} />);
  return { onSubmit };
}

async function fillRequired(user: ReturnType<typeof userEvent.setup>) {
  await user.clear(screen.getByLabelText(/Km/));
  await user.type(screen.getByLabelText(/Km/), '45000');
  await user.type(screen.getByLabelText(/Litri/), '40');
}

describe('RefuelingForm — prezzi del carburante', () => {
  it('dal distributore collegato dell\'elenco propone il prezzo di oggi', async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.type(screen.getByLabelText(/Distributore/), 'Q8 sotto casa');

    await waitFor(() => expect(screen.getByLabelText(/€\/L/)).toHaveValue('1.729'));
    expect(screen.getByText(/Prezzo del distributore, comunicato il 24 ott 2026/)).toBeInTheDocument();

    // Servito: il prezzo proposto cambia finché l'utente non l'ha toccato
    await user.click(screen.getByRole('button', { name: 'Servito' }));
    await waitFor(() => expect(screen.getByLabelText(/€\/L/)).toHaveValue('1.899'));

    await fillRequired(user);
    await user.click(screen.getByRole('button', { name: 'Salva' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ pricePerLiter: '1.899', station: 'Q8 sotto casa', fuelStationId: null });
  });

  it('non sovrascrive un prezzo scritto dall\'utente', async () => {
    const user = userEvent.setup();
    renderForm();

    await user.type(screen.getByLabelText(/€\/L/), '1,7');
    await user.type(screen.getByLabelText(/Distributore/), 'Q8 sotto casa');

    expect(await screen.findByText(/Prezzo del distributore/)).toBeInTheDocument();
    expect(screen.getByLabelText(/€\/L/)).toHaveValue('1,7');
  });

  it('per un rifornimento di giorni fa non propone il prezzo di oggi', async () => {
    const user = userEvent.setup();
    renderForm({ defaultValues: { refueledAt: '2026-01-10' } });

    await user.type(screen.getByLabelText(/Distributore/), 'Q8 sotto casa');

    await screen.findByRole('button', { name: /Distributori vicini/ });
    expect(screen.queryByText(/Prezzo del distributore/)).not.toBeInTheDocument();
    expect(screen.getByLabelText(/€\/L/)).toHaveValue('');
    expect(mockedAuthFetch).not.toHaveBeenCalledWith('/api/fuel-prices/stations/59183?fuel=diesel');
  });

  it('dai distributori vicini compila distributore e prezzo e lo collega', async () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      geolocation: {
        getCurrentPosition: (ok: PositionCallback) =>
          ok({ coords: { latitude: 44.64712345, longitude: 10.92521 } } as GeolocationPosition),
      },
    });
    const user = userEvent.setup();
    const { onSubmit } = renderForm();

    await user.click(await screen.findByRole('button', { name: /Distributori vicini/ }));
    await user.click(await screen.findByRole('button', { name: /Q8 · Via Emilia Est 1420, Modena/ }));

    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/fuel-prices/nearby?lat=44.6471&lng=10.9252&fuel=diesel');
    expect(screen.getByLabelText(/Distributore/)).toHaveValue('Q8 · Via Emilia Est 1420, Modena');
    await waitFor(() => expect(screen.getByLabelText(/€\/L/)).toHaveValue('1.729'));

    await fillRequired(user);
    await user.click(screen.getByRole('button', { name: 'Salva' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ station: 'Q8 · Via Emilia Est 1420, Modena', fuelStationId: 59183, refueledAt: todayIso() });
  });

  it('senza posizione propone la ricerca per nome', async () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      geolocation: {
        getCurrentPosition: (_ok: PositionCallback, fail: PositionErrorCallback) =>
          fail({ code: 1, PERMISSION_DENIED: 1, TIMEOUT: 3 } as GeolocationPositionError),
      },
    });
    const user = userEvent.setup();
    renderForm();

    await user.click(await screen.findByRole('button', { name: /Distributori vicini/ }));

    expect(await screen.findByText(/Posizione non concessa/)).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: /Cerca per nome/ })).toBeInTheDocument();
  });

  it('con i prezzi spenti sull\'istanza non mostra niente', async () => {
    enabled = false;
    const user = userEvent.setup();
    renderForm();

    await user.type(screen.getByLabelText(/Distributore/), 'Q8 sotto casa');

    await waitFor(() => expect(mockedAuthFetch).toHaveBeenCalledWith('/api/fuel-prices/status'));
    expect(screen.queryByRole('button', { name: /Distributori vicini/ })).not.toBeInTheDocument();
    expect(screen.queryByText(/Prezzo del distributore/)).not.toBeInTheDocument();
  });
});
