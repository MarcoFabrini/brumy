import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RouterProvider, createMemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { routes } from './router';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const user: User = {
  id: 1,
  email: 'anna@test.it',
  firstName: 'Anna',
  lastName: 'Neri',
  locale: 'it',
  hasAvatar: false,
  emailVerified: true,
  pendingEmail: null,
  isInstanceAdmin: false,
  twoFactorEnabled: false,
  backupCodesRemaining: 0,
  memberships: [{ id: 1, role: 'member', organization: { id: 1, name: 'Officina', slug: 'officina' } }],
};

function renderAt(path: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(
    <QueryClientProvider client={qc}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return router;
}

const signedOut = () => useAuthStore.setState({ status: 'unauthenticated', activeOrgId: null, user: null });
const signedIn = () => useAuthStore.setState({ status: 'authenticated', activeOrgId: 1, user });

// Le pagine in lazy si risolvono una volta sola per modulo (cache di React.lazy): il test che mostra
// il fallback deve essere il primo a visitare quella pagina.
describe('rotte — pagine caricate in lazy', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('/register mostra lo spinner di caricamento e poi la pagina', async () => {
    signedOut();
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/registration') return { open: true };
      throw new Error(`unexpected fetch ${path}`);
    });
    renderAt('/register');

    expect(screen.getByRole('status', { name: 'Caricamento in corso' })).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Crea account' })).toBeInTheDocument();
  });

  it('/settings dentro la shell: prima il fallback, poi la pagina, con la shell sempre a vista', async () => {
    signedIn();
    mockedAuthFetch.mockRejectedValue(new ApiError('http.404', 404));
    renderAt('/settings');

    // la shell (navigazione) c'è subito, il contenuto arriva dopo
    expect(screen.getAllByRole('navigation').length).toBeGreaterThan(0);
    expect(screen.getByRole('status', { name: 'Caricamento in corso' })).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Esci' })).toBeInTheDocument();
  });

  it('una rotta sconosciuta mostra la 404 dentro la shell', async () => {
    signedIn();
    renderAt('/non-esiste');

    expect(await screen.findByRole('heading', { name: 'Pagina non trovata' })).toBeInTheDocument();
  });

  it("l'id non valido nell'URL dà la 404 senza caricare la pagina del veicolo", async () => {
    signedIn();
    renderAt('/vehicles/abc');

    expect(await screen.findByRole('heading', { name: 'Pagina non trovata' })).toBeInTheDocument();
    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });
});
