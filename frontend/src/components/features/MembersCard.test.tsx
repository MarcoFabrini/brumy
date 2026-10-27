import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { ApiError, authFetch } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { Toaster } from '@/components/ui';
import { MembersCard } from './MembersCard';
import type { MemberRole } from '@/hooks/useOrganizationMembers';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const member = {
  id: 1,
  role: 'owner',
  acceptedAt: '2026-09-01T00:00:00+00:00',
  user: { id: 1, email: 'anna@test.it', firstName: 'Anna', lastName: 'Neri' },
};

function mockApi({ members, invitations }: { members: () => unknown; invitations: () => unknown }) {
  mockedAuthFetch.mockImplementation(async (path: string) => {
    if (path === '/api/organizations/1/members') return members();
    if (path === '/api/organizations/1/invitations') return invitations();
    throw new Error(`unexpected fetch ${path}`);
  });
}

function renderCard(currentRole: MemberRole = 'owner') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MembersCard organizationId={1} currentUserId={1} currentRole={currentRole} />
      <Toaster />
    </QueryClientProvider>,
  );
}

describe('MembersCard — errori delle query', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('se i membri non si caricano mostra un errore, non una card vuota', async () => {
    mockApi({
      members: () => Promise.reject(new ApiError('http.500', 500)),
      invitations: () => [],
    });
    renderCard();

    expect(await screen.findByRole('alert')).toHaveTextContent('Errore server');
  });

  it('se gli inviti non si caricano mostra un errore, e i membri restano visibili', async () => {
    mockApi({
      members: () => [member],
      invitations: () => Promise.reject(new ApiError('http.403', 403)),
    });
    renderCard();

    expect(await screen.findByRole('alert')).toHaveTextContent('Accesso negato');
    expect(screen.getByText(/Anna Neri/)).toBeInTheDocument();
  });

  it('senza errori non compare nessun alert', async () => {
    mockApi({ members: () => [member], invitations: () => [] });
    renderCard();

    expect(await screen.findByText(/Anna Neri/)).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});

// ---------------- cambio di ruolo ----------------

const asMember = (id: number, role: MemberRole, firstName: string) => ({
  id,
  role,
  acceptedAt: '2026-09-01T00:00:00+00:00',
  user: { id, email: `${firstName.toLowerCase()}@test.it`, firstName, lastName: 'Neri' },
});

function mockMembers(members: ReturnType<typeof asMember>[], patch?: (path: string, init: RequestInit) => unknown) {
  mockedAuthFetch.mockImplementation(async (path: string, init?: RequestInit) => {
    if (path === '/api/organizations/1/members') return members;
    if (path === '/api/organizations/1/invitations') return [];
    if (init?.method === 'PATCH') return patch ? patch(path, init) : members[0];
    if (path === '/api/auth/me') return { ...meAs('member'), activeOrganizationId: 1 };
    throw new Error(`unexpected fetch ${path}`);
  });
}

function meAs(role: MemberRole): User {
  return {
    id: 1,
    email: 'anna@test.it',
    firstName: 'Anna',
    lastName: 'Neri',
    locale: 'it',
    hasAvatar: false,
    emailVerified: true,
    pendingEmail: null,
    isInstanceAdmin: false,
    twoFactorEnabled: false,
    backupCodesRemaining: 0,
    memberships: [{ id: 1, role, organization: { id: 1, name: 'Org', slug: 'org' } }],
  };
}

/** I select dei ruoli sulle righe dei membri (esclude quello del form di invito, in fondo alla card). */
const rowSelects = () => within(screen.getAllByRole('list')[0]!).queryAllByRole('combobox');

const patchCalls = () => mockedAuthFetch.mock.calls.filter(([, init]) => init?.method === 'PATCH');

describe('MembersCard — cambio di ruolo', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    // Radix Select in jsdom: mancano alcune API del puntatore
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => undefined;
    Element.prototype.releasePointerCapture = () => undefined;
    Element.prototype.scrollIntoView = () => undefined;
  });

  it("l'owner vede il select su admin e member ma solo il badge sull'unico owner (sé stesso)", async () => {
    mockMembers([asMember(1, 'owner', 'Anna'), asMember(2, 'admin', 'Bruno'), asMember(3, 'member', 'Carla')]);
    renderCard('owner');

    expect(await screen.findByText(/Bruno Neri/)).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Cambia il ruolo di Bruno Neri' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Cambia il ruolo di Carla Neri' })).toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: 'Cambia il ruolo di Anna Neri' })).not.toBeInTheDocument();
    expect(rowSelects()).toHaveLength(2);
    expect(screen.getByText('Proprietario')).toBeInTheDocument(); // badge di sola lettura
  });

  it('con due owner la riga di un owner ha il select, anche la propria', async () => {
    mockMembers([asMember(1, 'owner', 'Anna'), asMember(2, 'owner', 'Bruno')]);
    renderCard('owner');

    expect(await screen.findByRole('combobox', { name: 'Cambia il ruolo di Bruno Neri' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Cambia il ruolo di Anna Neri' })).toBeInTheDocument();
  });

  it("l'admin vede il select solo sulle righe member e può solo promuovere", async () => {
    mockMembers([asMember(1, 'admin', 'Anna'), asMember(2, 'owner', 'Bruno'), asMember(4, 'admin', 'Dario'), asMember(3, 'member', 'Carla')]);
    const user = userEvent.setup();
    renderCard('admin');

    const select = await screen.findByRole('combobox', { name: 'Cambia il ruolo di Carla Neri' });
    expect(rowSelects()).toHaveLength(1);

    await user.click(select);
    const options = within(await screen.findByRole('listbox')).getAllByRole('option').map((o) => o.textContent);
    expect(options).toEqual(['Amministratore', 'Membro']);
  });

  it('il member non vede nessun select', async () => {
    mockMembers([asMember(1, 'member', 'Anna'), asMember(3, 'member', 'Carla')]);
    renderCard('member');

    expect(await screen.findByText(/Carla Neri/)).toBeInTheDocument();
    expect(rowSelects()).toHaveLength(0);
  });

  async function pick(user: ReturnType<typeof userEvent.setup>, name: string, option: string) {
    await user.click(await screen.findByRole('combobox', { name }));
    await user.click(await screen.findByRole('option', { name: option }));
  }

  it('annullare la conferma non chiama il backend e il select mostra ancora il ruolo del server', async () => {
    mockMembers([asMember(1, 'owner', 'Anna'), asMember(2, 'admin', 'Bruno')]);
    const user = userEvent.setup();
    renderCard('owner');

    await pick(user, 'Cambia il ruolo di Bruno Neri', 'Membro');
    const dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveTextContent('Bruno Neri non sarà più amministratore');
    await user.click(within(dialog).getByRole('button', { name: 'Annulla' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(patchCalls()).toHaveLength(0);
    expect(screen.getByRole('combobox', { name: 'Cambia il ruolo di Bruno Neri' })).toHaveTextContent('Amministratore');
  });

  it('confermare un declassamento invia il PATCH, mostra il toast e ricarica membri e inviti', async () => {
    mockMembers([asMember(1, 'owner', 'Anna'), asMember(2, 'admin', 'Bruno')]);
    const user = userEvent.setup();
    renderCard('owner');

    await pick(user, 'Cambia il ruolo di Bruno Neri', 'Membro');
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Conferma' }));

    await waitFor(() => expect(patchCalls()).toHaveLength(1));
    expect(patchCalls()[0]).toEqual(['/api/organizations/1/members/2', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ role: 'member' }) })]);
    expect((await screen.findAllByText('Ruolo aggiornato.')).length).toBeGreaterThan(0);
    await waitFor(() => {
      const memberLoads = mockedAuthFetch.mock.calls.filter(([p]) => p === '/api/organizations/1/members' ).length;
      expect(memberLoads).toBeGreaterThanOrEqual(2);
    });
    expect(mockedAuthFetch.mock.calls.some(([p]) => p === '/api/auth/me')).toBe(false); // non è il proprio ruolo
  });

  it('il testo di conferma cambia con la direzione del cambio', async () => {
    mockMembers([asMember(1, 'owner', 'Anna'), asMember(2, 'admin', 'Bruno'), asMember(3, 'member', 'Carla')]);
    const user = userEvent.setup();
    renderCard('owner');

    await pick(user, 'Cambia il ruolo di Carla Neri', 'Proprietario');
    let dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveTextContent('Carla Neri diventerà proprietario');
    await user.click(within(dialog).getByRole('button', { name: 'Annulla' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

    await pick(user, 'Cambia il ruolo di Carla Neri', 'Amministratore');
    dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveTextContent('Carla Neri diventerà amministratore');
  });

  it("un errore del backend (ultimo owner) si mostra tradotto e non cambia nulla", async () => {
    mockMembers([asMember(1, 'owner', 'Anna'), asMember(2, 'owner', 'Bruno')], () => {
      throw new ApiError('member.last_owner', 409);
    });
    const user = userEvent.setup();
    renderCard('owner');

    await pick(user, 'Cambia il ruolo di Bruno Neri', 'Membro');
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Conferma' }));

    expect(await screen.findByText(/almeno un proprietario/)).toBeInTheDocument();
  });

  it('se cambia il proprio ruolo rilegge /api/auth/me e aggiorna lo store', async () => {
    useAuthStore.setState({ user: meAs('owner') });
    mockMembers([asMember(1, 'owner', 'Anna'), asMember(2, 'owner', 'Bruno')]);
    const user = userEvent.setup();
    renderCard('owner');

    await pick(user, 'Cambia il ruolo di Anna Neri', 'Membro');
    expect(await screen.findByRole('dialog')).toHaveTextContent('Non sarai più proprietario');
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Conferma' }));

    await waitFor(() => expect(useAuthStore.getState().user?.memberships[0]?.role).toBe('member'));
    expect(mockedAuthFetch.mock.calls.some(([p]) => p === '/api/auth/me')).toBe(true);
    expect((await screen.findAllByText('Ruolo aggiornato.')).length).toBeGreaterThan(0);
  });

  it("se la rilettura di /api/auth/me fallisce dopo il cambio del proprio ruolo non c'è un toast d'errore e lo store segue il PATCH", async () => {
    useAuthStore.setState({ user: meAs('owner') });
    mockedAuthFetch.mockImplementation(async (path: string, init?: RequestInit) => {
      if (path === '/api/organizations/1/members') return [asMember(1, 'owner', 'Anna'), asMember(2, 'owner', 'Bruno')];
      if (path === '/api/organizations/1/invitations') return [];
      if (init?.method === 'PATCH') return { ...asMember(1, 'member', 'Anna') };
      if (path === '/api/auth/me') throw new ApiError('http.500', 500);
      throw new Error(`unexpected fetch ${path}`);
    });
    const user = userEvent.setup();
    renderCard('owner');

    await pick(user, 'Cambia il ruolo di Anna Neri', 'Membro');
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Conferma' }));

    expect((await screen.findAllByText('Ruolo aggiornato.')).length).toBeGreaterThan(0);
    expect(screen.queryByText('Errore server')).not.toBeInTheDocument();
    expect(useAuthStore.getState().user?.memberships[0]?.role).toBe('member');
    // Le invalidazioni partono comunque: i membri vengono ricaricati.
    await waitFor(() => {
      const loads = mockedAuthFetch.mock.calls.filter(([p]) => p === '/api/organizations/1/members').length;
      expect(loads).toBeGreaterThanOrEqual(2);
    });
  });
});

describe('MembersCard — layout mobile e rimozione', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => undefined;
    Element.prototype.releasePointerCapture = () => undefined;
    Element.prototype.scrollIntoView = () => undefined;
  });

  it('il ruolo è un selettore compatto a destra e nome ed email restano troncabili', async () => {
    const long = { ...asMember(2, 'member', 'Bartolomeo-Maria-Giuseppe') };
    long.user.email = 'bartolomeo.maria.giuseppe.con.un.indirizzo.molto.lungo@dominio-di-esempio.example';
    mockMembers([asMember(1, 'owner', 'Anna'), long]);
    renderCard('owner');

    const select = await screen.findByRole('combobox', { name: /Cambia il ruolo di Bartolomeo/ });
    // jsdom non fa layout: si verificano le classi che lo garantiscono.
    expect(select).toHaveClass('w-auto', 'shrink-0');
    expect(screen.getByText(/bartolomeo\.maria/)).toHaveClass('truncate');
    expect(screen.getByText(/Bartolomeo-Maria-Giuseppe Neri/).closest('div')).toHaveClass('min-w-0', 'flex-1');
  });

  it('"Invita un membro" apre il foglio con il modulo', async () => {
    mockMembers([asMember(1, 'owner', 'Anna')]);
    renderCard('owner');
    const user = userEvent.setup();

    await user.click(await screen.findByRole('button', { name: 'Invita un membro' }));

    const dialog = await screen.findByRole('dialog', { name: 'Invita un membro' });
    expect(within(dialog).getByLabelText(/Email/)).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Invia invito' })).toBeInTheDocument();
  });

  it("un admin non vede il pulsante di rimozione sugli altri admin, un owner sì", async () => {
    const list = [asMember(1, 'admin', 'Anna'), asMember(2, 'admin', 'Bruno'), asMember(3, 'member', 'Carla')];

    mockMembers(list);
    const first = renderCard('admin');
    await screen.findByText(/Carla/);
    // Anna è l'utente corrente, Bruno è admin: resta solo Carla (member).
    expect(screen.getAllByRole('button', { name: 'Rimuovi' })).toHaveLength(1);
    first.unmount();

    mockMembers([asMember(1, 'owner', 'Anna'), asMember(2, 'admin', 'Bruno'), asMember(3, 'member', 'Carla')]);
    renderCard('owner');
    await screen.findByText(/Carla/);
    expect(screen.getAllByRole('button', { name: 'Rimuovi' })).toHaveLength(2);
  });

  it('la conferma di rimozione dice che i veicoli passano a chi rimuove e suggerisce di trasferirli prima', async () => {
    mockMembers([asMember(1, 'owner', 'Anna'), asMember(3, 'member', 'Carla')]);
    const user = userEvent.setup();
    renderCard('owner');

    await user.click(await screen.findByRole('button', { name: 'Rimuovi' }));

    const dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveTextContent(/passeranno a te/);
    expect(dialog).toHaveTextContent(/trasferiscili prima/);
  });
});
