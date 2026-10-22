import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { VerifyEmailPage } from './VerifyEmailPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/verify-email?token=abc']}>
        <VerifyEmailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('VerifyEmailPage', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('verifica semplice: email confermata, si continua nell’app', async () => {
    mockedAuthFetch.mockResolvedValue({ emailChanged: false });
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Email confermata' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Continua' })).toHaveAttribute('href', '/');
  });

  it('cambio email confermato: spiega che le sessioni sono chiuse e porta al login', async () => {
    mockedAuthFetch.mockResolvedValue({ emailChanged: true });
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Email aggiornata' })).toBeInTheDocument();
    expect(screen.getByText(/accedi con la nuova email/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Vai al login' })).toHaveAttribute('href', '/login');
  });
});
