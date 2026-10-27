import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { vehicleKeys } from '@/hooks/useVehicles';
import { reloadUser } from '@/lib/authSession';
import { useAuthStore } from '@/stores/useAuthStore';

const JSON_HEADERS = { 'Content-Type': 'application/json' };

export type MemberRole = 'owner' | 'admin' | 'member';

export interface OrgMember {
  id: number;
  role: MemberRole;
  acceptedAt: string | null;
  user: { id: number; email: string; firstName: string; lastName: string };
}

export interface OrgInvitation {
  id: number;
  email: string;
  role: Exclude<MemberRole, 'owner'>;
  createdAt: string;
}

const membersKey = (orgId: number) => ['organizations', orgId, 'members'] as const;
const invitationsKey = (orgId: number) => ['organizations', orgId, 'invitations'] as const;

/** Membri (accettati) dell'organizzazione. Owner/admin only (403 altrimenti). */
export function useMembers(orgId: number, enabled = true) {
  return useQuery({
    queryKey: membersKey(orgId),
    queryFn: () => authFetch<OrgMember[]>(`/api/organizations/${orgId}/members`),
    enabled,
  });
}

/** Inviti pendenti dell'organizzazione (email ancora da accettare). */
export function useInvitations(orgId: number, enabled = true) {
  return useQuery({
    queryKey: invitationsKey(orgId),
    queryFn: () => authFetch<OrgInvitation[]>(`/api/organizations/${orgId}/invitations`),
    enabled,
  });
}

/** Invita un'email (anche non registrata: l'utente si crea l'account in accettazione). */
export function useInviteMember(orgId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: { email: string; role: Exclude<MemberRole, 'owner'> }) =>
      authFetch<OrgInvitation>(`/api/organizations/${orgId}/members`, {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify(payload),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: invitationsKey(orgId) });
      void qc.invalidateQueries({ queryKey: membersKey(orgId) });
    },
  });
}

/** Revoca un invito pendente. */
export function useRevokeInvitation(orgId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (invitationId: number) =>
      authFetch<void>(`/api/organizations/${orgId}/invitations/${invitationId}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: invitationsKey(orgId) }),
  });
}

/**
 * Rimuove un membro accettato (owner/admin). L'owner non è rimovibile.
 *
 * Il backend passa i veicoli del membro a chi lo rimuove, fa cadere le sue condivisioni e elimina
 * gli inviti che aveva spedito: oltre a membri e inviti si invalidano tutti i veicoli
 * (`vehicleKeys.all` copre lista, dettagli con ownership/permessi, condivisioni e candidati alla
 * condivisione, che stanno tutti sotto `['vehicles', …]`).
 */
export function useRemoveMember(orgId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (memberId: number) =>
      authFetch<void>(`/api/organizations/${orgId}/members/${memberId}`, { method: 'DELETE' }),
    onSuccess: () =>
      Promise.all([
        qc.invalidateQueries({ queryKey: membersKey(orgId) }),
        qc.invalidateQueries({ queryKey: invitationsKey(orgId) }),
        qc.invalidateQueries({ queryKey: vehicleKeys.all }),
      ]),
  });
}

interface ChangeMemberRoleVars {
  memberId: number;
  role: MemberRole;
  /** Il membro è l'utente corrente: dopo il cambio le sue membership nello store vanno rilette. */
  self: boolean;
}

/**
 * Allinea lo store al nuovo ruolo dell'utente corrente: rilegge `/api/auth/me`; se la rilettura fallisce
 * (il ruolo è comunque già cambiato) usa il ruolo del PATCH sulla membership corrispondente. Mai un errore.
 */
async function syncOwnMembership(updated: OrgMember): Promise<void> {
  const store = useAuthStore.getState();
  try {
    await reloadUser();
  } catch {
    const user = useAuthStore.getState().user;
    if (user) {
      store.setUser({
        ...user,
        memberships: user.memberships.map((m) => (m.id === updated.id ? { ...m, role: updated.role } : m)),
      });
    }
  }
}

/**
 * Cambia il ruolo d'organizzazione di un membro (`PATCH .../members/{memberId}`; la matrice dei permessi
 * è del backend, vedi `lib/memberRoles`). Un declassamento elimina gli inviti pendenti del membro e il
 * ruolo cambia i veicoli che l'utente vede: si invalidano membri, inviti e tutti i veicoli.
 *
 * Se è l'utente corrente a cambiare il PROPRIO ruolo si rilegge `/api/auth/me` e si aggiorna lo store
 * (come `useUpdateOrganization`), così `SettingsPage` e `MembersCard` riflettono subito il nuovo ruolo.
 * Se la rilettura fallisce non è un errore dell'operazione (il ruolo è già cambiato): niente toast fuorviante,
 * le invalidazioni partono comunque. Il ruolo di un ALTRO membro si aggiorna sul suo dispositivo al prossimo caricamento: il backend lo
 * fa comunque rispettare dalla richiesta successiva.
 */
export function useChangeMemberRole(orgId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ memberId, role, self }: ChangeMemberRoleVars) => {
      const updated = await authFetch<OrgMember>(`/api/organizations/${orgId}/members/${memberId}`, {
        method: 'PATCH',
        headers: JSON_HEADERS,
        body: JSON.stringify({ role }),
      });
      if (self) await syncOwnMembership(updated);
      return updated;
    },
    onSuccess: () =>
      Promise.all([
        qc.invalidateQueries({ queryKey: membersKey(orgId) }),
        qc.invalidateQueries({ queryKey: invitationsKey(orgId) }),
        qc.invalidateQueries({ queryKey: vehicleKeys.all }),
      ]),
  });
}
