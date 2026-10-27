import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { act, renderHook } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { useAuthStore } from '@/stores/useAuthStore';
import { useChangeMemberRole, useRemoveMember } from './useOrganizationMembers';
import { vehicleKeys } from './useVehicles';
import { testMe } from '@/test/authFixtures';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

function setup() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return { qc, ...renderHook(() => useRemoveMember(1), { wrapper }) };
}

describe('useRemoveMember — invalidazione', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    mockedAuthFetch.mockResolvedValue(undefined);
  });

  it('invalida membri, inviti, lista veicoli, dettagli, condivisioni e candidati; non le altre organizzazioni', async () => {
    const { qc, result } = setup();
    const keys = {
      members: ['organizations', 1, 'members'] as const,
      invitations: ['organizations', 1, 'invitations'] as const,
      list: vehicleKeys.lists(),
      detail: vehicleKeys.detail(4),
      shares: ['vehicles', 4, 'shares'] as const,
      candidates: ['vehicles', 4, 'share-candidates'] as const,
      candidatesOther: ['vehicles', 9, 'share-candidates'] as const,
      otherOrg: ['organizations', 2, 'members'] as const,
    };
    for (const key of Object.values(keys)) qc.setQueryData(key, []);

    await act(() => result.current.mutateAsync(55));

    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/organizations/1/members/55', { method: 'DELETE' });
    const invalidated = (key: readonly unknown[]) => qc.getQueryState(key)?.isInvalidated;
    expect(invalidated(keys.members)).toBe(true);
    expect(invalidated(keys.invitations)).toBe(true);
    expect(invalidated(keys.list)).toBe(true);
    expect(invalidated(keys.detail)).toBe(true);
    expect(invalidated(keys.shares)).toBe(true);
    expect(invalidated(keys.candidates)).toBe(true);
    expect(invalidated(keys.candidatesOther)).toBe(true);
    expect(invalidated(keys.otherOrg)).toBe(false);
  });
});

describe('useChangeMemberRole', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
    useAuthStore.setState({ user: null });
  });

  function setupRole() {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    );
    return { qc, ...renderHook(() => useChangeMemberRole(1), { wrapper }) };
  }

  it("invia il PATCH con il ruolo e invalida membri, inviti e veicoli senza rileggere /me per un altro membro", async () => {
    mockedAuthFetch.mockResolvedValue({ id: 55, role: 'admin' });
    const { qc, result } = setupRole();
    const keys = [['organizations', 1, 'members'], ['organizations', 1, 'invitations'], vehicleKeys.lists()] as const;
    for (const key of keys) qc.setQueryData(key, []);

    await act(() => result.current.mutateAsync({ memberId: 55, role: 'admin', self: false }));

    expect(mockedAuthFetch).toHaveBeenCalledTimes(1);
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/organizations/1/members/55', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ role: 'admin' }),
    });
    for (const key of keys) expect(qc.getQueryState(key)?.isInvalidated).toBe(true);
  });

  it('se è il proprio ruolo rilegge /api/auth/me e aggiorna lo store', async () => {
    const me = testMe({ memberships: [{ id: 1, role: 'member', organization: { id: 1, name: 'Org', slug: 'org' } }] });
    mockedAuthFetch.mockImplementation(async (path: string) => (path === '/api/auth/me' ? me : { id: 55, role: 'member' }));
    const { result } = setupRole();

    await act(() => result.current.mutateAsync({ memberId: 55, role: 'member', self: true }));

    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/auth/me', expect.anything());
    expect(useAuthStore.getState().user?.memberships[0]?.role).toBe('member');
  });

  it('se il PATCH fallisce non tocca lo store', async () => {
    mockedAuthFetch.mockRejectedValue(new Error('boom'));
    const { result } = setupRole();

    await expect(act(() => result.current.mutateAsync({ memberId: 55, role: 'member', self: true }))).rejects.toThrow('boom');

    expect(mockedAuthFetch).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().user).toBeNull();
  });

  it("se la rilettura di /me fallisce il PATCH resta riuscito: niente errore, invalidazioni fatte, store allineato al ruolo del PATCH", async () => {
    useAuthStore.setState({
      user: {
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
        memberships: [{ id: 55, role: 'owner', organization: { id: 1, name: 'Org', slug: 'org' } }],
      },
    });
    mockedAuthFetch.mockImplementation(async (path: string) => {
      if (path === '/api/auth/me') throw new Error('rete');
      return { id: 55, role: 'admin' };
    });
    const { qc, result } = setupRole();
    qc.setQueryData(['organizations', 1, 'members'], []);

    await act(() => result.current.mutateAsync({ memberId: 55, role: 'admin', self: true }));

    expect(qc.getQueryState(['organizations', 1, 'members'])?.isInvalidated).toBe(true);
    expect(useAuthStore.getState().user?.memberships[0]?.role).toBe('admin');
  });
});
