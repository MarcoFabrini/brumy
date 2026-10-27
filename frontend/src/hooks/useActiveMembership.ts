import { useAuthStore } from '@/stores/useAuthStore';

/**
 * Membership dell'organizzazione ATTIVA della sessione (`activeOrganizationId` di `/me`, poi `switch-org`): è l'org
 * su cui opera davvero ogni chiamata API. Se manca o non corrisponde a nessuna membership: owner/admin se presente,
 * altrimenti la prima. Source unica per topbar / sidebar / impostazioni.
 */
export function useActiveMembership() {
  const user = useAuthStore((s) => s.user);
  const activeOrgId = useAuthStore((s) => s.activeOrgId);
  return (
    user?.memberships.find((m) => m.organization.id === activeOrgId) ??
    user?.memberships.find((m) => m.role === 'owner' || m.role === 'admin') ??
    user?.memberships[0]
  );
}
