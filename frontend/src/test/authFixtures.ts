import type { Me, User } from '@/api/types/auth';

/** Utente completo (forma di `/me` senza l'org attiva), con i campi da sovrascrivere nel singolo test. */
export function testUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    email: 'mario@test.it',
    pendingEmail: null,
    firstName: 'Mario',
    lastName: 'Rossi',
    locale: 'it',
    hasAvatar: false,
    emailVerified: true,
    isInstanceAdmin: false,
    twoFactorEnabled: false,
    backupCodesRemaining: 0,
    memberships: [{ id: 1, role: 'owner', organization: { id: 10, name: 'Famiglia Rossi', slug: 'famiglia-rossi' } }],
    ...overrides,
  };
}

/** Risposta di `GET /api/auth/me`: l'utente più l'org attiva della sessione (di default la prima membership). */
export function testMe(overrides: Partial<Me> = {}): Me {
  const user = testUser(overrides);
  return { ...user, activeOrganizationId: user.memberships[0]?.organization.id ?? null, ...overrides };
}
