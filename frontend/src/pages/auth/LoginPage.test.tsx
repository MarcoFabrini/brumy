import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { LoginPage } from './LoginPage';
import { ApiError, authFetch } from '@/api/client';
import { useAuthStore } from '@/stores/useAuthStore';
import { testMe } from '@/test/authFixtures';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

function renderLogin() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/** Il link "Registrati" compare solo a istanza vuota (primo avvio): poi si entra su invito. */
describe('LoginPage — link di registrazione', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('istanza vuota: mostra il link Registrati', async () => {
    mockedAuthFetch.mockResolvedValue({ open: true });
    renderLogin();

    expect(await screen.findByRole('link', { name: 'Registrati' })).toHaveAttribute('href', '/register');
  });

  it('istanza con utenti: nessun link né testo di registrazione', async () => {
    mockedAuthFetch.mockResolvedValue({ open: false });
    renderLogin();

    await waitFor(() => expect(mockedAuthFetch).toHaveBeenCalledWith('/api/auth/registration', { anonymous: true }));
    expect(screen.queryByRole('link', { name: 'Registrati' })).not.toBeInTheDocument();
    expect(screen.queryByText(/Non hai un account/)).not.toBeInTheDocument();
  });

  it('stato non verificabile (errore): il link resta nascosto', async () => {
    mockedAuthFetch.mockRejectedValue(new Error('rete'));
    renderLogin();

    await waitFor(() => expect(mockedAuthFetch).toHaveBeenCalled());
    expect(screen.queryByRole('link', { name: 'Registrati' })).not.toBeInTheDocument();
  });

  it('mentre controlla non mostra il link (niente flash su istanza già chiusa)', () => {
    mockedAuthFetch.mockReturnValue(new Promise(() => {}));
    renderLogin();

    expect(screen.queryByRole('link', { name: 'Registrati' })).not.toBeInTheDocument();
  });
});

describe('LoginPage — invio', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/registration') return { open: false };
      throw new Error('login fallito');
    });
  });

  function loginCalls() {
    return mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/auth/login');
  }

  it("invia l'email ripulita dagli spazi", async () => {
    const user = userEvent.setup();
    renderLogin();

    await user.type(screen.getByLabelText(/^Email/), '  marco@test.it  ');
    await user.type(screen.getByLabelText(/^Password/), 'segreta');
    await user.click(screen.getByRole('button', { name: 'Entra' }));

    await waitFor(() => expect(loginCalls()).toHaveLength(1));
    expect(JSON.parse(String(loginCalls()[0]?.[1]?.body))).toEqual({ email: 'marco@test.it', password: 'segreta' });
  });

  it('con campi vuoti non chiama il server e segnala i campi obbligatori', async () => {
    const user = userEvent.setup();
    renderLogin();

    await user.click(screen.getByRole('button', { name: 'Entra' }));

    expect(await screen.findByText('Inserisci la password')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Email/)).toBeInvalid();
    expect(loginCalls()).toHaveLength(0);
  });

  it("un'email di soli spazi è considerata vuota", async () => {
    const user = userEvent.setup();
    renderLogin();

    await user.type(screen.getByLabelText(/^Email/), '   ');
    await user.type(screen.getByLabelText(/^Password/), 'segreta');
    await user.click(screen.getByRole('button', { name: 'Entra' }));

    expect(await screen.findByText(i18n.t('errors.account.email.required'))).toBeInTheDocument();
    expect(loginCalls()).toHaveLength(0);
  });
});

describe('LoginPage — bersagli touch', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('"Password dimenticata?" e "Registrati" raggiungono i 44px (jsdom non ha layout: si verifica la classe)', async () => {
    mockedAuthFetch.mockResolvedValue({ open: true });
    renderLogin();

    expect(await screen.findByRole('link', { name: 'Registrati' })).toHaveClass('min-h-touch', 'min-w-touch');
    expect(screen.getByRole('link', { name: 'Password dimenticata?' })).toHaveClass('min-h-touch', 'min-w-touch');
  });
});

