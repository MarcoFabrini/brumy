/**
 * Recupero dai chunk JS spariti. Dopo un deploy gli asset hanno hash nuovi e quelli vecchi non
 * esistono più (il service worker pulisce anche le precache obsolete): una scheda rimasta aperta
 * che apre una pagina in lazy chiede un file che dà 404. L'unico rimedio è ricaricare, che scarica
 * l'index.html nuovo. Si fa UNA volta: se il file manca ancora (rete assente, server giù) un secondo
 * reload sarebbe un ciclo, e si lascia l'errore all'ErrorBoundary.
 */

const STORAGE_KEY = 'brumy:chunk-reload-at';

/** Entro questo tempo da un reload "di recupero" non se ne fa un altro (copre anche un flag rimasto sporco). */
const RELOAD_WINDOW_MS = 60_000;

/** Fallback se sessionStorage non è usabile: vale per la vita della pagina (più errori simultanei = un reload). */
let reloadedInThisPage = false;

function readStamp(): number | null {
  try {
    const raw = window.sessionStorage.getItem(STORAGE_KEY);
    const stamp = raw === null ? NaN : Number(raw);
    return Number.isFinite(stamp) ? stamp : null;
  } catch {
    return null; // storage bloccato (privacy, quota, iframe): si prosegue senza
  }
}

function writeStamp(now: number): void {
  try {
    window.sessionStorage.setItem(STORAGE_KEY, String(now));
  } catch {
    // vedi readStamp
  }
}

/**
 * Ricarica la pagina se non l'abbiamo già fatto da poco per lo stesso motivo. True se ha ricaricato
 * (il chiamante non deve far comparire un errore: la pagina sta per essere sostituita).
 */
export function reloadOnceForChunkError(
  reload: () => void = () => window.location.reload(),
  now: number = Date.now(),
): boolean {
  if (reloadedInThisPage) return false;
  const last = readStamp();
  if (last !== null && now - last < RELOAD_WINDOW_MS) return false;

  reloadedInThisPage = true;
  writeStamp(now);
  reload();
  return true;
}

/** Un chunk è stato caricato bene: il prossimo errore di chunk potrà di nuovo ricaricare. */
export function clearChunkReloadFlag(): void {
  reloadedInThisPage = false;
  try {
    window.sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    // niente da pulire
  }
}

const CHUNK_ERROR_PATTERN =
  /dynamically imported module|importing a module script failed|unable to preload|loading (?:css )?chunk .* failed/i;

/** L'errore è un import dinamico fallito (messaggi di Chrome, Firefox, Safari e dei bundler). */
export function isChunkLoadError(error: unknown): boolean {
  return error instanceof Error && (error.name === 'ChunkLoadError' || CHUNK_ERROR_PATTERN.test(error.message));
}

/**
 * Ascolta `vite:preloadError`, che Vite emette quando il precaricamento (o l'import) di un chunk
 * fallisce. Se ricarica, `preventDefault` evita che l'errore arrivi all'ErrorBoundary per un attimo;
 * se non può (già ricaricato) lascia che l'errore si propaghi. Ritorna la funzione per smettere.
 */
export function installChunkErrorRecovery(target: Window = window): () => void {
  const onPreloadError = (event: Event) => {
    if (reloadOnceForChunkError()) event.preventDefault();
  };
  target.addEventListener('vite:preloadError', onPreloadError);
  return () => target.removeEventListener('vite:preloadError', onPreloadError);
}
