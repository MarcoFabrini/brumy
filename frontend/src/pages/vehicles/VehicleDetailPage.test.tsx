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
      spending: { refuelings: '10.00', maintenances: '0.00', expenses: '0.00', total: '10.00' },
      kmDriven: 100,
      consumption: { diesel: 12.5 },
    },
  ],
  spendingByCategory: [{ category: 'fuel', amount: '10.00' }],
  totals: { spending: '10.00', kmDriven: 100 },
};

const stats = {
  consumption: { diesel: 12.5 },
  totals: { cost: '10.00', refuelings: 1, maintenances: 0, expenses: 0 },
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
          <Route path="/vehicles" element={<p>Lista veicoli</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('VehicleDetailPage', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('con più veicoli propri mostra i grafici del veicolo prima della card informazioni', async () => {
    renderPage([vehicle(1, 'Golf'), vehicle(2, 'Panda')]);

    const chartsHeading = await screen.findByRole('heading', { name: 'Grafici del veicolo' });
    const infoTitle = screen.getByText('Dati veicolo');
    expect(chartsHeading.compareDocumentPosition(infoTitle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(await screen.findByRole('table', { name: 'Spese mensili' })).toBeInTheDocument();
  });

  it('con un solo veicolo proprio non mostra i grafici (sono già in dashboard)', async () => {
    renderPage([vehicle(1, 'Golf')]);

    expect(await screen.findByText('Dati veicolo')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Grafici del veicolo' })).not.toBeInTheDocument();
    expect(mockedAuthFetch).not.toHaveBeenCalledWith('/api/vehicles/1/charts?months=12');
  });

  it('"Aggiungi" apre la scelta di cosa inserire per questo veicolo', async () => {
    renderPage([vehicle(1, 'Golf'), vehicle(2, 'Panda')]);
    const user = userEvent.setup();

    await user.click(await screen.findByRole('button', { name: 'Aggiungi' }));

    const links = [
      ['Nuova manutenzione', '/maintenance/new?vehicleId=1'],
      ['Nuovo rifornimento', '/refueling/new?vehicleId=1'],
      ['Nuova spesa', '/expenses/new?vehicleId=1'],
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

    expect(await screen.findByText('Dati veicolo')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Aggiungi' })).not.toBeInTheDocument();
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

    it('chi può condividere vede "Trasferisci proprietà" nella card di condivisione', async () => {
      renderPage([{ ...vehicle(1, 'Golf'), permissions: { canEdit: true, canDelete: true, canShare: true } }]);

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

      expect(await screen.findByText('Dati veicolo')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Trasferisci proprietà' })).not.toBeInTheDocument();
      expect(mockedAuthFetch).not.toHaveBeenCalledWith('/api/vehicles/1/transfer-candidates');
    });
  });

  describe('archiviazione', () => {
    const archiveCalls = () =>
      mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/vehicles/1/archive');

    it('chi può eliminare vede "Archivia" su un veicolo attivo', async () => {
      renderPage([vehicle(1, 'Golf')]);

      expect(await screen.findByRole('button', { name: 'Archivia' })).toBeInTheDocument();
    });

    it.each([
      ['senza permesso di eliminazione', { permissions: { canEdit: true, canDelete: false, canShare: false } }],
      ['già archiviato (c\'è "Ripristina")', { archivedAt: '2026-09-01T10:00:00+02:00' }],
      ['condiviso in sola lettura', { ownership: 'shared' as const, permissions: { canEdit: false, canDelete: false, canShare: false } }],
    ])('veicolo %s: nessun "Archivia"', async (_case, overrides) => {
      renderPage([{ ...vehicle(1, 'Golf'), ...overrides }]);

      expect(await screen.findByText('Dati veicolo')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Archivia' })).not.toBeInTheDocument();
    });

    it('chiede conferma, poi chiama POST archive e torna alla lista', async () => {
      renderPage([vehicle(1, 'Golf')]);
      const user = userEvent.setup();

      await user.click(await screen.findByRole('button', { name: 'Archivia' }));
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

      await user.click(await screen.findByRole('button', { name: 'Archivia' }));
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

      await screen.findByText('Dati veicolo');
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
