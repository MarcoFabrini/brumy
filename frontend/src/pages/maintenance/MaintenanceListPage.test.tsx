import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import type { MaintenanceResponse } from '@/api/types/maintenance';
import type { Vehicle } from '@/api/types/vehicle';
import { LIST_PAGE_SIZE } from '@/lib/pagination';
import { MaintenanceListPage } from './MaintenanceListPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const golf: Vehicle = {
  id: 1,
  name: 'Golf',
  brand: 'VW',
  model: 'Golf',
  year: 2020,
  licensePlate: null,
  vin: null,
  type: 'car',
  fuelType: 'diesel',
  secondaryFuelType: null,
  initialKm: 0,
  notes: null,
  ownership: 'owned',
  permissions: { canEdit: true, canDelete: true, canShare: true },
};

const work = (id: number, description: string): MaintenanceResponse => ({
  id,
  vehicle: { id: 1, name: 'Golf', brand: 'VW', model: 'Golf' },
  performedAt: '2026-03-12T00:00:00+00:00',
  km: 1000,
  type: 'inspection',
  category: 'scheduled',
  description,
  cost: '120.00',
});

/** Le richieste di elenco, con i parametri letti come li legge il server. */
function listCalls(): URLSearchParams[] {
  return mockedAuthFetch.mock.calls
    .map(([path]) => path)
    .filter((path) => path.startsWith('/api/maintenances?'))
    .map((path) => new URLSearchParams(path.slice(path.indexOf('?') + 1)));
}

/** Risposta dell'elenco per parametri; `pending` lascia appesa una richiesta (per vedere il risultato precedente). */
type ListHandler = (params: URLSearchParams) => MaintenanceResponse[] | Promise<MaintenanceResponse[]>;

function renderPage(url: string, list: ListHandler) {
  mockedAuthFetch.mockImplementation(async (path: string) => {
    if (path === '/api/vehicles') return [golf];
    if (path === '/api/vehicles/1') return golf;
    if (path.startsWith('/api/maintenances?')) return list(new URLSearchParams(path.slice(path.indexOf('?') + 1)));
    throw new Error(`unexpected fetch ${path}`);
  });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[url]}>
        <Routes>
          <Route path="/maintenance" element={<MaintenanceListPage />} />
          <Route path="/maintenance/:id" element={<p>dettaglio</p>} />
          <Route path="/vehicles/:id" element={<p>veicolo</p>} />
        </Routes>
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return current;
}

/** L'ultima posizione del router, letta dai test. */
const current: { pathname: string; search: string; state: unknown } = { pathname: '', search: '', state: null };
function LocationProbe() {
  const location = useLocation();
  Object.assign(current, { pathname: location.pathname, search: location.search, state: location.state });
  return null;
}

const byQuery: ListHandler = (params) => {
  const q = params.get('q');
  if (q === null) return [work(1, 'Controllo annuale'), work(2, 'Pastiglie nuove')];
  if (q === 'tagl') return [work(1, 'Controllo annuale')];
  return [];
};

