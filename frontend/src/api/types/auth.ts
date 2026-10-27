import { z } from 'zod';

/** Risposta del login e del codice della verifica in due passaggi: `true` se dopo la password serve ancora il codice. */
export const signInResultSchema = z.object({ twoFactorRequired: z.boolean() });
export type SignInResult = z.infer<typeof signInResultSchema>;

export const ORG_ROLES = ['owner', 'admin', 'member'] as const;

/** L'utente collegato, com'è dentro `GET /api/auth/me` (senza l'org attiva della sessione). */
export const userSchema = z.object({
  id: z.number().int(),
  email: z.string(),
  /** Nuovo indirizzo chiesto dal profilo, in attesa del link di conferma (l'email dell'account non cambia finché non lo apre). */
  pendingEmail: z.string().nullable(),
  firstName: z.string(),
  lastName: z.string(),
  locale: z.enum(['it', 'en']),
  hasAvatar: z.boolean(),
  emailVerified: z.boolean(),
  /** Primo utente registrato sull'istanza: unico autorizzato a VAPID di istanza (SMTP è solo env). */
  isInstanceAdmin: z.boolean(),
  twoFactorEnabled: z.boolean(),
  backupCodesRemaining: z.number().int().nonnegative(),
  memberships: z.array(
    z.object({
      id: z.number().int(),
      role: z.enum(ORG_ROLES),
      organization: z.object({ id: z.number().int(), name: z.string(), slug: z.string() }),
    }),
  ),
});
export type User = z.infer<typeof userSchema>;

/** `GET /api/auth/me`: l'utente più l'organizzazione attiva di questa sessione (null senza membership accettate). */
export const meSchema = userSchema.extend({ activeOrganizationId: z.number().int().nullable() });
export type Me = z.infer<typeof meSchema>;

export const switchOrganizationResultSchema = z.object({ activeOrganizationId: z.number().int() });

/** Configurazione iniziata: segreto da inserire a mano, link `otpauth://` e QR (SVG come data URI). */
export const twoFactorSetupSchema = z.object({
  secret: z.string().min(1),
  otpauthUri: z.string().startsWith('otpauth://'),
  qrCode: z.string().startsWith('data:image/svg+xml'),
});
export type TwoFactorSetup = z.infer<typeof twoFactorSetupSchema>;

/** Codici di recupero in chiaro: si vedono solo nella risposta che li crea. */
export const backupCodesSchema = z.object({ backupCodes: z.array(z.string()).min(1) });

/** Password attuale + codice (dell'app; per disattivare e rigenerare vale anche un codice di recupero). */
export interface TwoFactorCredentials {
  currentPassword: string;
  code: string;
}
