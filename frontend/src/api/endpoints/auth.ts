import { authFetch } from '@/api/client';
import {
  backupCodesSchema,
  meSchema,
  signInResultSchema,
  switchOrganizationResultSchema,
  twoFactorSetupSchema,
  type Me,
  type SignInResult,
  type TwoFactorCredentials,
  type TwoFactorSetup,
} from '@/api/types/auth';

const JSON_HEADERS = { 'Content-Type': 'application/json' };

/** Primo passo del login: apre la sessione, oppure la lascia in attesa del codice (`twoFactorRequired`). */
export async function login(email: string, password: string): Promise<SignInResult> {
  return signInResultSchema.parse(
    await authFetch<unknown>('/api/auth/login', {
      method: 'POST',
      headers: JSON_HEADERS,
      body: JSON.stringify({ email, password }),
      anonymous: true,
    }),
  );
}

/** Secondo passo: codice dell'app di autenticazione o codice di recupero. */
export async function verifyTwoFactor(authCode: string): Promise<SignInResult> {
  return signInResultSchema.parse(
    await authFetch<unknown>('/api/auth/2fa/check', {
      method: 'POST',
      headers: JSON_HEADERS,
      body: JSON.stringify({ authCode }),
      anonymous: true,
    }),
  );
}

/** Chiude la sessione (anche a verifica in due passaggi in corso). Risponde 204 anche senza sessione. */
export async function logout(): Promise<void> {
  await authFetch<void>('/api/auth/logout', { method: 'POST', anonymous: true });
}

/**
 * Chi è collegato. `anonymous` per il bootstrap, dove un 401 vuol dire solo "nessuna sessione" e lo decide il
 * chiamante.
 */
export async function getMe(options: { anonymous?: boolean } = {}): Promise<Me> {
  return meSchema.parse(await authFetch<unknown>('/api/auth/me', { anonymous: options.anonymous }));
}

/**
 * Cambia l'organizzazione attiva della sessione (il backend la ricorda anche per le sessioni nuove). Ritorna l'id
 * attivo. Unica implementazione, condivisa da `useSwitchOrganization` e da `useAcceptInvitation`.
 */
export async function switchOrganization(organizationId: number): Promise<number> {
  const res = switchOrganizationResultSchema.parse(
    await authFetch<unknown>('/api/auth/switch-org', {
      method: 'POST',
      headers: JSON_HEADERS,
      body: JSON.stringify({ organizationId }),
    }),
  );
  return res.activeOrganizationId;
}

/** Nuovo segreto TOTP in attesa di conferma (sostituisce una configurazione non confermata). */
export async function beginTwoFactorSetup(): Promise<TwoFactorSetup> {
  return twoFactorSetupSchema.parse(await authFetch<unknown>('/api/auth/2fa/setup', { method: 'POST' }));
}

/** Attiva la verifica in due passaggi. Ritorna i codici di recupero, mostrati solo ora. */
export async function enableTwoFactor(credentials: TwoFactorCredentials): Promise<string[]> {
  return postForBackupCodes('/api/auth/2fa/enable', credentials);
}

export async function disableTwoFactor(credentials: TwoFactorCredentials): Promise<void> {
  await authFetch<void>('/api/auth/2fa/disable', {
    method: 'POST',
    headers: JSON_HEADERS,
    body: JSON.stringify(credentials),
  });
}

/** Codici di recupero nuovi: i precedenti smettono di valere. */
export async function regenerateBackupCodes(credentials: TwoFactorCredentials): Promise<string[]> {
  return postForBackupCodes('/api/auth/2fa/backup-codes', credentials);
}

async function postForBackupCodes(path: string, credentials: TwoFactorCredentials): Promise<string[]> {
  const res = backupCodesSchema.parse(
    await authFetch<unknown>(path, { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify(credentials) }),
  );
  return res.backupCodes;
}
