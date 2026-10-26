/**
 * Registrazione del service worker e rilevamento delle nuove versioni.
 *
 * Il SW precacha index.html e gli asset e li serve dalla precache: senza controlli attivi una PWA
 * già aperta resta sulla versione vecchia finché non viene riavviata. Qui si controlla quando l'app
 * torna in primo piano e ogni ora, e quando un nuovo worker prende il controllo si avvisa l'utente
 * (hooks/useUpdatePrompt). Niente reload automatico: potrebbe buttare via un form mezzo compilato.
 *
 * Nessuna interazione con lib/chunkRecovery: questo modulo non ricarica mai da solo, ricaricano solo
 * `refreshApp` e `refreshInPlace` su azione esplicita dell'utente, e dopo un reload il nuovo worker controlla già la pagina
 * (nessun `controllerchange`, quindi nessun avviso e nessun secondo reload).
 */

export const UPDATE_CHECK_INTERVAL_MS = 60 * 60 * 1000;
/** Attesa massima del nuovo worker durante `refreshApp`: offline o rete lenta non devono bloccare il reload. */
export const REFRESH_TIMEOUT_MS = 6_000;

// --- Stato "nuova versione disponibile" (letto con useSyncExternalStore) ---

let updateAvailable = false;
/** Durante un aggiornamento su richiesta la pagina sta per ricaricarsi: il controllerchange che ne segue non va segnalato. */
let refreshing = false;
const listeners = new Set<() => void>();

