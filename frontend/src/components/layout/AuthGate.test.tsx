import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import i18n from '@/i18n';
import { authFetch, refreshAccessToken, type RefreshResult } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { AuthGate } from './AuthGate';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
  refreshAccessToken: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);
const mockedRefresh = vi.mocked(refreshAccessToken);

const me: User = {
  id: 1,
  email: 'anna@test.it',
  firstName: 'Anna',
  lastName: 'Neri',
  locale: 'it',
  hasAvatar: false,
  emailVerified: true,
  pendingEmail: null,
  isInstanceAdmin: false,
  memberships: [],
};

function renderGate() {
  return render(
    <AuthGate>
      <p>Contenuto dell'app</p>
    </AuthGate>,
  );
}

describe('AuthGate', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    mockedRefresh.mockReset();
    await i18n.changeLanguage('it');
    // useThemeEffect (dentro AuthGate) legge prefers-color-scheme: jsdom non ha matchMedia
    vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
    useAuthStore.setState({ status: 'loading', accessToken: null, user: null });
  });

  it("finché il refresh è in corso mostra solo lo spinner: l'app (e il redirect al login) non compaiono", () => {
    mockedRefresh.mockReturnValue(new Promise<RefreshResult>(() => {}));

    renderGate();

    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByText("Contenuto dell'app")).not.toBeInTheDocument();
  });

  it('con sessione ripristinata mostra i figli', async () => {
    mockedRefresh.mockImplementation(async () => {
      useAuthStore.getState().setAccessToken('tok');
      return 'ok';
    });
    mockedAuthFetch.mockResolvedValue(me);

    renderGate();

    expect(await screen.findByText("Contenuto dell'app")).toBeInTheDocument();
  });

  it('senza sessione mostra comunque i figli: sarà il router a mandare al login', async () => {
    mockedRefresh.mockResolvedValue('invalid');

    renderGate();

    expect(await screen.findByText("Contenuto dell'app")).toBeInTheDocument();
    expect(useAuthStore.getState().status).toBe('unauthenticated');
  });

  it('server irraggiungibile: schermata con "Riprova" al posto dell\'app, e riprovando si entra', async () => {
    mockedRefresh.mockResolvedValueOnce('unavailable');
    const user = userEvent.setup();
    renderGate();

    expect(await screen.findByText('Server non raggiungibile')).toBeInTheDocument();
    expect(screen.queryByText("Contenuto dell'app")).not.toBeInTheDocument();

    mockedRefresh.mockImplementation(async () => {
      useAuthStore.getState().setAccessToken('tok');
      return 'ok';
    });
    mockedAuthFetch.mockResolvedValue(me);
    await user.click(screen.getByRole('button', { name: 'Riprova' }));

    expect(await screen.findByText("Contenuto dell'app")).toBeInTheDocument();
  });
});
