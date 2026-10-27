import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { useAuthStore } from '@/stores/useAuthStore';
import { testMe } from '@/test/authFixtures';
import { AuthGate } from './AuthGate';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const me = testMe({ email: 'anna@test.it', firstName: 'Anna', lastName: 'Neri', memberships: [], activeOrganizationId: null });

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
    await i18n.changeLanguage('it');
    // useThemeEffect (dentro AuthGate) legge prefers-color-scheme: jsdom non ha matchMedia
    vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
    useAuthStore.setState({ status: 'loading', user: null, activeOrgId: null });
  });

  it("finché /me è in corso mostra solo lo spinner: l'app (e il redirect al login) non compaiono", () => {
    mockedAuthFetch.mockReturnValue(new Promise(() => {}));

    renderGate();

    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(screen.queryByText("Contenuto dell'app")).not.toBeInTheDocument();
  });

  it('con sessione ripristinata mostra i figli', async () => {
    mockedAuthFetch.mockResolvedValue(me);

    renderGate();

    expect(await screen.findByText("Contenuto dell'app")).toBeInTheDocument();
  });

  it('senza sessione mostra comunque i figli: sarà il router a mandare al login', async () => {
    mockedAuthFetch.mockRejectedValue(new ApiError('http.401', 401));

    renderGate();

    expect(await screen.findByText("Contenuto dell'app")).toBeInTheDocument();
    expect(useAuthStore.getState().status).toBe('unauthenticated');
  });

  it('server irraggiungibile: schermata con "Riprova" al posto dell\'app, e riprovando si entra', async () => {
    mockedAuthFetch.mockRejectedValueOnce(new TypeError('Failed to fetch'));
    const user = userEvent.setup();
    renderGate();

    expect(await screen.findByText('Server non raggiungibile')).toBeInTheDocument();
    expect(screen.queryByText("Contenuto dell'app")).not.toBeInTheDocument();

    mockedAuthFetch.mockResolvedValue(me);
    await user.click(screen.getByRole('button', { name: 'Riprova' }));

    expect(await screen.findByText("Contenuto dell'app")).toBeInTheDocument();
  });
});
