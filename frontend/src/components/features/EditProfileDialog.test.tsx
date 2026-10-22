import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { EditProfileDialog } from './EditProfileDialog';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
  authFetchBlob: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const baseUser: User = {
  id: 1,
  email: 'marco@test.it',
  firstName: 'Marco',
  lastName: 'Rossi',
  locale: 'it',
  hasAvatar: false,
  emailVerified: true,
  pendingEmail: null,
  isInstanceAdmin: false,
  memberships: [],
};

/** Come SettingsPage: `user` arriva dallo store, quindi cambia identità a ogni setUser. */
function Harness() {
  const user = useAuthStore((s) => s.user);
  const [open, setOpen] = useState(true);
  if (!user) return null;
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>
        riapri
      </button>
      <EditProfileDialog open={open} onOpenChange={setOpen} user={user} />
    </>
  );
}

function renderDialog() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <Harness />
    </QueryClientProvider>,
  );
}

describe('EditProfileDialog', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'authenticated', accessToken: 'tok', user: baseUser });
  });

  it("caricare la foto non cancella il nome digitato e non ancora salvato", async () => {
    mockedAuthFetch.mockResolvedValue({ hasAvatar: true });
    const user = userEvent.setup();
    renderDialog();

    const firstName = screen.getByLabelText(/Nome/);
    await user.clear(firstName);
    await user.type(firstName, 'Giorgio');

    await user.upload(
      document.querySelector<HTMLInputElement>('input[type="file"]')!,
      new File(['x'], 'foto.png', { type: 'image/png' }),
    );

    await waitFor(() => expect(useAuthStore.getState().user?.hasAvatar).toBe(true));
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/auth/avatar', expect.objectContaining({ method: 'POST' }));
    expect(screen.getByLabelText(/Nome/)).toHaveValue('Giorgio');
  });

  it('rimuovere la foto non cancella il nome digitato e non ancora salvato', async () => {
    useAuthStore.setState({ user: { ...baseUser, hasAvatar: true } });
    mockedAuthFetch.mockResolvedValue(undefined);
    const user = userEvent.setup();
    renderDialog();

    const firstName = screen.getByLabelText(/Nome/);
    await user.clear(firstName);
    await user.type(firstName, 'Giorgio');

    await user.click(screen.getByRole('button', { name: 'Rimuovi' }));

    await waitFor(() => expect(useAuthStore.getState().user?.hasAvatar).toBe(false));
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/auth/avatar', expect.objectContaining({ method: 'DELETE' }));
    expect(screen.getByLabelText(/Nome/)).toHaveValue('Giorgio');
  });

  it('riaprendo il dialog mostra i valori salvati, non le modifiche scartate', async () => {
    const user = userEvent.setup();
    renderDialog();

    const firstName = screen.getByLabelText(/Nome/);
    await user.clear(firstName);
    await user.type(firstName, 'Giorgio');

    await user.click(screen.getByRole('button', { name: 'Annulla' }));
    expect(screen.queryByLabelText(/Nome/)).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'riapri' }));
    expect(await screen.findByLabelText(/Nome/)).toHaveValue('Marco');
  });

  it('con un cambio email in attesa lo mostra sotto il campo email', () => {
    useAuthStore.setState({ user: { ...baseUser, pendingEmail: 'nuova@test.it' } });
    renderDialog();

    expect(screen.getByText('In attesa di conferma: nuova@test.it')).toBeInTheDocument();
  });

  it("salvare una nuova email la lascia in attesa: l'email dell'account non cambia", async () => {
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/profile') return { access_token: 'tok-stessa-scadenza', user: { ...baseUser, pendingEmail: 'nuova@test.it' } };
      if (path === '/api/auth/me') return { ...baseUser, pendingEmail: 'nuova@test.it' };
      throw new Error(`unexpected fetch ${String(path)}`);
    });
    const user = userEvent.setup();
    renderDialog();

    const email = screen.getByLabelText(/Email/);
    await user.clear(email);
    await user.type(email, 'nuova@test.it');
    await user.type(screen.getByLabelText(/Password attuale/), 'segreta-123');
    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => expect(useAuthStore.getState().user?.pendingEmail).toBe('nuova@test.it'));
    expect(useAuthStore.getState().user?.email).toBe('marco@test.it');
    expect(mockedAuthFetch).toHaveBeenCalledWith(
      '/api/auth/profile',
      expect.objectContaining({ method: 'PUT', body: expect.stringContaining('"currentPassword":"segreta-123"') }),
    );
  });
});
