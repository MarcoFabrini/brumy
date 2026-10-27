import { useRef } from 'react';
import { useMutation } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { switchOrganization } from '@/api/endpoints/auth';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';
import { useAuthStore } from '@/stores/useAuthStore';

/**
 * Cambia l'organizzazione attiva della sessione. Con il nuovo `activeOrgId` nello store la cache delle query viene
 * svuotata da `clearQueryCacheOnSessionChange` (lib/sessionCache), non qui.
 * Poi si va alla home: gli id nell'URL corrente appartengono all'organizzazione precedente.
 * In errore resta l'organizzazione attuale e compare un toast.
 *
 * `switchTo` ignora le chiamate mentre una richiesta è in corso (il ref copre anche due click nello
 * stesso tick, prima che `isPending` si aggiorni).
 */
export function useSwitchOrganization() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const inFlight = useRef(false);

  const mutation = useMutation({
    mutationFn: switchOrganization,
    onSuccess: (activeOrgId) => {
      useAuthStore.getState().setActiveOrgId(activeOrgId);
      navigate('/');
    },
    onError: (error) => toast({ title: errorMessage(error), variant: 'error' }),
    onSettled: () => {
      inFlight.current = false;
    },
  });

  const switchTo = (organizationId: number) => {
    if (inFlight.current) return;
    inFlight.current = true;
    mutation.mutate(organizationId);
  };

  return { switchTo, isPending: mutation.isPending };
}
