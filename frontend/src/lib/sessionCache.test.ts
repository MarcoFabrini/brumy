import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/stores/useAuthStore';
import { testUser } from '@/test/authFixtures';
import { clearQueryCacheOnSessionChange, sessionCacheIsStale } from './sessionCache';

const user = (id: number) => testUser({ id, email: `u${id}@test.it`, memberships: [] });

describe('sessionCacheIsStale', () => {
  const session = (id: number | null, activeOrgId: number | null) => ({
    user: id === null ? null : { id },
    activeOrgId: id === null ? null : activeOrgId,
  });

  it('è stale quando l\'org attiva passa da un valore a un altro', () => {
    expect(sessionCacheIsStale(session(1, 2), session(1, 1))).toBe(true);
  });

  it('non è stale con la stessa org', () => {
    expect(sessionCacheIsStale(session(1, 1), session(1, 1))).toBe(false);
  });

  it('non è stale quando l\'org passa da/verso null (la sessione si apre o non ha org)', () => {
    expect(sessionCacheIsStale(session(1, 1), session(1, null))).toBe(false);
    expect(sessionCacheIsStale(session(1, null), session(1, 1))).toBe(false);
  });

  it('è stale quando l\'utente esce o cambia', () => {
    expect(sessionCacheIsStale(session(null, null), session(1, 1))).toBe(true);
    expect(sessionCacheIsStale(session(2, 1), session(1, 1))).toBe(true);
  });

  it('non è stale al primo login (nessun utente precedente)', () => {
    expect(sessionCacheIsStale(session(1, 1), session(null, null))).toBe(false);
  });
});

describe('clearQueryCacheOnSessionChange', () => {
  let unsubscribe: () => void;
  const clear = vi.fn();

  beforeEach(() => {
    clear.mockReset();
    useAuthStore.setState({ status: 'authenticated', user: user(1), activeOrgId: 1 });
    unsubscribe = clearQueryCacheOnSessionChange({ clear });
  });

  afterEach(() => {
    unsubscribe();
    useAuthStore.setState({ status: 'loading', user: null, activeOrgId: null });
  });

  it('svuota la cache quando l\'org attiva cambia (switch-org, invito accettato)', () => {
    useAuthStore.getState().setActiveOrgId(2);
    expect(clear).toHaveBeenCalledTimes(1);
  });

  it('non la svuota quando l\'org resta la stessa', () => {
    useAuthStore.getState().setActiveOrgId(1);
    useAuthStore.getState().setUser(user(1));
    expect(clear).not.toHaveBeenCalled();
  });

  it('non la svuota quando l\'org passa da null a un valore', () => {
    useAuthStore.setState({ activeOrgId: null });
    clear.mockReset();
    useAuthStore.getState().setActiveOrgId(3);
    expect(clear).not.toHaveBeenCalled();
  });

  it('la svuota al logout e al cambio utente', () => {
    useAuthStore.getState().logout();
    expect(clear).toHaveBeenCalledTimes(1);

    useAuthStore.getState().setAuthenticated(user(1), 1);
    useAuthStore.getState().setUser(user(2));
    expect(clear).toHaveBeenCalledTimes(2);
  });

  it('svuota davvero i dati di una QueryClient reale', () => {
    unsubscribe();
    const qc = new QueryClient();
    unsubscribe = clearQueryCacheOnSessionChange(qc);
    qc.setQueryData(['vehicles'], [{ id: 1 }]);

    useAuthStore.getState().setActiveOrgId(9);

    expect(qc.getQueryData(['vehicles'])).toBeUndefined();
  });

  it('dopo unsubscribe non reagisce più', () => {
    unsubscribe();
    useAuthStore.getState().setActiveOrgId(5);
    expect(clear).not.toHaveBeenCalled();
  });
});
