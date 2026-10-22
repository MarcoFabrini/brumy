import { create } from 'zustand';
import i18n from '@/i18n';

export interface User {
  id: number;
  email: string;
  /** Nuovo indirizzo chiesto dal profilo, in attesa del link di conferma (l'email dell'account non cambia finché non lo apre). */
  pendingEmail: string | null;
  firstName: string;
  lastName: string;
  locale: 'it' | 'en';
  hasAvatar: boolean;
  emailVerified: boolean;
  /** Primo utente registrato sull'istanza: unico autorizzato a VAPID di istanza (SMTP è solo env). */
  isInstanceAdmin: boolean;
  memberships: Array<{
    id: number;
    role: 'owner' | 'admin' | 'member';
    organization: { id: number; name: string; slug: string };
  }>;
}

/**
 * Stati bootstrap auth:
 * - 'loading': tentativo refresh in corso al mount (mostra splash)
 * - 'authenticated': access token valido + user caricato
 * - 'unauthenticated': no sessione (redirect login)
 * - 'unreachable': server irraggiungibile al bootstrap (offline / 5xx): la sessione può essere
 *   ancora valida, quindi niente redirect al login ma una schermata "riprova"
 */
export type AuthStatus = 'loading' | 'authenticated' | 'unauthenticated' | 'unreachable';

interface AuthState {
  status: AuthStatus;
  accessToken: string | null;
  user: User | null;
  setAuthenticated: (accessToken: string, user: User) => void;
  setAccessToken: (token: string) => void;
  setUser: (user: User) => void;
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

/**
 * Auth store NON persistito (in memoria).
 * Access token in localStorage = rischio XSS.
 * Refresh token vive nel cookie HttpOnly gestito dal browser.
 *
 * Allo start `status='loading'`: useAuthBootstrap tenta /api/auth/refresh.
 */
export const useAuthStore = create<AuthState>((set) => ({
  status: 'loading',
  accessToken: null,
  user: null,
  setAuthenticated: (accessToken, user) => {
    syncLanguage(user.locale);
    set({ status: 'authenticated', accessToken, user });
  },
  setAccessToken: (accessToken) => set({ accessToken }),
  setUser: (user) => {
    syncLanguage(user.locale);
    set({ user });
  },
  setUnauthenticated: () => set({ status: 'unauthenticated', accessToken: null, user: null }),
  setUnreachable: () => set({ status: 'unreachable' }),
  logout: () => set({ status: 'unauthenticated', accessToken: null, user: null }),
}));
