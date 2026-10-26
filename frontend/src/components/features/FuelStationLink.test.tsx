import type { ReactElement } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { FuelStationLink } from './FuelStationLink';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

vi.mock('recharts', async (importOriginal) => {
  const { cloneElement } = await import('react');
  return {
    ...(await importOriginal<typeof import('recharts')>()),
    ResponsiveContainer: ({ children }: { children: ReactElement<{ width?: number; height?: number }> }) =>
      cloneElement(children, { width: 400, height: 240 }),
  };
});

const q8 = {
  id: 59183,
  label: 'Q8 · Via Emilia Est 1420, Modena',
  brand: 'Q8',
  address: 'Via Emilia Est 1420',
  city: 'Modena',
  province: 'MO',
  distanceKm: null,
  prices: { self: null, served: null },
};

function renderLink(fuelStationId: number | null, followsPrice = false) {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <FuelStationLink value={{ id: 5, name: 'Q8 sotto casa', usageCount: 3, fuelStationId, followsPrice }} />
    </QueryClientProvider>,
  );
}

beforeEach(async () => {
  await i18n.changeLanguage('it');
  // Niente posizione: il foglio passa subito alla ricerca
  vi.stubGlobal('navigator', { ...navigator, geolocation: undefined });
  mockedAuthFetch.mockImplementation(async (path, init) => {
    if (path === '/api/fuel-prices/status') return { enabled: true, extractedOn: '2026-10-25' };
    if (path === '/api/fuel-prices/stations/59183') return q8;
    if (String(path).startsWith('/api/fuel-prices/search')) return [q8];
    if (path === '/api/values/5/fuel-station') {
      const { fuelStationId } = JSON.parse(String(init?.body)) as { fuelStationId: number | null };
      return { id: 5, name: 'Q8 sotto casa', usageCount: 3, fuelStationId, followsPrice: false };
    }
    if (path === '/api/values/5/follow-price') {
      const { follow } = JSON.parse(String(init?.body)) as { follow: boolean };
      return { id: 5, name: 'Q8 sotto casa', usageCount: 3, fuelStationId: 59183, followsPrice: follow };
    }
    if (String(path).startsWith('/api/fuel-prices/followed')) {
      return [
        { valueId: 5, name: 'Q8 sotto casa', fuelStationId: 59183, self: [{ day: '2026-10-25', price: '1.729' }], served: [] },
        { valueId: 8, name: 'Eni lavoro', fuelStationId: 1234, self: [{ day: '2026-10-25', price: '1.699' }], served: [] },
      ];
    }
    return [];
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('FuelStationLink', () => {
  it('collega la voce a un distributore cercato per nome', async () => {
    const user = userEvent.setup();
    renderLink(null);

    expect(await screen.findByText(/Non collegato/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Collega a un distributore' }));
    await user.type(await screen.findByRole('searchbox'), 'q8 modena');
    await user.click(await screen.findByRole('button', { name: /Q8 · Via Emilia Est 1420/ }));

    await waitFor(() =>
      expect(mockedAuthFetch).toHaveBeenCalledWith('/api/values/5/fuel-station', expect.objectContaining({ method: 'PATCH', body: '{"fuelStationId":59183}' })),
    );
  });

  it('mostra il distributore collegato e lo scollega', async () => {
    const user = userEvent.setup();
    renderLink(59183);

    expect(await screen.findByText('Q8 · Via Emilia Est 1420, Modena')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Scollega' }));

    await waitFor(() =>
      expect(mockedAuthFetch).toHaveBeenCalledWith('/api/values/5/fuel-station', expect.objectContaining({ body: '{"fuelStationId":null}' })),
    );
  });

  it('senza collegamento non si può seguire il prezzo', async () => {
    renderLink(null);

    expect(await screen.findByText(/Non collegato/)).toBeInTheDocument();
    expect(screen.queryByRole('switch', { name: 'Segui il prezzo' })).not.toBeInTheDocument();
  });

  it('collegata, segue il prezzo con l\'interruttore', async () => {
    const user = userEvent.setup();
    renderLink(59183);

    const toggle = await screen.findByRole('switch', { name: 'Segui il prezzo' });
    expect(toggle).toHaveAttribute('aria-checked', 'false');
    await user.click(toggle);

    await waitFor(() =>
      expect(mockedAuthFetch).toHaveBeenCalledWith('/api/values/5/follow-price', expect.objectContaining({ method: 'PATCH', body: '{"follow":true}' })),
    );
  });

  it('seguita, mostra l\'andamento del suo prezzo soltanto', async () => {
    renderLink(59183, true);

    expect(await screen.findByRole('switch', { name: 'Segui il prezzo' })).toHaveAttribute('aria-checked', 'true');
    const table = await screen.findByRole('table', { name: 'Andamento del prezzo' });
    expect(table).toHaveTextContent('Q8 sotto casa');
    expect(table).not.toHaveTextContent('Eni lavoro');
    // Nessun veicolo: il carburante proposto è la benzina
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/fuel-prices/followed?fuel=gasoline');
  });

  it('con i prezzi spenti non mostra niente', async () => {
    mockedAuthFetch.mockImplementation(async (path) => (path === '/api/fuel-prices/status' ? { enabled: false, extractedOn: null } : []));
    const { container } = render(
      <QueryClientProvider client={new QueryClient()}>
        <FuelStationLink value={{ id: 5, name: 'Q8', usageCount: 0, fuelStationId: null, followsPrice: false }} />
      </QueryClientProvider>,
    );
    await waitFor(() => expect(mockedAuthFetch).toHaveBeenCalledWith('/api/fuel-prices/status'));
    expect(container).toBeEmptyDOMElement();
  });
});
