import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { SettingsPage } from './SettingsPage';

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

  it('ha esattamente un h1 e le card come h2', () => {
    renderPage();

    const h1 = screen.getAllByRole('heading', { level: 1 });
    expect(h1).toHaveLength(1);
    expect(h1[0]).toHaveTextContent('Impostazioni');
    const h2 = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(h2).toEqual(expect.arrayContaining(['Profilo', 'Sicurezza', 'Preferenze']));
  });

  it('i select di lingua e tema hanno un nome accessibile', () => {
    renderPage();

    expect(screen.getByRole('combobox', { name: 'Lingua' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Tema' })).toBeInTheDocument();
  });
});
