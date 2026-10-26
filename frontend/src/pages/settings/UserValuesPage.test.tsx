import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { UserValuesPage } from './UserValuesPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const counts: Record<string, number> = { station: 3, workshop: 0, shop: 2, expense_category: 13 };

describe('UserValuesPage', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    mockedAuthFetch.mockImplementation(async (path) => {
      const kind = /^\/api\/values\/(\w+)$/.exec(path)?.[1] ?? '';
      return Array.from({ length: counts[kind] ?? 0 }, (_, i) => ({ id: i + 1, name: `Voce ${i}`, usageCount: 0, followsPrice: false }));
    });
    await i18n.changeLanguage('it');
  });

  it('ha una riga per elenco, con il numero di voci, che apre la sua pagina', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <UserValuesPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Elenchi personali');
    const rows = [
      ['Distributori', 'station', '3'],
      ['Officine', 'workshop', '0'],
      ['Negozi', 'shop', '2'],
      ['Categorie spese', 'expense_category', '13'],
    ] as const;
    for (const [label, kind, count] of rows) {
      const link = await screen.findByRole('link', { name: new RegExp(`${label}.*${count}`) });
      expect(link).toHaveAttribute('href', `/settings/values/${kind}`);
    }
  });
});
