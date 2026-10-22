import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import i18n from '@/i18n';
import { useAuthStore, type User } from './useAuthStore';

function userWith(locale: User['locale']): User {
  return {
    id: 1,
    email: 'anna@test.it',
    firstName: 'Anna',
    lastName: 'Neri',
    locale,
    hasAvatar: false,
    emailVerified: true,
    pendingEmail: null,
    isInstanceAdmin: false,
    memberships: [],
  };
}

describe('useAuthStore', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  beforeEach(async () => {
    await i18n.changeLanguage('it');
    useAuthStore.setState({ status: 'loading', accessToken: null, user: null });
  });

  it('parte in loading, senza token né utente (il bootstrap decide)', () => {
    const { status, accessToken, user } = useAuthStore.getState();
    expect([status, accessToken, user]).toEqual(['loading', null, null]);
  });

  it('setAuthenticated salva token e utente e porta la UI alla lingua del profilo', async () => {
    useAuthStore.getState().setAuthenticated('tok', userWith('en'));

    expect(useAuthStore.getState()).toMatchObject({ status: 'authenticated', accessToken: 'tok' });
    expect(i18n.language).toBe('en');
  });

  it('setUser aggiorna la lingua quando il profilo cambia, e non la tocca se coincide già', async () => {
    useAuthStore.getState().setAuthenticated('tok', userWith('it'));
    expect(i18n.language).toBe('it');

    useAuthStore.getState().setUser(userWith('en'));
    expect(i18n.language).toBe('en');
    expect(useAuthStore.getState().accessToken).toBe('tok');
  });

  it('una variante regionale della stessa lingua (en-US) non scatena un cambio lingua', async () => {
    await i18n.changeLanguage('en-US');

    useAuthStore.getState().setUser(userWith('en'));

    expect(i18n.language).toBe('en-US');
  });

  it('setAccessToken (rotazione del token) non cambia lo stato di sessione né l\'utente', () => {
    useAuthStore.getState().setAuthenticated('vecchio', userWith('it'));

    useAuthStore.getState().setAccessToken('nuovo');

    expect(useAuthStore.getState()).toMatchObject({ status: 'authenticated', accessToken: 'nuovo' });
    expect(useAuthStore.getState().user?.email).toBe('anna@test.it');
  });

  it.each([
    ['setUnauthenticated', 'unauthenticated'],
    ['logout', 'unauthenticated'],
  ] as const)('%s azzera token e utente', (action, status) => {
    useAuthStore.getState().setAuthenticated('tok', userWith('it'));

    useAuthStore.getState()[action]();

    expect(useAuthStore.getState()).toMatchObject({ status, accessToken: null, user: null });
  });

  it('setUnreachable cambia solo lo stato: la sessione può essere ancora valida, niente logout', () => {
    useAuthStore.getState().setAuthenticated('tok', userWith('it'));

    useAuthStore.getState().setUnreachable();

    expect(useAuthStore.getState()).toMatchObject({ status: 'unreachable', accessToken: 'tok' });
    expect(useAuthStore.getState().user).not.toBeNull();
  });

  it('non scrive nulla negli storage del browser (il token in localStorage sarebbe esposto a XSS)', async () => {
    // Il rilevatore di lingua di i18next scrive la lingua ("it", "en-US") in localStorage: lecito. Conta il token.
    const writes: string[] = [];
    const spyStorage = { getItem: () => null, removeItem: () => {}, setItem: (_k: string, v: string) => void writes.push(v) };
    vi.stubGlobal('localStorage', spyStorage);
    vi.stubGlobal('sessionStorage', spyStorage);
    vi.resetModules();
    const { useAuthStore: freshStore } = await import('./useAuthStore');

    freshStore.getState().setAuthenticated('segreto-da-non-salvare', userWith('it'));

    expect(freshStore.getState().accessToken).toBe('segreto-da-non-salvare');
    expect(writes.filter((value) => value.includes('segreto-da-non-salvare'))).toEqual([]);
  });
});
