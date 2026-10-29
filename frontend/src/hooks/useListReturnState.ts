import { useLocation } from 'react-router-dom';
import type { ListReturnState } from '@/lib/listReturn';

/**
 * `state` per il link da una riga di elenco al suo dettaglio: la pagina attuale con la sua query (`?q=` compresa), che
 * il dettaglio usa per il "‹ Indietro" ({@link import('@/lib/listReturn').listReturnPath}).
 */
export function useListReturnState(): ListReturnState {
  const { pathname, search } = useLocation();
  return { from: pathname + search };
}
