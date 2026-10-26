/** Posizione del telefono, arrotondata: per trovare il distributore più vicino bastano ~10 metri. */
export interface GeoPosition {
  latitude: number;
  longitude: number;
}

/** Perché la posizione non è arrivata: negata, non disponibile (niente GPS, pagina non HTTPS) o troppo lenta. */
export type GeoError = 'denied' | 'unavailable' | 'timeout';

const TIMEOUT_MS = 10_000;
/** Una posizione di pochi minuti fa va benissimo: niente attesa del GPS se il telefono la sa già. */
const MAX_AGE_MS = 5 * 60 * 1000;

const round = (n: number) => Math.round(n * 10_000) / 10_000;

/**
 * Chiede la posizione al telefono (la prima volta il browser mostra il suo avviso). Va chiamata da un'azione
 * dell'utente, mai all'apertura della pagina. Il browser la dà solo su HTTPS (o localhost).
 */
export function requestPosition(geolocation: Geolocation | undefined = globalThis.navigator?.geolocation): Promise<GeoPosition> {
  return new Promise((resolve, reject: (reason: GeoError) => void) => {
    if (!geolocation) {
      reject('unavailable');
      return;
    }
    geolocation.getCurrentPosition(
      (p) => resolve({ latitude: round(p.coords.latitude), longitude: round(p.coords.longitude) }),
      (e) => reject(e.code === e.PERMISSION_DENIED ? 'denied' : e.code === e.TIMEOUT ? 'timeout' : 'unavailable'),
      { enableHighAccuracy: true, timeout: TIMEOUT_MS, maximumAge: MAX_AGE_MS },
    );
  });
}
