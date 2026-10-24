import { useSyncExternalStore } from 'react';

const QUERY = '(min-width: 768px)';

function subscribe(onChange: () => void) {
  if (typeof window.matchMedia !== 'function') return () => {};
  const mq = window.matchMedia(QUERY);
  mq.addEventListener('change', onChange);
  return () => mq.removeEventListener('change', onChange);
}

/**
 * True nel layout desktop (da `md`, come la sidebar). Serve dove il gesto cambia col dispositivo, non solo l'aspetto:
 * es. la condivisione del veicolo apre una finestra su desktop e una pagina sul telefono.
 */
export function useIsDesktop(): boolean {
  return useSyncExternalStore(
    subscribe,
    () => typeof window.matchMedia === 'function' && window.matchMedia(QUERY).matches,
    () => false,
  );
}
