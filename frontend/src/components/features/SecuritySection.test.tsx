import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, renderHook, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { Toaster } from '@/components/ui/Toaster';
import { useToast } from '@/hooks/useToast';
import { useAuthStore } from '@/stores/useAuthStore';
import { testMe, testUser } from '@/test/authFixtures';
import { SecuritySection } from './SecuritySection';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const CODES = Array.from({ length: 10 }, (_, i) => `abcd-efgh-${String(i).padStart(4, '0')}`);
const SETUP = {
  secret: 'JBSWY3DPEHPK3PXP',
  otpauthUri: 'otpauth://totp/Brumy:anna%40test.it?secret=JBSWY3DPEHPK3PXP&issuer=Brumy',
  qrCode: 'data:image/svg+xml;base64,PHN2Zy8+',
};

function SectionFromStore() {
  const user = useAuthStore((s) => s.user);
  return user ? <SecuritySection user={user} /> : null;
}

function renderSection() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <SectionFromStore />
      <Toaster />
    </QueryClientProvider>,
  );
}

const calls = (path: string) => mockedAuthFetch.mock.calls.filter(([p]) => p === path);
const bodyOf = (path: string) => JSON.parse(String(calls(path)[0]?.[1]?.body)) as unknown;

describe('SecuritySection — verifica in due passaggi', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  afterEach(() => {
    cleanup();
    const { result } = renderHook(() => useToast());
    act(() => result.current.dismiss());
    vi.unstubAllGlobals();
  });

  it('attivazione: QR e chiave, poi password + codice, poi i codici da copiare e scaricare', async () => {
    useAuthStore.setState({ status: 'authenticated', user: testUser(), activeOrgId: 10 });
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/2fa/setup') return SETUP;
      if (path === '/api/auth/2fa/enable') return { backupCodes: CODES };
      if (path === '/api/auth/me') return testMe({ twoFactorEnabled: true, backupCodesRemaining: 10 });
      throw new Error(`unexpected fetch ${String(path)}`);
    });
    const createObjectURL = vi.fn(() => 'blob:codici');
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() }));
    const user = userEvent.setup();
    const writeText = vi.spyOn(navigator.clipboard, 'writeText');
    renderSection();

    expect(screen.getByText('Non attiva')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Verifica in due passaggi/ }));

    const dialog = await screen.findByRole('dialog');
    expect(await within(dialog).findByRole('img', { name: "Codice QR per l'app di autenticazione" })).toHaveAttribute('src', SETUP.qrCode);
    expect(within(dialog).getByText(SETUP.secret)).toBeInTheDocument();
    expect(within(dialog).getByRole('link', { name: "Apri nell'app di autenticazione" })).toHaveAttribute('href', SETUP.otpauthUri);

    await user.type(within(dialog).getByLabelText(/Password attuale/), 'segreta-123');
    await user.type(within(dialog).getByLabelText(/Codice di conferma/), '123456');
    await user.click(within(dialog).getByRole('button', { name: 'Attiva' }));

    const list = await within(dialog).findByRole('list', { name: 'Codici di recupero' });
    expect(within(list).getAllByRole('listitem').map((li) => li.textContent)).toEqual(CODES);
    expect(bodyOf('/api/auth/2fa/enable')).toEqual({ currentPassword: 'segreta-123', code: '123456' });

    await user.click(within(dialog).getByRole('button', { name: 'Copia' }));
    expect(writeText).toHaveBeenCalledWith(CODES.join('\n'));

    await user.click(within(dialog).getByRole('button', { name: 'Scarica' }));
    expect(createObjectURL).toHaveBeenCalledTimes(1);
    expect(createObjectURL.mock.calls[0]?.[0]).toBeInstanceOf(Blob);

    await user.click(within(dialog).getByRole('button', { name: 'Li ho salvati' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    // l'utente ricaricato da /me: la verifica risulta attiva, con i codici rimasti
    expect(screen.getByText('Attiva')).toBeInTheDocument();
    expect(screen.getByText('10 codici rimasti')).toBeInTheDocument();
  });

  it('un codice sbagliato (422) compare sul campo del codice', async () => {
    useAuthStore.setState({ status: 'authenticated', user: testUser(), activeOrgId: 10 });
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/2fa/setup') return SETUP;
      if (path === '/api/auth/2fa/enable') {
        throw new ApiError('validation_failed', 422, undefined, [{ field: 'code', message: 'two_factor.invalid_code' }]);
      }
      throw new Error(`unexpected fetch ${String(path)}`);
    });
    const user = userEvent.setup();
    renderSection();

    await user.click(screen.getByRole('button', { name: /Verifica in due passaggi/ }));
    const dialog = await screen.findByRole('dialog');
    await within(dialog).findByRole('img');
    await user.type(within(dialog).getByLabelText(/Password attuale/), 'segreta-123');
    await user.type(within(dialog).getByLabelText(/Codice di conferma/), '654321');
    await user.click(within(dialog).getByRole('button', { name: 'Attiva' }));

    expect(await within(dialog).findByText('Codice non valido')).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/Codice di conferma/)).toBeInvalid();
  });

  it('disattivazione: chiede password e codice (anche di recupero) e chiama disable', async () => {
    useAuthStore.setState({ status: 'authenticated', user: testUser({ twoFactorEnabled: true, backupCodesRemaining: 4 }), activeOrgId: 10 });
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/2fa/disable') return undefined;
      if (path === '/api/auth/me') return testMe();
      throw new Error(`unexpected fetch ${String(path)}`);
    });
    const user = userEvent.setup();
    renderSection();

    expect(screen.getByText('4 codici rimasti')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Verifica in due passaggi/ }));
    const dialog = await screen.findByRole('dialog', { name: 'Disattiva la verifica in due passaggi' });
    await user.type(within(dialog).getByLabelText(/Password attuale/), 'segreta-123');
    await user.type(within(dialog).getByLabelText(/Codice dell'app o di recupero/), 'ABCD EFGH 0003');
    await user.click(within(dialog).getByRole('button', { name: 'Disattiva' }));

    expect(await screen.findByText('Verifica in due passaggi disattivata')).toBeInTheDocument();
    expect(bodyOf('/api/auth/2fa/disable')).toEqual({ currentPassword: 'segreta-123', code: 'ABCD EFGH 0003' });
    await waitFor(() => expect(screen.getByText('Non attiva')).toBeInTheDocument());
  });

  it('disattivazione con password sbagliata: errore sul campo password, nessun toast', async () => {
    useAuthStore.setState({ status: 'authenticated', user: testUser({ twoFactorEnabled: true, backupCodesRemaining: 4 }), activeOrgId: 10 });
    mockedAuthFetch.mockRejectedValue(new ApiError('auth.invalid_current_password', 400));
    const user = userEvent.setup();
    renderSection();

    await user.click(screen.getByRole('button', { name: /Verifica in due passaggi/ }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/Password attuale/), 'sbagliata');
    await user.type(within(dialog).getByLabelText(/Codice dell'app o di recupero/), '123456');
    await user.click(within(dialog).getByRole('button', { name: 'Disattiva' }));

    expect(await within(dialog).findByText('La password attuale non è corretta')).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/Password attuale/)).toBeInvalid();
  });

  it('codici nuovi: password + codice, poi la lista nuova', async () => {
    useAuthStore.setState({ status: 'authenticated', user: testUser({ twoFactorEnabled: true, backupCodesRemaining: 1 }), activeOrgId: 10 });
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/2fa/backup-codes') return { backupCodes: CODES };
      if (path === '/api/auth/me') return testMe({ twoFactorEnabled: true, backupCodesRemaining: 10 });
      throw new Error(`unexpected fetch ${String(path)}`);
    });
    const user = userEvent.setup();
    renderSection();

    expect(screen.getByText('1 codice rimasto')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Codici di recupero/ }));
    const dialog = await screen.findByRole('dialog', { name: 'Genera nuovi codici' });
    await user.type(within(dialog).getByLabelText(/Password attuale/), 'segreta-123');
    await user.type(within(dialog).getByLabelText(/Codice dell'app o di recupero/), '123456');
    await user.click(within(dialog).getByRole('button', { name: 'Genera nuovi codici' }));

    expect(await within(dialog).findByRole('list', { name: 'Codici di recupero' })).toBeInTheDocument();
    expect(bodyOf('/api/auth/2fa/backup-codes')).toEqual({ currentPassword: 'segreta-123', code: '123456' });
  });
});
