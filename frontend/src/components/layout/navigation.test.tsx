import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { BottomNav } from './BottomNav';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { useAuthStore, type User } from '@/stores/useAuthStore';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

function renderWithProviders(ui: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
}

const tokenFor = (orgId: number) => `h.${btoa(JSON.stringify({ active_org_id: orgId })).replace(/=+$/, '')}.s`;

function setMemberships(memberships: User['memberships'], activeOrgId: number) {
  useAuthStore.setState({
    status: 'authenticated',
    accessToken: tokenFor(activeOrgId),
    user: {
      id: 1,
      email: 'anna@test.it',
      firstName: 'Anna',
      lastName: 'Neri',
      locale: 'it',
      hasAvatar: false,
      emailVerified: true,
      pendingEmail: null,
      isInstanceAdmin: false,
      memberships,
    },
  });
}

const officina = { id: 11, role: 'owner', organization: { id: 1, name: 'Officina Rossi', slug: 'officina-rossi' } } as const;
const famiglia = { id: 12, role: 'member', organization: { id: 2, name: 'Famiglia Neri', slug: 'famiglia-neri' } } as const;

describe('navigazione', () => {
  beforeEach(async () => {
    useAuthStore.setState({ status: 'loading', accessToken: null, user: null });
    mockedAuthFetch.mockReset();
    mockedAuthFetch.mockResolvedValue([]);
    await i18n.changeLanguage('it');
  });

  it('la sidebar desktop mostra solo Dashboard e Veicoli: i record si gestiscono dal veicolo', () => {
    renderWithProviders(<Sidebar />);

    const nav = within(screen.getByRole('navigation', { name: 'Navigazione principale' }));
    expect(nav.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/', '/vehicles']);
    for (const label of ['Manutenzioni', 'Rifornimenti', 'Spese', 'Promemoria']) {
      expect(nav.queryByText(label)).not.toBeInTheDocument();
    }
  });

  it("la sidebar non rende il nome dell'organizzazione come heading (precederebbe l'h1 della pagina)", () => {
    renderWithProviders(<Sidebar />);

    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });

  it('la barra mobile resta Home, "+" e Promemoria', () => {
    renderWithProviders(<BottomNav />);

    const nav = within(screen.getByRole('navigation', { name: 'Navigazione principale mobile' }));
    expect(nav.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/', '/reminders']);
    expect(nav.getByRole('button', { name: 'Aggiungi' })).toBeInTheDocument();
  });

  it('sidebar e topbar mostrano il nome org come testo con una sola organizzazione', () => {
    setMemberships([officina], 1);
    renderWithProviders(
      <>
        <Sidebar />
        <Topbar />
      </>,
    );

    expect(screen.getAllByText('Officina Rossi')).toHaveLength(2);
    expect(screen.queryByRole('button', { name: /cambia organizzazione/ })).not.toBeInTheDocument();
  });

  it('con più organizzazioni sidebar e topbar offrono il cambio, e la sidebar resta senza heading', () => {
    setMemberships([officina, famiglia], 2);
    renderWithProviders(
      <>
        <Sidebar />
        <Topbar />
      </>,
    );

    expect(screen.getAllByRole('button', { name: 'Famiglia Neri, cambia organizzazione' })).toHaveLength(2);
    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });
});
