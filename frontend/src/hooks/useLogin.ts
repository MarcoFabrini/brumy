import { useMutation } from '@tanstack/react-query';
import { ApiError } from '@/api/client';
import { login, logout, verifyTwoFactor } from '@/api/endpoints/auth';
import type { SignInResult } from '@/api/types/auth';
import { enterSession } from '@/lib/authSession';
import { useAuthStore } from '@/stores/useAuthStore';

interface LoginPayload {
  email: string;
  password: string;
}

/**
 * Primo passo del login. Senza verifica in due passaggi apre subito la sessione (`/me` nello store); con la verifica
 * attiva lo store passa a 'two_factor' e la pagina mostra il campo del codice.
 */
export function useLogin() {
  return useMutation({
    mutationFn: async ({ email, password }: LoginPayload): Promise<SignInResult> => {
      const result = await login(email, password);
      if (result.twoFactorRequired) {
        useAuthStore.getState().setTwoFactorPending();
      } else {
        await enterSession();
      }
      return result;
    },
  });
}

/**
 * Secondo passo: codice dell'app o codice di recupero; se valido apre la sessione. Un 401 diverso dal codice sbagliato
 * vuol dire che la verifica non è più in corso (sessione scaduta o chiusa altrove): si torna al primo passo.
 */
export function useVerifyTwoFactor() {
  return useMutation({
    mutationFn: async (code: string) => {
      try {
        await verifyTwoFactor(code);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401 && e.title !== 'auth.invalid_two_factor_code') {
          useAuthStore.getState().setUnauthenticated();
        }
        throw e;
      }
      return enterSession();
    },
  });
}

/** Annulla la verifica in corso: chiude la sessione a metà e torna al primo passo. */
export function useCancelTwoFactor() {
  return useMutation({
    mutationFn: async () => {
      try {
        await logout();
      } finally {
        useAuthStore.getState().setUnauthenticated();
      }
    },
  });
}
