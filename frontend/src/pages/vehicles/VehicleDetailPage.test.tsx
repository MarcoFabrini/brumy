import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactElement } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import type { Vehicle } from '@/api/types/vehicle';
import { useAuthStore } from '@/stores/useAuthStore';
import { VehicleDetailPage } from './VehicleDetailPage';
import { VehicleSharingPage } from './VehicleSharingPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

// In jsdom ResponsiveContainer misura 0×0 e non disegna nulla: dimensione fissa.
vi.mock('recharts', async (importOriginal) => {
  const { cloneElement } = await import('react');
  return {
    ...(await importOriginal<typeof import('recharts')>()),
    ResponsiveContainer: ({ children }: { children: ReactElement<{ width?: number; height?: number }> }) =>
      cloneElement(children, { width: 400, height: 240 }),
  };
});

const vehicle = (id: number, name: string): Vehicle => ({
  id,
  name,
  brand: 'Fiat',
  model: 'Panda',
  year: 2020,
  licensePlate: null,
  vin: null,
  type: 'car',
  fuelType: 'diesel',
  secondaryFuelType: null,
  initialKm: 0,
  notes: null,
  archivedAt: null,
  ownership: 'owned',
  permissions: { canEdit: true, canDelete: true, canShare: false },
});

const charts = {
  from: '2026-10',
  to: '2026-10',
  fuelTypes: ['diesel'],
  months: [
    {
      month: '2026-10',
      spending: { refuelings: '10.00', scheduled: '0.00', unscheduled: '0.00', inspection: '0.00', taxes: '0.00', insurance: '0.00', expenses: '0.00', total: '10.00' },
      kmDriven: 100,
      consumption: { diesel: 12.5 },
    },
  ],
  spendingByCategory: [{ category: 'fuel', name: null, amount: '10.00' }],
  totals: { spending: '10.00', kmDriven: 100 },
};

const stats = {
  consumption: { diesel: 12.5 },
  totals: {
    cost: '10.00', refuelings: 1, maintenances: 3, expenses: 2,
    sections: { scheduled: 2, unscheduled: 0, inspection: 1, tax: 1, insurance: 0, expense: 1, component: 4 },
  },
  currentKm: 100,
  kmDriven: 100,
  costPerKm: 0.1,
};

