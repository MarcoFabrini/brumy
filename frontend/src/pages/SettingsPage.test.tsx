import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { useThemeStore } from '@/stores/useThemeStore';
import { SettingsPage } from './SettingsPage';

// Nell'ambiente di test `localStorage` non esiste e il middleware persist di useThemeStore fallirebbe al primo set:
// lo si finge prima che i moduli vengano importati.
vi.hoisted(() => {
  const memory = new Map<string, string>();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (k: string) => memory.get(k) ?? null,
      setItem: (k: string, v: string) => void memory.set(k, v),
      removeItem: (k: string) => void memory.delete(k),
    },
  });
});

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
  memberships: [{ id: 1, role: 'member', organization: { id: 1, name: 'Officina', slug: 'officina' } }],
};

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <SettingsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const logoutCalls = () => mockedAuthFetch.mock.calls.filter(([path]) => path === '/api/auth/logout');

describe('SettingsPage — logout', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'authenticated', accessToken: 'tok', user });
  });

  it('un doppio tap non ripete il logout: il pulsante è disattivato finché è in corso', async () => {
    let finishLogout: () => void = () => {};
    mockedAuthFetch.mockImplementation(async (path) => {
      if (path === '/api/auth/logout') {
        await new Promise<void>((resolve) => {
          finishLogout = resolve;
        });
        return undefined;
      }
      // le altre card della pagina non interessano a questo test
      throw new ApiError('http.404', 404);
    });
    const u = userEvent.setup();
    renderPage();

    const button = screen.getByRole('button', { name: 'Esci' });
    await u.click(button);
    await u.click(button);

    await waitFor(() => expect(logoutCalls()).toHaveLength(1));
    expect(button).toBeDisabled();

    finishLogout();
    await waitFor(() => expect(useAuthStore.getState().status).toBe('unauthenticated'));
    expect(logoutCalls()).toHaveLength(1);
  });
});

describe('SettingsPage — semantica', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    mockedAuthFetch.mockRejectedValue(new ApiError('http.404', 404));
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'authenticated', accessToken: 'tok', user });
  });

  it('ha esattamente un h1 e le sezioni raggruppate come h2', () => {
    renderPage();

    const h1 = screen.getAllByRole('heading', { level: 1 });
    expect(h1).toHaveLength(1);
    expect(h1[0]).toHaveTextContent('Profilo');
    const h2 = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(h2).toEqual(expect.arrayContaining(['Profilo', 'Organizzazione', 'Preferenze']));
  });

  it('le righe di account aprono la modifica del profilo e il cambio password', async () => {
    renderPage();

    await userEvent.click(screen.getByRole('button', { name: 'Cambia password' }));
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
  });

  it('la riga dei valori ricorrenti porta a /settings/values', () => {
    renderPage();

    expect(screen.getByRole('link', { name: /Elenchi personali/ })).toHaveAttribute(
      'href',
      '/settings/values',
    );
  });

  it('i select di lingua e tema hanno un nome accessibile', () => {
    renderPage();

    expect(screen.getByRole('combobox', { name: 'Lingua' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Tema' })).toBeInTheDocument();
  });
});

describe('SettingsPage — testo grande e organizzazione', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    mockedAuthFetch.mockRejectedValue(new ApiError('http.404', 404));
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'authenticated', accessToken: 'tok', user });
    useThemeStore.setState({ largeText: false });
  });

  it("l'interruttore Testo grande accende la preferenza (lo zoom a pizzico è spento)", async () => {
    renderPage();

    const sw = screen.getByRole('switch', { name: 'Testo grande' });
    expect(sw).toHaveAttribute('aria-checked', 'false');
    await userEvent.click(sw);
    expect(useThemeStore.getState().largeText).toBe(true);
  });

  it("con più organizzazioni il cambio si fa dal Profilo (su mobile non c'è più la barra in alto)", () => {
    useAuthStore.setState({
      user: {
        ...user,
        memberships: [
          ...user.memberships,
          { id: 2, role: 'owner', organization: { id: 2, name: 'Famiglia', slug: 'famiglia' } },
        ],
      },
    });
    renderPage();

    expect(screen.getByText('Cambia organizzazione')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /cambia organizzazione/ })).toBeInTheDocument();
  });
});
