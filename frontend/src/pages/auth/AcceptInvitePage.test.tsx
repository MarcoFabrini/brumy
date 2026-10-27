import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { AcceptInvitePage } from './AcceptInvitePage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const loggedUser: User = {
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
  memberships: [],
};

const preview = { organizationName: 'Officina Rossi', email: 'anna@test.it', role: 'member', accountExists: true };

function acceptCalls() {
  return mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/auth/invitation/accept');
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/accept-invite?token=tok']}>
        <AcceptInvitePage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return qc;
}

describe('AcceptInvitePage — utente loggato con l\'email invitata', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'authenticated', activeOrgId: 1, user: loggedUser });
  });

  it("dopo l'accettazione resta la schermata di successo anche se l'anteprima non è più leggibile", async () => {
    let previewCalls = 0;
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/invitation/tok') {
        // l'invito è consumato: dalla seconda lettura in poi il server risponde 400
        if (++previewCalls > 1) throw new ApiError('http.400', 400);
        return preview;
      }
      if (path === '/api/auth/invitation/accept') return { organizationId: 7 };
      if (path === '/api/auth/switch-org') return { activeOrganizationId: 7 };
      if (path === '/api/auth/me') return { ...loggedUser, activeOrganizationId: 7 };
      throw new Error(`unexpected fetch ${path}`);
    });
    const qc = renderPage();

    expect(await screen.findByText('Invito accettato')).toBeInTheDocument();
    expect(screen.getByText(/Officina Rossi/)).toBeInTheDocument();

    // anche se l'anteprima viene riletta e fallisce, il successo non si trasforma in "link non valido"
    await act(async () => {
      await qc.refetchQueries({ queryKey: ['invitation', 'tok'] });
    });
    expect(previewCalls).toBeGreaterThan(1);
    expect(screen.getByText('Invito accettato')).toBeInTheDocument();
    expect(screen.queryByText('Link non valido')).not.toBeInTheDocument();
  });

  it("l'accettazione non fa rileggere l'anteprima dell'invito consumato", async () => {
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/invitation/tok') return preview;
      if (path === '/api/auth/invitation/accept') return { organizationId: 7 };
      if (path === '/api/auth/switch-org') return { activeOrganizationId: 7 };
      if (path === '/api/auth/me') return { ...loggedUser, activeOrganizationId: 7 };
      throw new Error(`unexpected fetch ${path}`);
    });
    renderPage();

    await screen.findByText('Invito accettato');
    expect(mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/auth/invitation/tok')).toHaveLength(1);
  });

  it("se l'accettazione fallisce mostra Riprova, e un secondo tentativo richiama l'API", async () => {
    let attempts = 0;
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/invitation/tok') return preview;
      if (path === '/api/auth/invitation/accept') {
        if (++attempts === 1) throw new ApiError('http.500', 500);
        return {};
      }
      if (path === '/api/auth/me') return { ...loggedUser, activeOrganizationId: 7 };
      throw new Error(`unexpected fetch ${path}`);
    });
    const user = userEvent.setup();
    renderPage();

    const retry = await screen.findByRole('button', { name: 'Riprova' });
    expect(acceptCalls()).toHaveLength(1);

    await user.click(retry);

    expect(await screen.findByText('Invito accettato')).toBeInTheDocument();
    await waitFor(() => expect(acceptCalls()).toHaveLength(2));
  });
});
