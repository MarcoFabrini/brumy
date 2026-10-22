import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { Toaster } from '@/components/ui';
import { clearQueryCacheOnSessionChange } from '@/lib/sessionCache';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { OrganizationSwitcher } from './OrganizationSwitcher';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const tokenFor = (orgId: number) => `h.${btoa(JSON.stringify({ active_org_id: orgId })).replace(/=+$/, '')}.s`;

const officina = { id: 11, role: 'owner', organization: { id: 1, name: 'Officina Rossi', slug: 'officina-rossi' } } as const;
const famiglia = { id: 12, role: 'member', organization: { id: 2, name: 'Famiglia Neri', slug: 'famiglia-neri' } } as const;

function setSession(memberships: User['memberships'], activeOrgId = 1) {
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

function Where() {
  const { pathname } = useLocation();
  return <output data-testid="where">{pathname}</output>;
}

function renderSwitcher(qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/vehicles/42']}>
        <Routes>
          <Route path="*" element={<OrganizationSwitcher />} />
        </Routes>
        <Where />
      </MemoryRouter>
      <Toaster />
    </QueryClientProvider>,
  );
  return qc;
}

describe('OrganizationSwitcher', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('con una sola organizzazione mostra solo il nome, senza bottone', () => {
    setSession([officina]);
    renderSwitcher();

    expect(screen.getByText('Officina Rossi')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });

  it('senza membership ripiega sul nome app', () => {
    setSession([]);
    renderSwitcher();

    expect(screen.getByText('Brumy')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('con più organizzazioni elenca nome e ruolo e segna quella attiva', async () => {
    const user = userEvent.setup();
    setSession([officina, famiglia], 2);
    renderSwitcher();

    await user.click(screen.getByRole('button', { name: 'Famiglia Neri, cambia organizzazione' }));

    const items = await screen.findAllByRole('menuitem');
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent('Officina Rossi');
    expect(items[0]).toHaveTextContent('Proprietario');
    expect(items[0]).not.toHaveAttribute('aria-current');
    expect(items[1]).toHaveTextContent('Famiglia Neri');
    expect(items[1]).toHaveTextContent('Membro');
    expect(items[1]).toHaveAttribute('aria-current', 'true');
    expect(items[1]).toHaveTextContent('Organizzazione attiva');
  });

  it("scegliere l'organizzazione attiva non fa nulla", async () => {
    const user = userEvent.setup();
    setSession([officina, famiglia], 1);
    renderSwitcher();

    await user.click(screen.getByRole('button', { name: /cambia organizzazione/ }));
    await user.click(await screen.findByRole('menuitem', { name: /Officina Rossi/ }));

    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('sceglierne un\'altra chiama switch-org, salva il token, svuota la cache e porta alla home', async () => {
    const user = userEvent.setup();
    setSession([officina, famiglia], 1);
    mockedAuthFetch.mockResolvedValue({ access_token: tokenFor(2) });
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    // Come in main.tsx: è la sessione che svuota la cache quando l'org del token cambia.
    const stop = clearQueryCacheOnSessionChange(qc);
    qc.setQueryData(['vehicles', 'list'], [{ id: 42 }]);
    renderSwitcher(qc);
    expect(screen.getByTestId('where')).toHaveTextContent('/vehicles/42');

    await user.click(screen.getByRole('button', { name: /cambia organizzazione/ }));
    await user.click(await screen.findByRole('menuitem', { name: /Famiglia Neri/ }));

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent(/^\/$/));
    expect(mockedAuthFetch).toHaveBeenCalledTimes(1);
    expect(mockedAuthFetch).toHaveBeenCalledWith(
      '/api/auth/switch-org',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ organizationId: 2 }) }),
    );
    expect(useAuthStore.getState().accessToken).toBe(tokenFor(2));
    expect(qc.getQueryData(['vehicles', 'list'])).toBeUndefined();
    // il nome in vista segue il nuovo claim
    expect(await screen.findByRole('button', { name: 'Famiglia Neri, cambia organizzazione' })).toBeInTheDocument();
    stop();
  });

  it("se il cambio fallisce mostra l'errore e resta nell'organizzazione corrente", async () => {
    const user = userEvent.setup();
    setSession([officina, famiglia], 1);
    mockedAuthFetch.mockRejectedValue(new ApiError('auth.not_member', 403));
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    qc.setQueryData(['vehicles', 'list'], [{ id: 42 }]);
    renderSwitcher(qc);

    await user.click(screen.getByRole('button', { name: /cambia organizzazione/ }));
    await user.click(await screen.findByRole('menuitem', { name: /Famiglia Neri/ }));

    expect(await screen.findByText('Non fai parte di questa organizzazione')).toBeInTheDocument();
    expect(useAuthStore.getState().accessToken).toBe(tokenFor(1));
    expect(qc.getQueryData(['vehicles', 'list'])).toEqual([{ id: 42 }]);
    expect(screen.getByTestId('where')).toHaveTextContent('/vehicles/42');
    expect(screen.getByRole('button', { name: 'Officina Rossi, cambia organizzazione' })).toBeInTheDocument();
  });

  it('un doppio click sulla voce invia una sola richiesta', async () => {
    const user = userEvent.setup();
    setSession([officina, famiglia], 1);
    mockedAuthFetch.mockResolvedValue({ access_token: tokenFor(2) });
    renderSwitcher();

    await user.click(screen.getByRole('button', { name: /cambia organizzazione/ }));
    await user.dblClick(await screen.findByRole('menuitem', { name: /Famiglia Neri/ }));

    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent(/^\/$/));
    expect(mockedAuthFetch).toHaveBeenCalledTimes(1);
  });

  it('si apre e si sceglie da tastiera', async () => {
    const user = userEvent.setup();
    setSession([officina, famiglia], 1);
    mockedAuthFetch.mockResolvedValue({ access_token: tokenFor(2) });
    renderSwitcher();

    screen.getByRole('button', { name: /cambia organizzazione/ }).focus();
    await user.keyboard('{Enter}');
    await screen.findAllByRole('menuitem');
    await user.keyboard('{ArrowDown}{Enter}');

    await waitFor(() => expect(mockedAuthFetch).toHaveBeenCalledTimes(1));
    expect(mockedAuthFetch).toHaveBeenCalledWith(
      '/api/auth/switch-org',
      expect.objectContaining({ body: JSON.stringify({ organizationId: 2 }) }),
    );
  });
});