function renderPage(ownedVehicles: Vehicle[]) {
  mockedAuthFetch.mockImplementation(async (path: string, options?: RequestInit) => {
    if (path === '/api/vehicles/1/archive' && options?.method === 'POST') {
      return { ...ownedVehicles[0], archivedAt: '2026-10-05T10:00:00+02:00' };
    }
    if (path === '/api/vehicles') return ownedVehicles;
    if (path === '/api/vehicles/1') return ownedVehicles[0];
    if (path === '/api/vehicles/1/stats') return stats;
    if (path === '/api/vehicles/1/charts?months=12') return charts;
    return []; // liste delle tab
  });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/vehicles/1']}>
        <Routes>
          <Route path="/vehicles/:id" element={<VehicleDetailPage />} />
          <Route path="/vehicles/:id/sharing" element={<VehicleSharingPage />} />
          <Route path="/vehicles" element={<p>Lista veicoli</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/** Il menu "Aggiungi" della barra (desktop): non il pulsante "Aggiungi" degli allegati. */
const addMenus = () =>
  screen.queryAllByRole('button', { name: 'Aggiungi' }).filter((b) => b.getAttribute('aria-haspopup') === 'menu');
const findAddMenu = async () => {
  await waitFor(() => expect(addMenus()).toHaveLength(1));
  return addMenus()[0]!;
};

/** Le azioni sul veicolo stanno nel menu "…" ("Altre azioni"). */
async function openActions(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Altre azioni' }));
}

describe('VehicleDetailPage', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('mostra i grafici del veicolo dopo il riepilogo e i dati, anche se è l\'unico veicolo', async () => {
    renderPage([vehicle(1, 'Golf')]);

    const chartsHeading = await screen.findByRole('heading', { name: 'Andamento' });
    const infoTitle = screen.getByText('Tipo veicolo');
    expect(infoTitle.compareDocumentPosition(chartsHeading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(await screen.findByRole('table', { name: 'Spese mensili' })).toBeInTheDocument();
  });

  it('il registro ha una riga per sezione, con la sua lista per questo veicolo e il conteggio', async () => {
    renderPage([vehicle(1, 'Golf')]);

    const refuelings = await screen.findByRole('link', { name: /Rifornimenti/ });
    expect(refuelings).toHaveAttribute('href', '/refueling?vehicleId=1');
    await waitFor(() => expect(refuelings).toHaveTextContent('1'));
    const rows = [
      ['Lavori ordinari', '/maintenance?category=scheduled&vehicleId=1', '2'],
      ['Lavori imprevisti', '/maintenance?category=unscheduled&vehicleId=1', '0'],
      ['Revisione', '/maintenance?category=inspection&vehicleId=1', '1'],
      ['Tasse', '/expenses?section=tax&vehicleId=1', '1'],
      ['Assicurazione', '/expenses?section=insurance&vehicleId=1', '0'],
      ['Altre spese', '/expenses?section=other&vehicleId=1', '1'],
      ['Componenti', '/components?vehicleId=1', '4'],
    ] as const;
    for (const [name, href, count] of rows) {
      const link = screen.getByRole('link', { name: new RegExp(`^${name}`) });
      expect(link).toHaveAttribute('href', href);
      expect(link).toHaveTextContent(count);
    }
    expect(screen.getByRole('link', { name: /Promemoria/ })).toHaveAttribute('href', '/reminders?vehicleId=1');
  });

  it('in cima mostra la prossima scadenza, che apre il promemoria', async () => {
    mockedAuthFetch.mockImplementation(async (path: string) => {
      if (path === '/api/vehicles') return [vehicle(1, 'Golf')];
      if (path === '/api/vehicles/1') return vehicle(1, 'Golf');
      if (path === '/api/vehicles/1/stats') return stats;
      if (path === '/api/reminders?vehicleId=1') {
        return [{ id: 9, vehicle: { id: 1, name: 'Golf', brand: 'VW', model: 'Golf' }, type: 'inspection', description: 'Revisione', dueDate: '2020-01-01', dueKm: null, notifyDaysBefore: 30, notifyKmBefore: 1000, recurrenceMonths: 24, recurrenceKm: null, completedAt: null, completedOn: null, completedKm: null }];
      }
      return [];
    });
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/vehicles/1']}>
          <Routes>
            <Route path="/vehicles/:id" element={<VehicleDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const next = await screen.findByRole('link', { name: /Prossima scadenza/ });
    expect(next).toHaveAttribute('href', '/reminders/9');
    expect(next).toHaveTextContent('Revisione');
    expect(next).toHaveTextContent('Scaduto');
  });

  it('in cima la spesa del mese corrente; il costo totale sta nei dati del veicolo', async () => {
    renderPage([vehicle(1, 'Golf')]);

    const month = (await screen.findByText('Speso questo mese')).nextElementSibling as HTMLElement;
    // I grafici di prova hanno solo ottobre 2026: negli altri mesi la spesa del mese è zero.
    const thisMonth = `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}`;
    await waitFor(() => expect(month).toHaveTextContent(thisMonth === '2026-10' ? '10,00' : '0,00'));
    expect(screen.getByText('Costo totale').nextElementSibling).toHaveTextContent('10,00');
  });

  it('"Aggiungi" apre la scelta di cosa inserire per questo veicolo', async () => {
    renderPage([vehicle(1, 'Golf'), vehicle(2, 'Panda')]);
    const user = userEvent.setup();

    await user.click(await findAddMenu());

    const links = [
      ['Nuovo rifornimento', '/refueling/new?vehicleId=1'],
      ['Nuovo lavoro ordinario', '/maintenance/new?category=scheduled&vehicleId=1'],
      ['Nuova revisione', '/maintenance/new?category=inspection&vehicleId=1'],
      ['Nuova tassa', '/expenses/new?section=tax&vehicleId=1'],
      ['Nuova spesa', '/expenses/new?section=other&vehicleId=1'],
      ['Nuovo componente', '/components/new?vehicleId=1'],
      ['Nuovo promemoria', '/reminders/new?vehicleId=1'],
    ];
    for (const [name, href] of links) {
      expect(await screen.findByRole('menuitem', { name })).toHaveAttribute('href', href);
    }
  });

  it.each([
    ['condiviso in sola lettura', { ownership: 'shared' as const, permissions: { canEdit: false, canDelete: false, canShare: false } }],
    ['archiviato', { archivedAt: '2026-09-01T10:00:00+02:00' }],
  ])('veicolo %s: nessun "Aggiungi"', async (_case, overrides) => {
    renderPage([{ ...vehicle(1, 'Golf'), ...overrides }, vehicle(2, 'Panda')]);

    expect(await screen.findByText('Tipo veicolo')).toBeInTheDocument();
    expect(addMenus()).toHaveLength(0);
  });

  describe('trasferimento di proprietà', () => {
    const me = {
      id: 1,
      email: 'anna@test.it',
      firstName: 'Anna',
      lastName: 'Neri',
      locale: 'it' as const,
      hasAvatar: false,
      emailVerified: true,
      pendingEmail: null,
      isInstanceAdmin: false,
      memberships: [{ id: 1, role: 'owner' as const, organization: { id: 1, name: 'Org', slug: 'org' } }],
    };

    beforeEach(() => {
      useAuthStore.setState({ user: me });
      // Radix Select in jsdom: mancano alcune API del puntatore
      Element.prototype.hasPointerCapture = () => false;
      Element.prototype.setPointerCapture = () => undefined;
      Element.prototype.releasePointerCapture = () => undefined;
      Element.prototype.scrollIntoView = () => undefined;
    });

    it('la condivisione non sta nella pagina: si apre dall\'icona (sul telefono è una pagina a sé)', async () => {
      renderPage([{ ...vehicle(1, 'Golf'), permissions: { canEdit: true, canDelete: true, canShare: true } }]);
      const user = userEvent.setup();

      await screen.findByText('Tipo veicolo');
      expect(screen.queryByRole('button', { name: 'Trasferisci proprietà' })).not.toBeInTheDocument();
      await user.click(screen.getByRole('button', { name: 'Condivisione' }));

      expect(await screen.findByRole('heading', { level: 1, name: 'Condivisione' })).toBeInTheDocument();
      expect(await screen.findByRole('button', { name: 'Trasferisci proprietà' })).toBeInTheDocument();
    });

    it('un member che cede senza tenere l\'accesso torna alla lista veicoli', async () => {
      useAuthStore.setState({ user: { ...me, memberships: [{ ...me.memberships[0]!, role: 'member' }] } });
      const user = userEvent.setup();
      renderPage([{ ...vehicle(1, 'Golf'), permissions: { canEdit: true, canDelete: true, canShare: true } }]);
      const original = mockedAuthFetch.getMockImplementation();
      mockedAuthFetch.mockImplementation(async (path: string, options?: RequestInit) => {
        if (path === '/api/vehicles/1/transfer-candidates') return [{ id: 10, firstName: 'Carlo', lastName: 'Alberti' }];
        if (path === '/api/vehicles/1/transfer' && options?.method === 'POST') return undefined;
        return original ? original(path, options) : [];
      });

      await user.click(await screen.findByRole('button', { name: 'Condivisione' }));
      await user.click(await screen.findByRole('button', { name: 'Trasferisci proprietà' }));
      await user.click(await screen.findByRole('combobox', { name: /Nuovo proprietario/ }));
      await user.click(await screen.findByRole('option', { name: 'Carlo Alberti' }));
      await user.click(screen.getByRole('checkbox', { name: /Mantieni l'accesso/ }));
      await user.click(screen.getByRole('button', { name: 'Trasferisci' }));

      expect(await screen.findByText('Lista veicoli')).toBeInTheDocument();
    });

    it('senza canShare (es. condiviso in sola lettura) la card e il pulsante non ci sono', async () => {
      renderPage([
        { ...vehicle(1, 'Golf'), ownership: 'shared', permissions: { canEdit: false, canDelete: false, canShare: false } },
      ]);

      expect(await screen.findByText('Tipo veicolo')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Condivisione' })).not.toBeInTheDocument();
      expect(mockedAuthFetch).not.toHaveBeenCalledWith('/api/vehicles/1/transfer-candidates');
    });
  });

  describe('archiviazione', () => {
    const archiveCalls = () =>
      mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/vehicles/1/archive');

    it('chi può eliminare trova "Archivia" ed "Elimina" nel menu "…"', async () => {
      renderPage([vehicle(1, 'Golf')]);
      const user = userEvent.setup();

      await openActions(user);
      expect(await screen.findByRole('menuitem', { name: 'Archivia' })).toBeInTheDocument();
      expect(screen.getByRole('menuitem', { name: 'Elimina' })).toBeInTheDocument();
    });

    it.each([
      ['senza permesso di eliminazione', { permissions: { canEdit: true, canDelete: false, canShare: false } }],
      ['già archiviato (c\'è "Ripristina")', { archivedAt: '2026-09-01T10:00:00+02:00' }],
      ['condiviso in sola lettura', { ownership: 'shared' as const, permissions: { canEdit: false, canDelete: false, canShare: false } }],
    ])('veicolo %s: nessun "Archivia"', async (_case, overrides) => {
      renderPage([{ ...vehicle(1, 'Golf'), ...overrides }]);

      expect(await screen.findByText('Tipo veicolo')).toBeInTheDocument();
      const more = screen.queryByRole('button', { name: 'Altre azioni' });
      if (more) await userEvent.setup().click(more);
      expect(screen.queryByRole('menuitem', { name: 'Archivia' })).not.toBeInTheDocument();
    });

    it('chiede conferma, poi chiama POST archive e torna alla lista', async () => {
      renderPage([vehicle(1, 'Golf')]);
      const user = userEvent.setup();

      await openActions(user);
      await user.click(await screen.findByRole('menuitem', { name: 'Archivia' }));
      expect(await screen.findByText('Archiviare il veicolo?')).toBeInTheDocument();
      expect(screen.getByText(/scompare da elenchi, dashboard e notifiche/)).toBeInTheDocument();
      expect(archiveCalls()).toHaveLength(0); // niente prima della conferma

      const dialog = screen.getByRole('dialog');
      await user.click(within(dialog).getByRole('button', { name: 'Archivia' }));

      expect(await screen.findByText('Lista veicoli')).toBeInTheDocument();
      expect(archiveCalls()).toEqual([['/api/vehicles/1/archive', { method: 'POST' }]]);
    });

    it('annullando non archivia e resta sul dettaglio', async () => {
      renderPage([vehicle(1, 'Golf')]);
      const user = userEvent.setup();

      await openActions(user);
      await user.click(await screen.findByRole('menuitem', { name: 'Archivia' }));
      await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Annulla' }));

      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
      expect(archiveCalls()).toHaveLength(0);
      expect(screen.queryByText('Lista veicoli')).not.toBeInTheDocument();
    });
  });

  describe('km attuali', () => {
    // initialKm distinto dai km delle statistiche: se compare, vuol dire che è stato usato come fallback
    const withInitialKm = { ...vehicle(1, 'Golf'), initialKm: 12345 };
    const currentKm = () => screen.getByText('Km attuali').nextElementSibling;

    function renderWithStats(statsResult: () => Promise<unknown>) {
      mockedAuthFetch.mockImplementation(async (path: string) => {
        if (path === '/api/vehicles') return [withInitialKm];
        if (path === '/api/vehicles/1') return withInitialKm;
        if (path === '/api/vehicles/1/stats') return statsResult();
        return [];
      });
      const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      return render(
        <QueryClientProvider client={qc}>
          <MemoryRouter initialEntries={['/vehicles/1']}>
            <Routes>
              <Route path="/vehicles/:id" element={<VehicleDetailPage />} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>,
      );
    }

    it('mentre le statistiche caricano non presenta i km iniziali come attuali', async () => {
      renderWithStats(() => new Promise(() => {}));

      await screen.findByText('Tipo veicolo');
      expect(currentKm()).not.toHaveTextContent('12.345');
      expect(currentKm()).not.toHaveTextContent('km');
    });

    it('se le statistiche falliscono mostra un trattino, non i km iniziali', async () => {
      renderWithStats(() => Promise.reject(new Error('boom')));

      await waitFor(() => expect(currentKm()).toHaveTextContent('—'));
      expect(currentKm()).not.toHaveTextContent('12.345');
    });

    it('con le statistiche mostra i km attuali', async () => {
      renderWithStats(async () => ({ ...stats, currentKm: 20000 }));

      await waitFor(() => expect(currentKm()).toHaveTextContent('20.000 km'));
    });
  });
});
