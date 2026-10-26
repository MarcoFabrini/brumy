import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { Toaster } from '@/components/ui';
import { UserValueListPage } from './UserValueListPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const lists: Record<string, { id: number; name: string; usageCount: number; followsPrice: boolean }[]> = {
  station: [
    { id: 7, name: 'Eni', usageCount: 1, followsPrice: false },
    { id: 9, name: 'Q8', usageCount: 0, followsPrice: false },
    { id: 10, name: 'Tamoil', usageCount: 3, followsPrice: false },
  ],
  workshop: [],
  shop: [],
  expense_category: [{ id: 21, name: 'Bollo', usageCount: 0, followsPrice: false }],
};

function mockApi() {
  mockedAuthFetch.mockImplementation(async (path, init) => {
    const method = init?.method ?? 'GET';
    const list = /^\/api\/values\/(station|workshop|shop|expense_category)$/.exec(path);
    if (method === 'GET' && list) return lists[list[1]!];
    if (method === 'POST' && list) return { id: 99, name: 'Nuova', usageCount: 0, followsPrice: false };
    if (method === 'PATCH') return { id: 7, name: 'Eni Station', usageCount: 1, followsPrice: false };
    return undefined;
  });
}

const writes = () => mockedAuthFetch.mock.calls.filter(([, init]) => init?.method && init.method !== 'GET');

function renderPage(kind: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/settings/values/${kind}`]}>
        <Routes>
          <Route path="/settings/values" element={<h1>Elenchi</h1>} />
          <Route path="/settings/values/:kind" element={<UserValueListPage />} />
        </Routes>
      </MemoryRouter>
      <Toaster />
    </QueryClientProvider>,
  );
}

describe('UserValueListPage', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    mockApi();
    await i18n.changeLanguage('it');
    // Radix Select in jsdom: mancano alcune API del puntatore
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => undefined;
    Element.prototype.releasePointerCapture = () => undefined;
    Element.prototype.scrollIntoView = () => undefined;
  });

  it("mostra le voci di un solo tipo con i conteggi d'uso", async () => {
    renderPage('station');

    expect(await screen.findByRole('button', { name: /Eni/ })).toHaveTextContent('usata 1 volta');
    expect(screen.getByRole('button', { name: /Tamoil/ })).toHaveTextContent('usata 3 volte');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Distributori');
    expect(screen.queryByText('Bollo')).not.toBeInTheDocument();
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/values/station');
  });

  it('un elenco vuoto lo dice', async () => {
    renderPage('workshop');

    expect(await screen.findByText('Ancora vuoto: si riempie quando salvi')).toBeInTheDocument();
  });

  it('un tipo sconosciuto torna agli elenchi', async () => {
    renderPage('pizza');

    expect(await screen.findByRole('heading', { name: 'Elenchi' })).toBeInTheDocument();
  });

  it('rinomina una voce con PATCH /api/values/7', async () => {
    const u = userEvent.setup();
    renderPage('station');

    await u.click(await screen.findByRole('button', { name: /Eni/ }));
    const dialog = await screen.findByRole('dialog');
    const input = within(dialog).getByLabelText(/Nome/);
    await u.clear(input);
    await u.type(input, 'Eni Station');
    await u.click(within(dialog).getByRole('button', { name: 'Salva' }));

    await waitFor(() =>
      expect(writes()).toContainEqual([
        '/api/values/7',
        expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ name: 'Eni Station' }) }),
      ]),
    );
  });

  it("l'unione chiede conferma e invia POST /api/values/7/merge", async () => {
    const u = userEvent.setup();
    renderPage('station');

    await u.click(await screen.findByRole('button', { name: /Eni/ }));
    const dialog = await screen.findByRole('dialog');
    await u.click(within(dialog).getByRole('combobox', { name: /Unisci a/ }));
    await u.click(await screen.findByRole('option', { name: 'Q8' }));
    await u.click(within(dialog).getByRole('button', { name: 'Unisci' }));

    expect(await screen.findByText('I record di «Eni» passano a «Q8» e «Eni» sparisce.')).toBeInTheDocument();
    expect(writes()).toHaveLength(0);
    const confirm = (await screen.findAllByRole('dialog')).at(-1)!;
    await u.click(within(confirm).getByRole('button', { name: 'Conferma' }));

    await waitFor(() =>
      expect(writes()).toContainEqual([
        '/api/values/7/merge',
        expect.objectContaining({ method: 'POST', body: JSON.stringify({ intoId: 9 }) }),
      ]),
    );
  });

  it('elimina anche una voce usata, dopo una conferma che spiega cosa succede ai record', async () => {
    const u = userEvent.setup();
    renderPage('station');

    await u.click(await screen.findByRole('button', { name: /Eni/ }));
    await u.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Elimina' }));
    const confirm = (await screen.findAllByRole('dialog')).at(-1)!;
    expect(within(confirm).getByText(/I record già salvati mantengono questo nome/)).toBeInTheDocument();
    await u.click(within(confirm).getByRole('button', { name: 'Elimina' }));

    await waitFor(() =>
      expect(writes()).toContainEqual(['/api/values/7', expect.objectContaining({ method: 'DELETE' })]),
    );
  });

  it.each([
    ['station', 'Nuovo distributore'],
    ['workshop', 'Nuova officina'],
    ['shop', 'Nuovo negozio'],
    ['expense_category', 'Nuova categoria'],
  ])('per %s il pulsante "%s" crea la voce con POST', async (kind, label) => {
    const u = userEvent.setup();
    renderPage(kind);

    await u.click(await screen.findByRole('button', { name: label }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('heading', { name: label })).toBeInTheDocument();
    await u.type(within(dialog).getByLabelText(/Nome/), 'Nuova');
    await u.click(within(dialog).getByRole('button', { name: 'Salva' }));

    await waitFor(() =>
      expect(writes()).toContainEqual([
        `/api/values/${kind}`,
        expect.objectContaining({ method: 'POST', body: JSON.stringify({ name: 'Nuova' }) }),
      ]),
    );
  });
});
