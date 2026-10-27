import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { switchOrganization } from '@/api/endpoints/auth';
import { enterSession, reloadUser } from '@/lib/authSession';
import { profileLocale } from '@/lib/format';
import { resyncThisDevice } from '@/lib/pushResync';
import { useAuthStore } from '@/stores/useAuthStore';

const JSON_HEADERS = { 'Content-Type': 'application/json' };

interface ProfilePayload {
  email: string;
  firstName: string;
  lastName: string;
  locale: 'it' | 'en';
  /** Obbligatoria se cambia l'email. */
  currentPassword?: string;
}

/**
 * Aggiorna profilo (nome/email/locale) e ricarica /me. Una nuova email non cambia subito l'account: diventa
 * `pendingEmail` e il server manda un link al nuovo indirizzo; la sessione resta questa. Le sessioni si chiudono solo
 * quando il link viene aperto.
 */
export function useUpdateProfile() {
  return useMutation({
    mutationFn: async (payload: ProfilePayload) => {
      await authFetch<unknown>('/api/auth/profile', {
        method: 'PUT',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
      });
      return reloadUser();
    },
  });
}

interface ChangePasswordPayload {
  currentPassword: string;
  newPassword: string;
}

/**
 * Cambia password: verifica la corrente. Questa sessione resta (il server ne rinnova id e cookie remember-me), le
 * altre si chiudono e le push subscription vengono cancellate: questo browser ri-registra la propria.
 */
export function useChangePassword() {
  return useMutation({
    mutationFn: async (payload: ChangePasswordPayload) => {
      await authFetch<void>('/api/auth/password', {
        method: 'PUT',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
      });
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
      return reloadUser();
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
        anonymous: true,
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
        anonymous: true,
      }),
  });
}

const verifyEmailResponseSchema = z.object({ emailChanged: z.boolean() });

/**
 * Conferma l'indirizzo email col token dal link (pubblico). Se il link confermava un cambio email il server ha chiuso
 * tutte le sessioni, anche questa: si rientra con il nuovo indirizzo.
 */
export function useVerifyEmail() {
  return useMutation({
    mutationFn: async (payload: { token: string }) =>
      verifyEmailResponseSchema.parse(
        await authFetch<unknown>('/api/auth/verify-email', {
          method: 'POST',
          headers: JSON_HEADERS,
          body: JSON.stringify(payload),
          anonymous: true,
        }),
      ),
    onSuccess: ({ emailChanged }) => {
      const { status, logout } = useAuthStore.getState();
      if (status !== 'authenticated') return;
      if (emailChanged) {
        logout();
        return;
      }
      // Se l'utente è già dentro, il banner "verifica l'email" deve sparire senza ricaricare.
      void reloadUser().catch(() => undefined);
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
    queryFn: () => authFetch<InvitationPreview>(`/api/auth/invitation/${encodeURIComponent(token)}`, { anonymous: true }),
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

      // La sessione opera ancora sulla vecchia organizzazione: si passa a quella appena
      // accettata, altrimenti l'utente non vedrebbe mai i dati per cui è stato invitato.
      if (res?.organizationId) {
        try {
          useAuthStore.getState().setActiveOrgId(await switchOrganization(res.organizationId));
          // La cache appartiene alla vecchia organizzazione. L'anteprima dell'invito resta: la pagina
          // è ancora iscritta a quella chiave e, ora che l'invito è consumato, un refetch darebbe 400.
          qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'invitation' });
        } catch {
          // L'invito è accettato comunque: l'utente può cambiare organizzazione in seguito.
        }
      }

      return reloadUser();
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

/** Registra un nuovo account dall'invito e accetta in un colpo: il backend apre la sessione, qui si carica /me. */
export function useRegisterInvited() {
  return useMutation({
    mutationFn: async (payload: RegisterInvitedPayload) => {
      await authFetch<unknown>('/api/auth/invitation/register', {
        method: 'POST',
        headers: JSON_HEADERS,
        // Lingua dell'interfaccia, non 'it' fisso: altrimenti chi si registra in inglese si ritrova in italiano.
        body: JSON.stringify({ locale: profileLocale(i18n.language), ...payload }),
        anonymous: true,
      });
      return enterSession();
    },
  });
}
