import { z } from 'zod';

/** Codice dell'app di autenticazione: 6 cifre (gli spazi che alcune app mostrano a metà non contano). */
export function isAuthenticatorCode(value: string): boolean {
  return /^\d{6}$/.test(value.replace(/\s/g, ''));
}

/**
 * Codice di recupero: 12 caratteri dell'alfabeto Crockford base32, mostrati come `xxxx-xxxx-xxxx`; maiuscole, spazi e
 * trattini non contano (il backend normalizza allo stesso modo).
 */
export function isRecoveryCode(value: string): boolean {
  return /^[0-9a-z]{12}$/.test(value.replace(/[\s-]/g, '').toLowerCase());
}

const authenticatorCode = z.string().refine(isAuthenticatorCode, 'two_factor.code_format');
const recoveryCode = z.string().refine(isRecoveryCode, 'two_factor.recovery_code_format');

/** Secondo passo del login, con il codice dell'app. */
export const twoFactorAppCodeSchema = z.object({ code: authenticatorCode });
/** Secondo passo del login, con un codice di recupero. */
export const twoFactorRecoveryCodeSchema = z.object({ code: recoveryCode });
export type TwoFactorCodeFormData = z.infer<typeof twoFactorAppCodeSchema>;

const currentPassword = z.string().min(1, 'account.password.required').max(200, 'common.too_long');

/** Attivazione: password attuale + codice dell'app appena configurata. */
export const twoFactorEnableSchema = z.object({ currentPassword, code: authenticatorCode });

/**
 * Disattivazione e codici nuovi: password attuale + codice dell'app o di recupero. L'errore di formato segue quel che
 * l'utente sta scrivendo: lettere o più di 6 caratteri → codice di recupero.
 */
export const twoFactorConfirmSchema = z.object({
  currentPassword,
  code: z.string().superRefine((value, ctx) => {
    if (isAuthenticatorCode(value) || isRecoveryCode(value)) return;
    const looksLikeRecovery = /[a-z]/i.test(value) || value.replace(/[\s-]/g, '').length > 6;
    ctx.addIssue({ code: 'custom', message: looksLikeRecovery ? 'two_factor.recovery_code_format' : 'two_factor.code_format' });
  }),
});
export type TwoFactorCredentialsFormData = z.infer<typeof twoFactorConfirmSchema>;
