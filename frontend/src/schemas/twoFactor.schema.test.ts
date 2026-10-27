import { describe, expect, it } from 'vitest';
import { twoFactorAppCodeSchema, twoFactorConfirmSchema, twoFactorRecoveryCodeSchema } from './twoFactor.schema';

const firstMessage = (r: { success: boolean; error?: { issues: Array<{ message: string }> } }) => r.error?.issues[0]?.message;

describe('schemi della verifica in due passaggi', () => {
  it('il codice dell\'app ha 6 cifre (spazi ammessi)', () => {
    expect(twoFactorAppCodeSchema.safeParse({ code: '123456' }).success).toBe(true);
    expect(twoFactorAppCodeSchema.safeParse({ code: '123 456' }).success).toBe(true);
    expect(firstMessage(twoFactorAppCodeSchema.safeParse({ code: '12345' }))).toBe('two_factor.code_format');
    expect(firstMessage(twoFactorAppCodeSchema.safeParse({ code: '12345a' }))).toBe('two_factor.code_format');
  });

  it('il codice di recupero ha 12 caratteri, maiuscole, spazi e trattini non contano', () => {
    expect(twoFactorRecoveryCodeSchema.safeParse({ code: 'abcd-efgh-jkmn' }).success).toBe(true);
    expect(twoFactorRecoveryCodeSchema.safeParse({ code: 'ABCD EFGH JKMN' }).success).toBe(true);
    expect(firstMessage(twoFactorRecoveryCodeSchema.safeParse({ code: 'abcd-efgh' }))).toBe('two_factor.recovery_code_format');
  });

  it('per disattivare vale l\'uno o l\'altro, e l\'errore segue quel che si sta scrivendo', () => {
    const parse = (code: string) => twoFactorConfirmSchema.safeParse({ currentPassword: 'x', code });
    expect(parse('123456').success).toBe(true);
    expect(parse('abcd-efgh-jkmn').success).toBe(true);
    expect(firstMessage(parse('1234'))).toBe('two_factor.code_format');
    expect(firstMessage(parse('abcd-ef'))).toBe('two_factor.recovery_code_format');
    expect(firstMessage(twoFactorConfirmSchema.safeParse({ currentPassword: '', code: '123456' }))).toBe('account.password.required');
  });
});
