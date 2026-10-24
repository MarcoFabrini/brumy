import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { BottomNav } from './BottomNav';
import { Sidebar } from './Sidebar';
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

  it('la sidebar desktop ha le voci della tab bar (Home, Veicoli, Scadenze): i record si gestiscono dal veicolo', () => {
    renderWithProviders(<Sidebar />);

    const nav = within(screen.getByRole('navigation', { name: 'Navigazione principale' }));
    const links = nav.getAllByRole('link');
    expect(links.map((link) => link.getAttribute('href'))).toEqual(['/', '/vehicles', '/reminders']);
    expect(links.map((link) => link.textContent)).toEqual(['Home', 'Veicoli', 'Scadenze']);
    for (const label of ['Manutenzioni', 'Rifornimenti', 'Spese']) {
      expect(nav.queryByText(label)).not.toBeInTheDocument();
    }
  });

  it("la sidebar non rende il nome dell'organizzazione come heading (precederebbe l'h1 della pagina)", () => {
    renderWithProviders(<Sidebar />);

    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });

  it('la tab bar mobile è Home · Veicoli · "+" · Scadenze · Profilo, con le etichette sotto le icone', () => {
    renderWithProviders(<BottomNav />);

    const nav = within(screen.getByRole('navigation', { name: 'Navigazione principale mobile' }));
    const links = nav.getAllByRole('link');
    expect(links.map((link) => link.getAttribute('href'))).toEqual(['/', '/vehicles', '/reminders', '/settings']);
    expect(links.map((link) => link.textContent)).toEqual(['Home', 'Veicoli', 'Scadenze', 'Profilo']);
    expect(nav.getByRole('button', { name: 'Aggiungi' })).toBeInTheDocument();
  });

  it('la tab attiva è segnata con aria-current', () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/vehicles/3']}>
          <BottomNav />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(screen.getByRole('link', { name: 'Veicoli' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'Home' })).not.toHaveAttribute('aria-current');
  });

  it('la sidebar mostra il nome org come testo con una sola organizzazione', () => {
    setMemberships([officina], 1);
    renderWithProviders(<Sidebar />);

    expect(screen.getAllByText('Officina Rossi')).toHaveLength(1);
    expect(screen.queryByRole('button', { name: /cambia organizzazione/ })).not.toBeInTheDocument();
  });

  it('con più organizzazioni la sidebar offre il cambio e resta senza heading', () => {
    setMemberships([officina, famiglia], 2);
    renderWithProviders(<Sidebar />);

    expect(screen.getAllByRole('button', { name: 'Famiglia Neri, cambia organizzazione' })).toHaveLength(1);
    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });
});
