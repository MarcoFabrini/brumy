import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { switchOrganization } from '@/api/endpoints/auth';
import { profileLocale } from '@/lib/format';
import { resyncThisDevice } from '@/lib/pushResync';
import { useAuthStore, type User } from '@/stores/useAuthStore';

const JSON_HEADERS = { 'Content-Type': 'application/json' };

interface ProfilePayload {
  email: string;
  firstName: string;
  lastName: string;
  locale: 'it' | 'en';
  /** Obbligatoria se cambia l'email. */
  currentPassword?: string;
}

interface AuthLikeResponse {
  access_token: string;
  user: Omit<User, 'memberships'>;
}

/**
 * Aggiorna profilo (nome/email/locale) e ricarica /me. La risposta porta sempre un access token: con l'email
 * cambiata è una sessione nuova (il server ha chiuso tutte le altre e cancellato le push subscription, quindi
 * questo browser ri-registra la propria).
 */
export function useUpdateProfile() {
  return useMutation({
    mutationFn: async (payload: ProfilePayload) => {
      const previousEmail = useAuthStore.getState().user?.email;
      const res = await authFetch<AuthLikeResponse>('/api/auth/profile', {
        method: 'PUT',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
      });
      useAuthStore.getState().setAccessToken(res.access_token);
      if (res.user.email !== previousEmail) void resyncThisDevice();
      const me = await authFetch<User>('/api/auth/me');
      useAuthStore.getState().setUser(me);
      return me;
    },
  });
}

interface ChangePasswordPayload {
  currentPassword: string;
  newPassword: string;
}

/**
 * Cambia password: verifica corrente, ruota la sessione (nuovo access_token + cookie). Il server chiude tutte le
 * altre sessioni e cancella le push subscription: questo browser ri-registra la propria.
 */
export function useChangePassword() {
  return useMutation({
    mutationFn: async (payload: ChangePasswordPayload) => {
      const res = await authFetch<AuthLikeResponse>('/api/auth/password', {
        method: 'PUT',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
      });
      useAuthStore.getState().setAccessToken(res.access_token);
      void resyncThisDevice();
    },
  });
}

interface UpdateOrganizationPayload {
  id: number;
  name: string;
}

/** Rinomina organizzazione (owner/admin). Ricarica /me per aggiornare le membership nello store. */
export function useUpdateOrganization() {
  return useMutation({
    mutationFn: async (payload: UpdateOrganizationPayload) => {
      await authFetch(`/api/organizations/${payload.id}`, {
        method: 'PUT',
        headers: JSON_HEADERS,
        body: JSON.stringify({ name: payload.name }),
      });
      const me = await authFetch<User>('/api/auth/me');
      useAuthStore.getState().setUser(me);
      return me;
    },
  });
}

/** Richiede email di reset. Risposta sempre 200 (no enumeration). */
export function useForgotPassword() {
  return useMutation({
    mutationFn: (payload: { email: string }) =>
      authFetch<{ status: string }>('/api/auth/forgot-password', {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
        skipRefresh: true,
      }),
  });
}

/** Reimposta la password con il token dal link email. */
export function useResetPassword() {
  return useMutation({
    mutationFn: (payload: { token: string; password: string }) =>
      authFetch<void>('/api/auth/reset-password', {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
        skipRefresh: true,
      }),
  });
}

/** Conferma l'indirizzo email col token dal link (pubblico, no sessione). */
export function useVerifyEmail() {
  return useMutation({
    mutationFn: (payload: { token: string }) =>
      authFetch<void>('/api/auth/verify-email', {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
        skipRefresh: true,
      }),
    onSuccess: () => {
      // Se l'utente è già dentro, il banner "verifica l'email" deve sparire senza ricaricare.
      const { status, setUser } = useAuthStore.getState();
      if (status !== 'authenticated') return;
      void authFetch<User>('/api/auth/me').then(setUser, () => undefined);
    },
  });
}

/** Rinvia l'email di verifica all'utente corrente. Ricarica /me se nel frattempo è verificato. */
export function useResendVerification() {
  return useMutation({
    mutationFn: () =>
      authFetch<void>('/api/auth/resend-verification', { method: 'POST' }),
  });
}

export interface InvitationPreview {
  organizationName: string;
  email: string;
  role: 'owner' | 'admin' | 'member';
  accountExists: boolean;
}

/** Anteprima invito dal token (pubblico): org, email, ruolo, se esiste già un account. */
export function useInvitationPreview(token: string) {
  return useQuery({
    queryKey: ['invitation', token],
    queryFn: () => authFetch<InvitationPreview>(`/api/auth/invitation/${encodeURIComponent(token)}`, { skipRefresh: true }),
    enabled: token.length > 0,
    retry: false,
  });
}

/** Accetta un invito (utente esistente, loggato). Ricarica /me per la nuova membership. */
export function useAcceptInvitation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { token: string }) => {
      const res = await authFetch<{ organizationId?: number } | undefined>('/api/auth/invitation/accept', {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
      });

      // Il token in uso opera ancora sulla vecchia organizzazione: si passa a quella appena
      // accettata, altrimenti l'utente non vedrebbe mai i dati per cui è stato invitato.
      if (res?.organizationId) {
        try {
          useAuthStore.getState().setAccessToken(await switchOrganization(res.organizationId));
          // La cache appartiene alla vecchia organizzazione. L'anteprima dell'invito resta: la pagina
          // è ancora iscritta a quella chiave e, ora che l'invito è consumato, un refetch darebbe 400.
          qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'invitation' });
        } catch {
          // L'invito è accettato comunque: l'utente può cambiare organizzazione in seguito.
        }
      }

      const me = await authFetch<User>('/api/auth/me');
      useAuthStore.getState().setUser(me);
      return me;
    },
  });
}

interface RegisterInvitedPayload {
  token: string;
  firstName: string;
  lastName: string;
  password: string;
  locale?: 'it' | 'en';
}

/** Registra un nuovo account dall'invito e accetta in un colpo: logga l'utente. */
export function useRegisterInvited() {
  const setAuthenticated = useAuthStore((s) => s.setAuthenticated);
  return useMutation({
    mutationFn: async (payload: RegisterInvitedPayload) => {
      const data = await authFetch<{ access_token: string }>('/api/auth/invitation/register', {
        method: 'POST',
        headers: JSON_HEADERS,
        // Lingua dell'interfaccia, non 'it' fisso: altrimenti chi si registra in inglese si ritrova in italiano.
        body: JSON.stringify({ locale: profileLocale(i18n.language), ...payload }),
        skipRefresh: true,
      });
      useAuthStore.getState().setAccessToken(data.access_token);
      const me = await authFetch<User>('/api/auth/me');
      setAuthenticated(data.access_token, me);
      return me;
    },
  });
}
