import { useMutation, useQueryClient } from '@tanstack/react-query';
import { registrationKey } from '@/hooks/useRegistrationOpen';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import { enterSession } from '@/lib/authSession';
import { profileLocale } from '@/lib/format';

interface RegisterPayload {
  email: string;
  password: string;
  firstName: string;
  lastName: string;
  locale?: 'it' | 'en';
}

/**
 * useRegister — registrazione del primo utente: il backend apre già la sessione (cookie), qui si carica `/me`.
 */
export function useRegister() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: RegisterPayload) => {
      await authFetch<unknown>('/api/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        // Il profilo nasce nella lingua dell'interfaccia: così syncLanguage non la riporta in italiano.
        body: JSON.stringify({ locale: profileLocale(i18n.language), ...payload }),
        anonymous: true,
      });
      const me = await enterSession();
      // Da ora l'istanza ha un utente: registrazione libera chiusa.
      queryClient.setQueryData(registrationKey, { open: false });
      return me;
    },
  });
}
