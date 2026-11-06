import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render as rtlRender, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { stubLocalStorage } from '@/test/memoryStorage';
import { ReleaseBanner } from './ReleaseBanner';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

function render() {
  return rtlRender(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ReleaseBanner />
    </QueryClientProvider>,
  );
}

const newer = (version: string) => ({
  version: '0.8.1',
  update: { version, url: `https://github.com/MarcoFabrini/brumy/releases/tag/v${version}` },
});

describe('ReleaseBanner', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
    mockedAuthFetch.mockReset();
    stubLocalStorage();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("all'amministratore annuncia la versione uscita su GitHub con il link alle novità", async () => {
    mockedAuthFetch.mockResolvedValue(newer('0.9.0'));
    render();

    expect(await screen.findByText(/È uscita Brumy 0\.9\.0/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Novità' })).toHaveAttribute(
      'href',
      'https://github.com/MarcoFabrini/brumy/releases/tag/v0.9.0',
    );
  });

  it('chiuso resta chiuso per quella versione (ricordato su questo dispositivo)', async () => {
    mockedAuthFetch.mockResolvedValue(newer('0.9.0'));
    render();

    await userEvent.click(await screen.findByRole('button', { name: 'Chiudi' }));

    expect(screen.queryByText(/È uscita Brumy/)).not.toBeInTheDocument();
    expect(localStorage.getItem('brumy.release.dismissed')).toBe('0.9.0');
  });

  it('chiuso per una versione, non compare al riavvio per la stessa', async () => {
    localStorage.setItem('brumy.release.dismissed', '0.9.0');
    mockedAuthFetch.mockResolvedValue(newer('0.9.0'));
    const { container } = render();

    await vi.waitFor(() => expect(mockedAuthFetch).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  it('per una versione più nuova ricompare anche dopo averlo chiuso', async () => {
    localStorage.setItem('brumy.release.dismissed', '0.9.0');
    mockedAuthFetch.mockResolvedValue(newer('0.9.1'));
    render();

    expect(await screen.findByText(/È uscita Brumy 0\.9\.1/)).toBeInTheDocument();
  });

  it('senza aggiornamenti (o per chi non è amministratore) non mostra niente', async () => {
    mockedAuthFetch.mockResolvedValue({ version: '0.8.1', update: null });
    const { container } = render();

    await vi.waitFor(() => expect(mockedAuthFetch).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });
});
