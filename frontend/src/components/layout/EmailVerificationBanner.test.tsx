import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { Toaster } from '@/components/ui';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { EmailVerificationBanner } from './EmailVerificationBanner';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const unverified: User = {
  id: 1,
  email: 'anna@test.it',
  firstName: 'Anna',
  lastName: 'Neri',
  locale: 'it',
  hasAvatar: false,
  emailVerified: false,
  pendingEmail: null,
  isInstanceAdmin: false,
  memberships: [],
};

function renderBanner() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <EmailVerificationBanner />
      <Toaster />
    </QueryClientProvider>,
  );
}

describe('EmailVerificationBanner — rinvio', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'authenticated', accessToken: 'tok', user: unverified });
  });

  it('il pulsante di rinvio ha un bersaglio da 44px', () => {
    renderBanner();

    // jsdom non ha layout: si verifica la classe che garantisce i 44px.
    const button = screen.getByRole('button', { name: 'Rinvia email' });
    expect(button).toHaveClass('min-h-touch', 'min-w-touch');
  });

  it('rinvio riuscito: toast di conferma', async () => {
    mockedAuthFetch.mockResolvedValue(undefined);
    const user = userEvent.setup();
    renderBanner();

    await user.click(screen.getByRole('button', { name: 'Rinvia email' }));

    expect(await screen.findByText(i18n.t('auth.verify.resent'))).toBeInTheDocument();
  });

  it('un errore (429) mostra un toast invece di tacere', async () => {
    mockedAuthFetch.mockRejectedValue(new ApiError('http.429', 429));
    const user = userEvent.setup();
    renderBanner();

    await user.click(screen.getByRole('button', { name: 'Rinvia email' }));

    expect(await screen.findByText('Troppe richieste: riprova tra qualche istante')).toBeInTheDocument();
  });
});

describe('EmailVerificationBanner — cambio email in attesa', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it("mostra il nuovo indirizzo da confermare anche se l'email attuale è verificata", () => {
    useAuthStore.setState({
      status: 'authenticated',
      accessToken: 'tok',
      user: { ...unverified, emailVerified: true, pendingEmail: 'nuova@test.it' },
    });
    renderBanner();

    expect(screen.getByText(/Conferma il nuovo indirizzo nuova@test\.it/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Rinvia email' })).toBeInTheDocument();
  });

  it('non compare con email verificata e nessun cambio in attesa', () => {
    useAuthStore.setState({ status: 'authenticated', accessToken: 'tok', user: { ...unverified, emailVerified: true } });
    renderBanner();

    expect(screen.queryByRole('button', { name: 'Rinvia email' })).not.toBeInTheDocument();
  });
});
