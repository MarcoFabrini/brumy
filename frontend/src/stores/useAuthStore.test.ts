import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import i18n from '@/i18n';
import { testUser } from '@/test/authFixtures';
import { useAuthStore, type User } from './useAuthStore';

function userWith(locale: User['locale']): User {
  return testUser({ email: 'anna@test.it', firstName: 'Anna', lastName: 'Neri', locale, memberships: [] });
}

describe('useAuthStore', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  beforeEach(async () => {
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'loading', user: null, activeOrgId: null });
  });

  it('parte in loading, senza utente né org attiva (il bootstrap decide)', () => {
    const { status, user, activeOrgId } = useAuthStore.getState();
    expect([status, user, activeOrgId]).toEqual(['loading', null, null]);
  });

  it('non tiene nessun token: la sessione è il cookie HttpOnly', () => {
    expect(Object.keys(useAuthStore.getState())).not.toContain('accessToken');
  });

  it('setAuthenticated salva utente e org attiva e porta la UI alla lingua del profilo', async () => {
    useAuthStore.getState().setAuthenticated(userWith('en'), 7);

    expect(useAuthStore.getState()).toMatchObject({ status: 'authenticated', activeOrgId: 7 });
    expect(useAuthStore.getState().user?.email).toBe('anna@test.it');
    expect(i18n.language).toBe('en');
  });

  it('setUser aggiorna la lingua quando il profilo cambia, e non la tocca se coincide già', async () => {
    useAuthStore.getState().setAuthenticated(userWith('it'), 7);
    expect(i18n.language).toBe('it');

    useAuthStore.getState().setUser(userWith('en'));
    expect(i18n.language).toBe('en');
    expect(useAuthStore.getState().activeOrgId).toBe(7);
  });

  it('una variante regionale della stessa lingua (en-US) non scatena un cambio lingua', async () => {
    await i18n.changeLanguage('en-US');

    useAuthStore.getState().setUser(userWith('en'));

    expect(i18n.language).toBe('en-US');
  });

  it('setActiveOrgId (switch-org) non cambia lo stato di sessione né l\'utente', () => {
    useAuthStore.getState().setAuthenticated(userWith('it'), 7);

    useAuthStore.getState().setActiveOrgId(8);

    expect(useAuthStore.getState()).toMatchObject({ status: 'authenticated', activeOrgId: 8 });
    expect(useAuthStore.getState().user?.email).toBe('anna@test.it');
  });

  it.each([
    ['setUnauthenticated', 'unauthenticated'],
    ['logout', 'unauthenticated'],
    ['setTwoFactorPending', 'two_factor'],
  ] as const)('%s azzera utente e org attiva', (action, status) => {
    useAuthStore.getState().setAuthenticated(userWith('it'), 7);

    useAuthStore.getState()[action]();

    expect(useAuthStore.getState()).toMatchObject({ status, user: null, activeOrgId: null });
  });

  it('setUnreachable cambia solo lo stato: la sessione può essere ancora valida, niente logout', () => {
    useAuthStore.getState().setAuthenticated(userWith('it'), 7);

    useAuthStore.getState().setUnreachable();

    expect(useAuthStore.getState()).toMatchObject({ status: 'unreachable', activeOrgId: 7 });
    expect(useAuthStore.getState().user).not.toBeNull();
  });

  it('non scrive nulla negli storage del browser (dati personali in localStorage sarebbero esposti a XSS)', async () => {
    // Il rilevatore di lingua di i18next scrive la lingua ("it", "en-US") in localStorage: lecito. Conta l'utente.
    const writes: string[] = [];
    const spyStorage = { getItem: () => null, removeItem: () => {}, setItem: (_k: string, v: string) => void writes.push(v) };
    vi.stubGlobal('localStorage', spyStorage);
    vi.stubGlobal('sessionStorage', spyStorage);
    vi.resetModules();
    const { useAuthStore: freshStore } = await import('./useAuthStore');

    freshStore.getState().setAuthenticated(userWith('it'), 7);

    expect(freshStore.getState().user?.email).toBe('anna@test.it');
    expect(writes.filter((value) => value.includes('anna@test.it'))).toEqual([]);
  });
});
