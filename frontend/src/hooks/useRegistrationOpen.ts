import { useQuery } from '@tanstack/react-query';
import { authFetch } from '@/api/client';

export const registrationKey = ['auth', 'registration'] as const;

/**
 * La registrazione libera esiste solo finché l'istanza non ha utenti (primo
 * avvio: il primo account diventa admin). Poi si entra solo su invito.
 * Mai dati in cache: un valore vecchio "aperta" farebbe comparire per un attimo
 * il link/la pagina di registrazione su un'istanza già chiusa.
 */
export function useRegistrationOpen() {
  return useQuery({
    queryKey: registrationKey,
    queryFn: () => authFetch<{ open: boolean }>('/api/auth/registration', { anonymous: true }),
    staleTime: 0,
    gcTime: 0,
    retry: false,
  });
}