export function subscribeUpdateAvailable(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function getUpdateAvailable(): boolean {
  return updateAvailable;
}

function markUpdateAvailable(): void {
  if (updateAvailable || refreshing) return;
  updateAvailable = true;
  listeners.forEach((listener) => listener());
}

/** Solo per i test: riporta lo stato del modulo a quello iniziale. */
export function resetUpdateStateForTests(): void {
  updateAvailable = false;
  refreshing = false;
  listeners.clear();
}

// --- Registrazione ---

/**
 * Il SW esiste solo nella build (`vite build`, anche `--watch`): `vite dev` non lo produce e
 * `/sw.js` darebbe l'index.html della SPA come "script".
 */
export function canUseServiceWorker(): boolean {
  return import.meta.env.PROD && typeof navigator !== 'undefined' && 'serviceWorker' in navigator;
}

/** Registra '/sw.js' (output di vite-plugin-pwa, strategia injectManifest, scope '/') e avvia i controlli. */
export function startServiceWorker(): void {
  if (!canUseServiceWorker()) return;
  const register = () => {
    navigator.serviceWorker
      .register('/sw.js', { scope: '/' })
      .then((registration) => watchForUpdates(registration))
      .catch(() => {
        // Contesto non sicuro o sw.js irraggiungibile: l'app funziona lo stesso, senza offline né push.
      });
  };
  if (document.readyState === 'complete') register();
  else window.addEventListener('load', register, { once: true });
}

// --- Rilevamento aggiornamenti ---

export interface WatchEnv {
  container: Pick<ServiceWorkerContainer, 'addEventListener' | 'removeEventListener' | 'controller'>;
  doc: Pick<Document, 'addEventListener' | 'removeEventListener' | 'visibilityState'>;
  setInterval: (handler: () => void, ms: number) => number;
  clearInterval: (id: number) => void;
}

function defaultEnv(): WatchEnv {
  return {
    container: navigator.serviceWorker,
    doc: document,
    setInterval: (handler, ms) => window.setInterval(handler, ms),
    clearInterval: (id) => window.clearInterval(id),
  };
}

/** Un controllo che fallisce (offline) non è un problema: riprova alla prossima occasione. */
function checkQuietly(registration: Pick<ServiceWorkerRegistration, 'update'>): void {
  registration.update().catch(() => undefined);
}

/**
 * Controlla gli aggiornamenti quando l'app torna visibile e ogni ora, e segnala quando un nuovo
 * worker prende il controllo di una pagina già controllata (alla prima installazione il
 * `controllerchange` è solo il `clients.claim()` del primo avvio: nessuna nuova versione).
 * Ritorna la funzione che toglie tutto.
 */
export function watchForUpdates(
  registration: Pick<ServiceWorkerRegistration, 'update' | 'waiting'>,
  env: WatchEnv = defaultEnv(),
): () => void {
  let hadController = env.container.controller !== null;

  const onControllerChange = () => {
    if (!hadController) {
      hadController = true;
      return;
    }
    markUpdateAvailable();
  };
  const onVisibility = () => {
    if (env.doc.visibilityState === 'visible') checkQuietly(registration);
  };

  env.container.addEventListener('controllerchange', onControllerChange);
  env.doc.addEventListener('visibilitychange', onVisibility);
  const timer = env.setInterval(() => {
    if (env.doc.visibilityState === 'visible') checkQuietly(registration);
  }, UPDATE_CHECK_INTERVAL_MS);

  // Un worker già in attesa di una pagina già controllata è una versione nuova non ancora attiva.
  if (registration.waiting && hadController) markUpdateAvailable();

  return () => {
    env.container.removeEventListener('controllerchange', onControllerChange);
    env.doc.removeEventListener('visibilitychange', onVisibility);
    env.clearInterval(timer);
  };
}

// --- Aggiornamento su richiesta (pull-to-refresh e pulsante "Aggiorna") ---

/** Risolve alla scadenza o quando `promise` finisce, qualunque esito: serve solo ad attendere. */
function settleWithin(promise: Promise<unknown>, ms: number): Promise<void> {
  return new Promise<void>((resolve) => {
    const timer = setTimeout(resolve, ms);
    const done = () => {
      clearTimeout(timer);
      resolve();
    };
    promise.then(done, done);
  });
}

/** Aspetta che un worker appena trovato diventi attivo (o fallisca): solo allora il reload serve la versione nuova. */
function waitForActivation(worker: ServiceWorker): Promise<void> {
  return new Promise<void>((resolve) => {
    const check = () => {
      if (worker.state === 'activated' || worker.state === 'redundant') {
        worker.removeEventListener('statechange', check);
        resolve();
      }
    };
    worker.addEventListener('statechange', check);
    check();
  });
}

/**
 * Cerca una nuova versione e aspetta (al massimo REFRESH_TIMEOUT_MS) che il nuovo worker sia attivo: solo allora un
 * reload serve l'index.html nuovo, senza l'attesa arriverebbe dalla precache del worker vecchio. True se l'ha trovata;
 * offline o in errore risponde false.
 */
async function installNewVersion(): Promise<boolean> {
  if (!canUseServiceWorker()) return false;
  let found = false;
  try {
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration) return false;
    await settleWithin(
      (async () => {
        await registration.update();
        const worker = registration.installing ?? registration.waiting;
        if (!worker) return;
        found = true;
        // La pagina sta per ricaricarsi: il controllerchange del nuovo worker non è un avviso da mostrare
        refreshing = true;
        await waitForActivation(worker);
      })(),
      REFRESH_TIMEOUT_MS,
    );
  } catch {
    // Registrazione illeggibile: nessuna versione nuova da prendere
  }
  return found;
}

/** Pulsante "Aggiorna" dell'avviso: prende la versione nuova se c'è e ricarica comunque, anche offline o in errore. */
export async function refreshApp(reload: () => void = () => window.location.reload()): Promise<void> {
  refreshing = true;
  try {
    await installNewVersion();
  } finally {
    reload();
  }
}

/**
 * Pull-to-refresh: se c'è una versione nuova (già segnalata o appena trovata) ricarica, l'unico modo di prenderla;
 * altrimenti aggiorna solo i dati con `refetch`, senza toccare la pagina: niente schermo bianco né scheletri.
 */
export async function refreshInPlace(
  refetch: () => Promise<unknown>,
  reload: () => void = () => window.location.reload(),
): Promise<void> {
  if (updateAvailable || (await installNewVersion())) {
    refreshing = true;
    reload();
    return;
  }
  await refetch();
}