describe('LoginPage — verifica in due passaggi', () => {
  const me = testMe({ id: 3, email: 'anna@test.it', activeOrganizationId: 10 });

  function renderWithHome() {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/login']}>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/" element={<p>HOME</p>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
  }

  const calls = (path: string) => mockedAuthFetch.mock.calls.filter(([p]) => p === path);

  /** Backend con la verifica attiva: il codice giusto è 123456 (o il codice di recupero abcd-efgh-jkmn). */
  function mockBackend() {
    mockedAuthFetch.mockImplementation(async (path, init) => {
      if (path === '/api/auth/registration') return { open: false };
      if (path === '/api/auth/login') return { twoFactorRequired: true };
      if (path === '/api/auth/2fa/check') {
        const { authCode } = JSON.parse(String(init?.body)) as { authCode: string };
        if (authCode === '123456' || authCode === 'abcd-efgh-jkmn') return { twoFactorRequired: false };
        throw new ApiError('auth.invalid_two_factor_code', 401);
      }
      if (path === '/api/auth/logout') return undefined;
      if (path === '/api/auth/me') return me;
      throw new Error(`unexpected fetch ${String(path)}`);
    });
  }

  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'unauthenticated', user: null, activeOrgId: null });
    mockBackend();
  });

  async function passwordStep(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText(/^Email/), 'anna@test.it');
    await user.type(screen.getByLabelText(/^Password/), 'segreta');
    await user.click(screen.getByRole('button', { name: 'Entra' }));
    return screen.findByLabelText(/^Codice/);
  }

  it('dopo la password chiede il codice dell\'app, con focus e tastiera numerica', async () => {
    const user = userEvent.setup();
    renderWithHome();

    const code = await passwordStep(user);

    expect(useAuthStore.getState().status).toBe('two_factor');
    expect(code).toHaveFocus();
    expect(code).toHaveAttribute('autocomplete', 'one-time-code');
    expect(code).toHaveAttribute('inputmode', 'numeric');
    expect(screen.getByText("Inserisci il codice a 6 cifre dell'app di autenticazione")).toBeInTheDocument();
    expect(calls('/api/auth/me')).toHaveLength(0);
  });

  it('il codice giusto chiama il check, poi /me, e porta alla home', async () => {
    const user = userEvent.setup();
    renderWithHome();
    await passwordStep(user);

    await user.type(screen.getByLabelText(/^Codice/), '123456');
    await user.click(screen.getByRole('button', { name: 'Verifica' }));

    expect(await screen.findByText('HOME')).toBeInTheDocument();
    expect(JSON.parse(String(calls('/api/auth/2fa/check')[0]?.[1]?.body))).toEqual({ authCode: '123456' });
    expect(calls('/api/auth/me')).toHaveLength(1);
    expect(useAuthStore.getState()).toMatchObject({ status: 'authenticated', activeOrgId: 10 });
  });

  it('un codice sbagliato mostra "Codice non valido" sul campo', async () => {
    const user = userEvent.setup();
    renderWithHome();
    await passwordStep(user);

    await user.type(screen.getByLabelText(/^Codice/), '000000');
    await user.click(screen.getByRole('button', { name: 'Verifica' }));

    expect(await screen.findByText('Codice non valido')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Codice/)).toBeInvalid();
    expect(useAuthStore.getState().status).toBe('two_factor');
  });

  it('un codice che non ha 6 cifre non va al server', async () => {
    const user = userEvent.setup();
    renderWithHome();
    await passwordStep(user);

    await user.type(screen.getByLabelText(/^Codice/), '12a');
    await user.click(screen.getByRole('button', { name: 'Verifica' }));

    expect(await screen.findByText('Il codice ha 6 cifre')).toBeInTheDocument();
    expect(calls('/api/auth/2fa/check')).toHaveLength(0);
  });

  it('"Usa un codice di recupero" passa a un campo testuale e lo accetta', async () => {
    const user = userEvent.setup();
    renderWithHome();
    await passwordStep(user);

    await user.click(screen.getByRole('button', { name: 'Usa un codice di recupero' }));
    const recovery = screen.getByLabelText(/^Codice di recupero/);
    expect(recovery).toHaveFocus();
    expect(recovery).toHaveAttribute('autocomplete', 'off');
    expect(recovery).not.toHaveAttribute('inputmode');
    expect(screen.getByRole('button', { name: "Usa l'app di autenticazione" })).toBeInTheDocument();

    await user.type(recovery, 'abcd-efgh-jkmn');
    await user.click(screen.getByRole('button', { name: 'Verifica' }));

    expect(await screen.findByText('HOME')).toBeInTheDocument();
  });

  it('"Annulla" chiama il logout e torna al primo passo', async () => {
    const user = userEvent.setup();
    renderWithHome();
    await passwordStep(user);

    await user.click(screen.getByRole('button', { name: "Annulla e torna all'accesso" }));

    expect(await screen.findByRole('button', { name: 'Entra' })).toBeInTheDocument();
    expect(calls('/api/auth/logout')).toHaveLength(1);
    expect(useAuthStore.getState().status).toBe('unauthenticated');
  });

  it('dopo un reload a verifica in corso (store in two_factor) mostra subito il secondo passo', () => {
    useAuthStore.setState({ status: 'two_factor' });
    renderWithHome();

    expect(screen.getByLabelText(/^Codice/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/^Password/)).not.toBeInTheDocument();
  });
});