describe('MaintenanceListPage — ricerca', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('nella griglia di scelta del veicolo non c\'è il campo di ricerca', async () => {
    renderPage('/maintenance?category=scheduled', byQuery);

    expect(await screen.findByText('Golf')).toBeInTheDocument();
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });

  it('il campo ha il nome accessibile, è di tipo search e la "x" compare solo con del testo', async () => {
    renderPage('/maintenance?category=scheduled&vehicleId=1', byQuery);
    const user = userEvent.setup();

    const field = await screen.findByRole('searchbox', { name: "Cerca nell'elenco" });
    expect(field).toHaveAttribute('type', 'search');
    expect(screen.queryByRole('button', { name: 'Cancella ricerca' })).not.toBeInTheDocument();
    expect(within(screen.getByRole('search')).getByRole('searchbox')).toBe(field);

    await user.type(field, 'x');
    expect(screen.getByRole('button', { name: 'Cancella ricerca' })).toBeInTheDocument();
  });

  it('scrivendo veloce parte una sola richiesta dopo il ritardo e l\'URL tiene veicolo e sezione', async () => {
    const location = renderPage('/maintenance?category=inspection&vehicleId=1', byQuery);
    const user = userEvent.setup();

    await user.type(await screen.findByRole('searchbox'), 'tagl');

    expect(location.search).toBe('?category=inspection&vehicleId=1&q=tagl');
    await waitFor(() => expect(screen.queryByText('Pastiglie nuove')).not.toBeInTheDocument());
    expect(screen.getByText('Controllo annuale')).toBeInTheDocument();
    const searched = listCalls().map((p) => p.get('q'));
    expect(searched).toEqual([null, 'tagl']);
    expect(listCalls()[1]?.get('category')).toBe('inspection');
    expect(listCalls()[1]?.getAll('hint[0][]')).toEqual(['maintenance_type:inspection']);
  });

  it('aperta con ?q= il campo è già compilato e la ricerca parte subito', async () => {
    renderPage('/maintenance?category=scheduled&vehicleId=1&q=tagl', byQuery);

    expect(await screen.findByRole('searchbox')).toHaveValue('tagl');
    expect(await screen.findByText('Controllo annuale')).toBeInTheDocument();
    expect(listCalls().map((p) => p.get('q'))).toEqual(['tagl']);
  });

  it('mentre arriva il nuovo risultato le righe precedenti restano, con aria-busy', async () => {
    let release: (rows: MaintenanceResponse[]) => void = () => {};
    renderPage('/maintenance?category=scheduled&vehicleId=1', (params) =>
      params.get('q') === null ? byQuery(params) : new Promise((resolve) => (release = resolve)),
    );
    const user = userEvent.setup();
    expect(await screen.findByText('Pastiglie nuove')).toBeInTheDocument();

    await user.type(screen.getByRole('searchbox'), 'tagl');
    await waitFor(() => expect(listCalls()).toHaveLength(2));

    expect(screen.getByText('Pastiglie nuove')).toBeInTheDocument();
    expect(screen.getByText('Pastiglie nuove').closest('[aria-busy="true"]')).not.toBeNull();

    await act(async () => release([work(1, 'Controllo annuale')]));
    await waitFor(() => expect(screen.queryByText('Pastiglie nuove')).not.toBeInTheDocument());
    expect(document.querySelector('[aria-busy="true"]')).toBeNull();
  });

  it('nessun risultato: lo dice con la parola cercata; "Cancella ricerca" svuota campo e URL e torna all\'elenco', async () => {
    const location = renderPage('/maintenance?category=scheduled&vehicleId=1&q=zzz', byQuery);
    const user = userEvent.setup();

    expect(await screen.findByRole('status')).toHaveTextContent('Nessun risultato per «zzz»');
    const clearButtons = screen.getAllByRole('button', { name: 'Cancella ricerca' });
    await user.click(clearButtons[clearButtons.length - 1]!);

    expect(await screen.findByText('Pastiglie nuove')).toBeInTheDocument();
    expect(screen.getByRole('searchbox')).toHaveValue('');
    expect(location.search).toBe('?category=scheduled&vehicleId=1');
  });

  it('la "x" del campo svuota la ricerca e ricarica l\'elenco completo', async () => {
    const location = renderPage('/maintenance?category=scheduled&vehicleId=1&q=tagl', byQuery);
    const user = userEvent.setup();
    expect(await screen.findByText('Controllo annuale')).toBeInTheDocument();

    await user.click(within(screen.getByRole('search')).getByRole('button', { name: 'Cancella ricerca' }));

    expect(await screen.findByText('Pastiglie nuove')).toBeInTheDocument();
    expect(screen.getByRole('searchbox')).toHaveValue('');
    expect(location.search).toBe('?category=scheduled&vehicleId=1');
  });

  it('un elenco vuoto senza ricerca mostra solo il suo stato vuoto, senza campo', async () => {
    renderPage('/maintenance?category=scheduled&vehicleId=1', () => []);

    expect(await screen.findByText('Nessun lavoro ordinario')).toBeInTheDocument();
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });

  it('le righe portano al dettaglio ricordando l\'elenco con la sua ricerca', async () => {
    const location = renderPage('/maintenance?category=scheduled&vehicleId=1&q=tagl', byQuery);
    const user = userEvent.setup();

    await user.click(await screen.findByRole('link', { name: /Controllo annuale/ }));

    expect(location.pathname).toBe('/maintenance/1');
    expect(location.state).toEqual({ from: '/maintenance?category=scheduled&vehicleId=1&q=tagl' });
  });
});

