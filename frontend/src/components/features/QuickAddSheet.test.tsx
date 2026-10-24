import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { QuickAddSheet } from './QuickAddSheet';
import { useQuickAddStore } from '@/stores/useQuickAddStore';
import type { Vehicle } from '@/api/types/vehicle';

// Lo store dell'ultimo veicolo usato è persistito: nell'ambiente di test `localStorage` non esiste.
vi.hoisted(() => {
  const memory = new Map<string, string>();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (k: string) => memory.get(k) ?? null,
      setItem: (k: string, v: string) => void memory.set(k, v),
      removeItem: (k: string) => void memory.delete(k),
    },
  });
});

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const vehicle = (id: number, name: string, ownership: Vehicle['ownership'] = 'owned'): Vehicle => ({
  id,
  name,
  brand: 'Fiat',
  model: 'Panda',
  year: 2020,
  licensePlate: null,
  vin: null,
  type: 'car',
  fuelType: 'gasoline',
  secondaryFuelType: null,
  initialKm: 0,
  notes: null,
  ownership,
  permissions: {
    canEdit: ownership !== 'shared',
    canDelete: ownership !== 'shared',
    canShare: ownership !== 'shared',
  },
});

function Where() {
  const l = useLocation();
  return <div data-testid="where">{l.pathname + l.search}</div>;
}

const vehicles = [vehicle(4, 'Panda'), vehicle(5, 'Ducati'), vehicle(6, 'Della moglie', 'shared')];

/** Risposte del backend: lista veicoli, dettaglio (per il controllo "modificabile"), stats e ultimi rifornimenti. */
function mockApi(list: Vehicle[] = vehicles) {
  mockedAuthFetch.mockImplementation(async (path: string, init?: RequestInit) => {
    if (path === '/api/vehicles') return list;
    const detail = /^\/api\/vehicles\/(\d+)$/.exec(path);
    if (detail) return list.find((v) => v.id === Number(detail[1]));
    if (/^\/api\/vehicles\/\d+\/stats$/.test(path)) return { currentKm: 51_200 };
    if (path.startsWith('/api/refuelings?vehicleId=')) {
      return [{ id: 1, vehicle: { id: 4 }, refueledAt: '2026-10-01', km: 51_000, liters: '30', pricePerLiter: '1.849', totalCost: '55.47', fuelType: 'gasoline', fullTank: true, station: 'Q8 Via Roma', notes: null }];
    }
    if (path === '/api/refuelings' && init?.method === 'POST') {
      return { id: 99, vehicle: { id: 5 }, refueledAt: '2026-10-22', km: 51_200, liters: '32.5', pricePerLiter: '1.849', totalCost: '60.09', fuelType: 'gasoline', fullTank: true, station: 'Q8 Via Roma', notes: null };
    }
    throw new Error(`unexpected fetch ${path}`);
  });
}

