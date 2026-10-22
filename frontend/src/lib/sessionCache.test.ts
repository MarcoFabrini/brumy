import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { useAuthStore, type User } from '@/stores/useAuthStore';
import { clearQueryCacheOnSessionChange, sessionCacheIsStale } from './sessionCache';

const jwt = (orgId: number | null) =>
  `h.${btoa(JSON.stringify(orgId === null ? {} : { active_org_id: orgId })).replace(/=+$/, '')}.s`;

const user = (id: number): User => ({
  id,
  email: `u${id}@test.it`,
  firstName: 'A',
  lastName: 'B',
  locale: 'it',
  hasAvatar: false,
  emailVerified: true,
  pendingEmail: null,
  isInstanceAdmin: false,
  memberships: [],
});

describe('sessionCacheIsStale', () => {
  const session = (id: number | null, orgId: number | null) => ({
    user: id === null ? null : { id },
    accessToken: id === null ? null : jwt(orgId),
  });

  it('è stale quando l\'org attiva passa da un valore a un altro', () => {
    expect(sessionCacheIsStale(session(1, 2), session(1, 1))).toBe(true);
  });

  it('non è stale con la stessa org (rotazione del token)', () => {
    expect(sessionCacheIsStale(session(1, 1), session(1, 1))).toBe(false);
  });

  it('non è stale quando l\'org passa da/verso null (il token si assesta)', () => {
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
    useAuthStore.setState({ status: 'authenticated', accessToken: jwt(1), user: user(1) });
    unsubscribe = clearQueryCacheOnSessionChange({ clear });
  });

  afterEach(() => {
    unsubscribe();
    useAuthStore.setState({ status: 'loading', accessToken: null, user: null });
  });

  it('svuota la cache quando un token con un\'altra org sostituisce il precedente (altra scheda, switch)', () => {
    useAuthStore.getState().setAccessToken(jwt(2));
    expect(clear).toHaveBeenCalledTimes(1);
  });

  it('non la svuota alla rotazione del token sulla stessa org', () => {
    useAuthStore.getState().setAccessToken(jwt(1));
    expect(clear).not.toHaveBeenCalled();
  });

  it('non la svuota quando l\'org passa da null a un valore', () => {
    useAuthStore.setState({ accessToken: jwt(null) });
    clear.mockReset();
    useAuthStore.getState().setAccessToken(jwt(3));
    expect(clear).not.toHaveBeenCalled();
  });

  it('la svuota al logout e al cambio utente', () => {
    useAuthStore.getState().logout();
    expect(clear).toHaveBeenCalledTimes(1);

    useAuthStore.getState().setAuthenticated(jwt(1), user(1));
    useAuthStore.getState().setUser(user(2));
    expect(clear).toHaveBeenCalledTimes(2);
  });

  it('svuota davvero i dati di una QueryClient reale', () => {
    unsubscribe();
    const qc = new QueryClient();
    unsubscribe = clearQueryCacheOnSessionChange(qc);
    qc.setQueryData(['vehicles'], [{ id: 1 }]);

    useAuthStore.getState().setAccessToken(jwt(9));

    expect(qc.getQueryData(['vehicles'])).toBeUndefined();
  });

  it('dopo unsubscribe non reagisce più', () => {
    unsubscribe();
    useAuthStore.getState().setAccessToken(jwt(5));
    expect(clear).not.toHaveBeenCalled();
  });
});
