import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, cleanup, render, renderHook, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { Toaster } from '@/components/ui/Toaster';
import { useToast } from '@/hooks/useToast';
import { useAuthStore } from '@/stores/useAuthStore';
import { ChangePasswordDialog } from './ChangePasswordDialog';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

function renderDialog(onOpenChange = vi.fn()) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <ChangePasswordDialog open onOpenChange={onOpenChange} />
      <Toaster />
    </QueryClientProvider>,
  );
  return onOpenChange;
}

async function fill(user: ReturnType<typeof userEvent.setup>, current: string, next: string, confirm = next) {
  await user.type(screen.getByLabelText(/Password attuale/), current);
  await user.type(screen.getByLabelText(/^Nuova password/), next);
  await user.type(screen.getByLabelText(/Conferma password/), confirm);
  await user.click(screen.getByRole('button', { name: 'Cambia password' }));
}

describe('ChangePasswordDialog', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'authenticated', user: null, activeOrgId: 1 });
  });

  afterEach(() => {
    // I toast stanno in uno store globale di modulo: si chiudono perché non passino al test dopo
    cleanup();
    const { result } = renderHook(() => useToast());
    act(() => result.current.dismiss());
  });

  it('con conferma diversa mostra l\'errore sul campo e non chiama il server', async () => {
    const user = userEvent.setup();
    renderDialog();

    await fill(user, 'attuale-123', 'nuova-password-1', 'nuova-password-2');

    expect(await screen.findByText('Le password non coincidono')).toBeInTheDocument();
    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('con una nuova password troppo corta non chiama il server', async () => {
    const user = userEvent.setup();
    renderDialog();

    await fill(user, 'attuale-123', 'corta');

    await waitFor(() => expect(screen.getByLabelText(/^Nuova password/)).toBeInvalid());
    expect(mockedAuthFetch).not.toHaveBeenCalled();
  });

  it('invia solo password attuale e nuova (la conferma resta nel browser), poi chiude e avvisa', async () => {
    mockedAuthFetch.mockResolvedValue(undefined); // 204: la sessione resta questa
    const user = userEvent.setup();
    const onOpenChange = renderDialog();

    await fill(user, 'attuale-123', 'nuova-password-1');

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false));
    const [path, init] = mockedAuthFetch.mock.calls[0] ?? [];
    expect(path).toBe('/api/auth/password');
    expect(init?.method).toBe('PUT');
    expect(JSON.parse(String(init?.body))).toEqual({ currentPassword: 'attuale-123', newPassword: 'nuova-password-1' });
    expect(useAuthStore.getState().status).toBe('authenticated');
    expect(await screen.findByText('Password aggiornata')).toBeInTheDocument();
  });

  it('password attuale errata: errore sul campo giusto, il dialogo resta aperto e la sessione non cambia', async () => {
    mockedAuthFetch.mockRejectedValue(new ApiError('auth.invalid_current_password', 400));
    const user = userEvent.setup();
    const onOpenChange = renderDialog();

    await fill(user, 'sbagliata-1', 'nuova-password-1');

    expect(await screen.findByText('La password attuale non è corretta')).toBeInTheDocument();
    expect(screen.getByLabelText(/Password attuale/)).toBeInvalid();
    expect(onOpenChange).not.toHaveBeenCalledWith(false);
    expect(useAuthStore.getState().status).toBe('authenticated');
    expect(screen.queryByText('Password aggiornata')).not.toBeInTheDocument();
  });

  it('un errore diverso non si attacca a un campo: avviso generico, dialogo aperto', async () => {
    mockedAuthFetch.mockRejectedValue(new ApiError('http.500', 500));
    const user = userEvent.setup();
    const onOpenChange = renderDialog();

    await fill(user, 'attuale-123', 'nuova-password-1');

    expect(await screen.findByText('Errore server')).toBeInTheDocument();
    expect(screen.getByLabelText(/Password attuale/)).toBeValid();
    expect(onOpenChange).not.toHaveBeenCalledWith(false);
  });
});
