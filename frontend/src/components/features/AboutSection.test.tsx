import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render as rtlRender, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { AboutSection } from './AboutSection';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

function render() {
  return rtlRender(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AboutSection />
    </QueryClientProvider>,
  );
}

describe('AboutSection', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
    mockedAuthFetch.mockReset();
  });

  it('mostra la versione installata, senza avviso se non ci sono aggiornamenti', async () => {
    mockedAuthFetch.mockResolvedValue({ version: '0.7.0', update: null });
    render();

    expect(await screen.findByText('0.7.0')).toBeInTheDocument();
    expect(screen.getByText('Versione')).toBeInTheDocument();
    expect(screen.queryByText('Aggiornamento disponibile')).not.toBeInTheDocument();
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/about');
  });

  it("all'amministratore mostra la versione nuova con il link alle note", async () => {
    mockedAuthFetch.mockResolvedValue({
      version: '0.7.0',
      update: { version: '0.7.1', url: 'https://github.com/MarcoFabrini/brumy/releases/tag/v0.7.1' },
    });
    render();

    const link = await screen.findByRole('link', { name: /Aggiornamento disponibile/ });
    expect(link).toHaveAttribute('href', 'https://github.com/MarcoFabrini/brumy/releases/tag/v0.7.1');
    expect(link).toHaveAttribute('target', '_blank');
    expect(link).toHaveTextContent('0.7.1');
  });

  it('una build senza versione di rilascio si chiama "sviluppo"', async () => {
    mockedAuthFetch.mockResolvedValue({ version: 'dev', update: null });
    render();

    expect(await screen.findByText('sviluppo')).toBeInTheDocument();
  });
});
