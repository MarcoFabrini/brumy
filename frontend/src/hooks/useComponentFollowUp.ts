import { useNavigate } from 'react-router-dom';
import type { Maintenance } from '@/api/types/maintenance';
import { componentFollowUpPath, newComponentIds } from '@/lib/componentNames';

/**
 * Dopo il salvataggio di un lavoro: se ha creato componenti si apre la scheda del primo da completare (gli altri
 * in coda, poi `back`); altrimenti si va su `fallback` (null = si resta dove si è, come nel "+"), con `state` (lo
 * state del dettaglio, per tornare all'elenco con la sua ricerca).
 */
export function useComponentFollowUp() {
  const navigate = useNavigate();
  return (saved: Maintenance, newComponents: string[], fallback: string | null, state?: unknown) => {
    const next = componentFollowUpPath(newComponentIds(saved.components, newComponents), `/maintenance/${saved.id}`);
    if (next) navigate(next);
    else if (fallback) navigate(fallback, { replace: true, state });
  };
}