function renderSheet(initialPath = '/') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[initialPath]}>
        <QuickAddSheet open onOpenChange={() => {}} />
        <Where />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('QuickAddSheet — "+" in due tocchi', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    useQuickAddStore.setState({ lastVehicleId: null });
    await i18n.changeLanguage('it');
  });

  it('propone subito cosa registrare, il rifornimento per primo, più "Nuovo veicolo"', async () => {
    mockApi();
    renderSheet();

    const actions = await screen.findAllByRole('button', { name: /^Nuov/ });
    expect(actions.map((b) => b.textContent)).toEqual([
      'Nuovo rifornimento',
      'Nuova manutenzione',
      'Nuova spesa',
      'Nuovo promemoria',
      'Nuovo veicolo',
    ]);
  });

  it('senza veicoli propri resta solo "Nuovo veicolo", che porta al modulo del veicolo', async () => {
    mockApi([vehicle(6, 'Della moglie', 'shared')]);
    const user = userEvent.setup();
    renderSheet();

    await user.click(await screen.findByRole('button', { name: 'Nuovo veicolo' }));

    expect(screen.queryByRole('button', { name: 'Nuovo rifornimento' })).not.toBeInTheDocument();
    expect(screen.getByTestId('where')).toHaveTextContent('/vehicles/new');
  });

  it('dal dettaglio di un veicolo il modulo si apre già su quel veicolo, coi km attuali e il prezzo dell\'ultimo pieno', async () => {
    mockApi();
    const user = userEvent.setup();
    renderSheet('/vehicles/5');

    await user.click(await screen.findByRole('button', { name: 'Nuovo rifornimento' }));

    const dialog = await screen.findByRole('dialog', { name: 'Nuovo rifornimento' });
    expect(within(dialog).getByRole('combobox', { name: 'Veicolo' })).toHaveTextContent('Ducati');
    expect(await within(dialog).findByLabelText(/Km al momento/)).toHaveValue(51_200);
    await waitFor(() => expect(within(dialog).getByLabelText(/€\/L/)).toHaveValue('1.849'));
    // Resta dove si era: nessuna pagina "scegli un veicolo" né di dettaglio.
    expect(screen.getByTestId('where')).toHaveTextContent('/vehicles/5');
  });

  it('salvare registra sul veicolo scelto, chiude il foglio e lo ricorda per la prossima volta', async () => {
    mockApi();
    const user = userEvent.setup();
    renderSheet('/vehicles/5');

    await user.click(await screen.findByRole('button', { name: 'Nuovo rifornimento' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nuovo rifornimento' });
    await waitFor(() => expect(within(dialog).getByLabelText(/€\/L/)).toHaveValue('1.849'));
    await user.type(within(dialog).getByLabelText(/Litri/), '32.5');
    await user.click(within(dialog).getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Nuovo rifornimento' })).not.toBeInTheDocument());
    const post = mockedAuthFetch.mock.calls.find(([path, init]) => path === '/api/refuelings' && init?.method === 'POST');
    expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({ vehicleId: 5, km: 51_200, liters: '32.5', pricePerLiter: '1.849' });
    expect(useQuickAddStore.getState().lastVehicleId).toBe(5);
    expect(screen.getByTestId('where')).toHaveTextContent('/vehicles/5');
  });

  it("senza contesto si riparte dall'ultimo veicolo usato", async () => {
    mockApi();
    useQuickAddStore.setState({ lastVehicleId: 5 });
    const user = userEvent.setup();
    renderSheet('/');

    await user.click(await screen.findByRole('button', { name: 'Nuova spesa' }));

    const dialog = await screen.findByRole('dialog', { name: 'Nuova spesa' });
    expect(within(dialog).getByRole('combobox', { name: 'Veicolo' })).toHaveTextContent('Ducati');
  });

  it('i veicoli condivisi in sola lettura non si possono scegliere', async () => {
    mockApi();
    const user = userEvent.setup();
    renderSheet('/vehicles/6');

    await user.click(await screen.findByRole('button', { name: 'Nuova spesa' }));

    const dialog = await screen.findByRole('dialog', { name: 'Nuova spesa' });
    expect(within(dialog).getByRole('combobox', { name: 'Veicolo' })).toHaveTextContent('Panda');
  });

  describe('accessibilità Radix', () => {
    afterEach(() => vi.restoreAllMocks());

    it('apre il foglio con una descrizione e senza warning', async () => {
      const error = vi.spyOn(console, 'error').mockImplementation(() => {});
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
      mockApi();
      renderSheet();

      await screen.findByRole('button', { name: 'Nuovo rifornimento' });

      expect(screen.getByRole('dialog')).toHaveAccessibleDescription('Scegli cosa registrare: il veicolo è già scelto.');
      const logged = [...error.mock.calls, ...warn.mock.calls].flat().map(String);
      expect(logged.filter((m) => /Description|aria-describedby/.test(m))).toEqual([]);
    });
  });
});
