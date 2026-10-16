import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { VehicleShareCard } from './VehicleShareCard';
import type { ShareCandidate, VehicleShare } from '@/hooks/useVehicleShares';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

const OWNER_ID = 1;

function share(id: number, role: VehicleShare['role'], userId: number, first: string, last: string): VehicleShare {
  return {
    id,
    role,
    acceptedAt: '2026-09-01T00:00:00+00:00',
    createdAt: '2026-09-01T00:00:00+00:00',
    user: { id: userId, firstName: first, lastName: last },
  };
}

const CANDIDATES: ShareCandidate[] = [
  { id: 10, firstName: 'Carlo', lastName: 'Alberti' },
  { id: 11, firstName: 'Anna', lastName: 'Bianchi' },
  { id: 12, firstName: 'Luca', lastName: 'Verdi' },
];

/** Risponde per URL: condivisioni esistenti, candidati e (a POST) la creazione. */
function mockApi({ shares = [] as VehicleShare[], candidates = CANDIDATES } = {}) {
  mockedAuthFetch.mockImplementation(async (url, init) => {
    if (init?.method === 'POST') return [];
    if (String(url).endsWith('/share-candidates')) return candidates;
    return shares;
  });
}

function renderCard() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <VehicleShareCard vehicleId={7} currentUserId={OWNER_ID} onAccessLost={vi.fn()} />
    </QueryClientProvider>,
  );
}

describe('VehicleShareCard', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('il proprietario non è revocabile, le condivisioni in sola lettura sì; solo nomi, mai email', async () => {
    mockApi({ shares: [share(1, 'admin', OWNER_ID, 'Marco', 'Rossi'), share(2, 'viewer', 2, 'Sara', 'Neri')] });
    renderCard();

    expect(await screen.findByText(/Marco Rossi/)).toBeInTheDocument();
    expect(screen.getByText('Sara Neri')).toBeInTheDocument();
    expect(screen.getByText('Proprietario')).toBeInTheDocument();
    expect(screen.getByText('Sola lettura')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Revoca' })).toHaveLength(1);
    expect(screen.queryByText(/@test\.it/)).not.toBeInTheDocument();
  });

  it("non c'è nessun campo email: si sceglie da un elenco, e il pulsante è disattivato senza selezione", async () => {
    mockApi();
    renderCard();

    expect(await screen.findByRole('button', { name: /Seleziona persone/ })).toBeInTheDocument();
    expect(screen.queryByLabelText(/Email/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Condividi' })).toBeDisabled();
  });

  it('multi-selezione per nome e cognome: invia gli id di tutte le persone scelte', async () => {
    mockApi();
    const user = userEvent.setup();
    renderCard();

    await user.click(await screen.findByRole('button', { name: /Seleziona persone/ }));
    // Elenco per nome e cognome (ordine del backend: per cognome).
    const options = await screen.findAllByRole('menuitemcheckbox');
    expect(options.map((o) => o.textContent)).toEqual(['Carlo Alberti', 'Anna Bianchi', 'Luca Verdi']);

    await user.click(screen.getByRole('menuitemcheckbox', { name: 'Carlo Alberti' }));
    // Il menu resta aperto: si può scegliere una seconda persona.
    await user.click(screen.getByRole('menuitemcheckbox', { name: 'Luca Verdi' }));
    await user.keyboard('{Escape}');

    await user.click(await screen.findByRole('button', { name: 'Condividi con 2 persone' }));

    await waitFor(() =>
      expect(mockedAuthFetch).toHaveBeenCalledWith('/api/vehicles/7/shares', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ userIds: [10, 12] }),
      }),
    );
  });

  it('senza membri con cui condividere spiega come entrano (inviti) e non mostra il form', async () => {
    mockApi({ candidates: [] });
    renderCard();

    expect(await screen.findByText(/Nessun altro membro dell'organizzazione/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Seleziona persone/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Condividi' })).not.toBeInTheDocument();
  });
});
