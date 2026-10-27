import type { QueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/stores/useAuthStore';

type Session = { user: { id: number } | null; activeOrgId: number | null };

/**
 * True se la cache delle query non appartiene più alla sessione corrente:
 * - l'utente è uscito o è cambiato (cache di un altro utente mai visibile);
 * - l'organizzazione attiva passa da un valore a un ALTRO valore (switch-org, accettazione di un invito): i dati in
 *   cache sono dell'org precedente. Da/verso null no: è la sessione che si apre o si chiude (login, bootstrap,
 *   logout), già coperta dal cambio di utente.
 */
export function sessionCacheIsStale(next: Session, prev: Session): boolean {
  if (prev.user && (!next.user || next.user.id !== prev.user.id)) return true;

  return prev.activeOrgId !== null && next.activeOrgId !== null && prev.activeOrgId !== next.activeOrgId;
}

/**
 * Svuota la cache di `queryClient` quando la sessione cambia (vedi {@link sessionCacheIsStale}),
 * in un unico punto invece che nei singoli hook. Ritorna la funzione per smettere di ascoltare.
 */
export function clearQueryCacheOnSessionChange(queryClient: Pick<QueryClient, 'clear'>): () => void {
  return useAuthStore.subscribe((state, prev) => {
    if (sessionCacheIsStale(state, prev)) queryClient.clear();
  });
}