describe('MaintenanceListPage — pagine successive', () => {
  const fullPage = (page: number) =>
    Array.from({ length: LIST_PAGE_SIZE }, (_, i) => work(page * 100 + i, `Lavoro ${page}-${i}`));

  let observers: Array<{ callback: IntersectionObserverCallback; target: Element | null }> = [];

  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    observers = [];
    vi.stubGlobal(
      'IntersectionObserver',
      class {
        private entry: { callback: IntersectionObserverCallback; target: Element | null };
        constructor(callback: IntersectionObserverCallback) {
          this.entry = { callback, target: null };
          observers.push(this.entry);
        }
        observe(target: Element) {
          this.entry.target = target;
        }
        disconnect() {
          observers = observers.filter((o) => o !== this.entry);
        }
      },
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('"Mostra altri" carica la pagina dopo e sparisce a fine elenco', async () => {
    renderPage('/maintenance?category=scheduled&vehicleId=1', (params) =>
      params.get('page') === '1' ? fullPage(1) : [work(999, 'Il più vecchio')],
    );
    const user = userEvent.setup();

    await user.click(await screen.findByRole('button', { name: 'Mostra altri' }));

    expect(await screen.findByText('Il più vecchio')).toBeInTheDocument();
    expect(listCalls().map((p) => [p.get('page'), p.get('limit')])).toEqual([
      ['1', String(LIST_PAGE_SIZE)],
      ['2', String(LIST_PAGE_SIZE)],
    ]);
    expect(screen.getAllByRole('link', { name: /Lavoro 1-\d+|Il più vecchio/ })).toHaveLength(LIST_PAGE_SIZE + 1);
    expect(screen.queryByRole('button', { name: 'Mostra altri' })).not.toBeInTheDocument();
  });

  it('arrivando in fondo scorrendo la pagina dopo parte da sola', async () => {
    renderPage('/maintenance?category=scheduled&vehicleId=1', (params) =>
      params.get('page') === '1' ? fullPage(1) : [work(999, 'Il più vecchio')],
    );
    const button = await screen.findByRole('button', { name: 'Mostra altri' });
    await waitFor(() => expect(observers.some((o) => o.target === button)).toBe(true));

    const observer = observers.find((o) => o.target === button)!;
    act(() => observer.callback([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver));

    expect(await screen.findByText('Il più vecchio')).toBeInTheDocument();
    expect(listCalls()).toHaveLength(2);
  });

  it('se la pagina dopo fallisce non riprova da sola: riprende solo premendo "Mostra altri"', async () => {
    let secondPageCalls = 0;
    renderPage('/maintenance?category=scheduled&vehicleId=1', async (params) => {
      if (params.get('page') === '1') return fullPage(1);
      secondPageCalls += 1;
      // Latenza reale: la pagina risulta "in caricamento" in un render prima di fallire, come in produzione
      await new Promise((resolve) => setTimeout(resolve, 20));
      if (secondPageCalls === 1) throw new Error('offline');
      return [work(999, 'Il più vecchio')];
    });
    const user = userEvent.setup();
    const button = await screen.findByRole('button', { name: 'Mostra altri' });
    await waitFor(() => expect(observers.some((o) => o.target === button)).toBe(true));

    const first = observers.find((o) => o.target === button)!;
    act(() => first.callback([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver));
    await waitFor(() => expect(secondPageCalls).toBe(1));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Mostra altri' })).toBeEnabled());

    // Il fondo è ancora in vista: nessun osservatore riarmato, nessuna nuova richiesta
    for (const observer of [...observers]) {
      act(() => observer.callback([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver));
    }
    expect(observers.some((o) => o.target === screen.getByRole('button', { name: 'Mostra altri' }))).toBe(false);
    expect(secondPageCalls).toBe(1);

    await user.click(screen.getByRole('button', { name: 'Mostra altri' }));

    expect(await screen.findByText('Il più vecchio')).toBeInTheDocument();
    expect(secondPageCalls).toBe(2);
  });

  it('con una ricerca le pagine successive restano della ricerca', async () => {
    renderPage('/maintenance?category=scheduled&vehicleId=1&q=lavoro', (params) =>
      params.get('page') === '1' ? fullPage(1) : [],
    );
    const user = userEvent.setup();

    await user.click(await screen.findByRole('button', { name: 'Mostra altri' }));

    await waitFor(() => expect(listCalls()).toHaveLength(2));
    expect(listCalls()[1]?.get('q')).toBe('lavoro');
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Mostra altri' })).not.toBeInTheDocument());
  });
});
