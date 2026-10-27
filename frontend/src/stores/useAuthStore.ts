import { create } from 'zustand';
import i18n from '@/i18n';
import type { User } from '@/api/types/auth';

export type { User } from '@/api/types/auth';

/**
 * Stati della sessione:
 * - 'loading': bootstrap in corso al mount (`GET /api/auth/me`, mostra splash)
 * - 'authenticated': sessione valida + utente caricato
 * - 'two_factor': password giusta, manca il codice della verifica in due passaggi (il login mostra il secondo passo)
 * - 'unauthenticated': nessuna sessione (redirect login)
 * - 'unreachable': server irraggiungibile al bootstrap (offline / 5xx): la sessione può essere
 *   ancora valida, quindi niente redirect al login ma una schermata "riprova"
 */
export type AuthStatus = 'loading' | 'authenticated' | 'two_factor' | 'unauthenticated' | 'unreachable';

interface AuthState {
  status: AuthStatus;
  user: User | null;
  /** Organizzazione attiva della sessione (la sceglie il backend; `switch-org` la cambia). */
  activeOrgId: number | null;
  setAuthenticated: (user: User, activeOrgId: number | null) => void;
  setActiveOrgId: (activeOrgId: number) => void;
  setUser: (user: User) => void;
  setTwoFactorPending: () => void;
  setUnauthenticated: () => void;
  setUnreachable: () => void;
  logout: () => void;
}

/**
 * Lingua UI = lingua del profilo (`user.locale`): unico controllo lingua.
 * Allineiamo i18next ogni volta che l'utente viene (ri)caricato dal backend,
 * così la preferenza segue l'account su ogni dispositivo.
 */
function syncLanguage(locale: User['locale']): void {
  if (!i18n.language.startsWith(locale)) {
    void i18n.changeLanguage(locale);
  }
}

const SIGNED_OUT = { user: null, activeOrgId: null } as const;

/**
 * Auth store in memoria: solo lo stato e l'utente. Nessun token: la sessione è il cookie HttpOnly che il browser
 * manda da solo, e al reload `useAuthBootstrap` chiede `/api/auth/me` per sapere chi è collegato.
 */
export const useAuthStore = create<AuthState>((set) => ({
  status: 'loading',
  ...SIGNED_OUT,
  setAuthenticated: (user, activeOrgId) => {
    syncLanguage(user.locale);
    set({ status: 'authenticated', user, activeOrgId });
  },
  setActiveOrgId: (activeOrgId) => set({ activeOrgId }),
  setUser: (user) => {
    syncLanguage(user.locale);
    set({ user });
  },
  setTwoFactorPending: () => set({ status: 'two_factor', ...SIGNED_OUT }),
  setUnauthenticated: () => set({ status: 'unauthenticated', ...SIGNED_OUT }),
  setUnreachable: () => set({ status: 'unreachable' }),
  logout: () => set({ status: 'unauthenticated', ...SIGNED_OUT }),
}));
