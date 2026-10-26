/**
 * Logica pura del gesto "trascina verso il basso per aggiornare" (vedi hooks/usePullToRefresh).
 * Tenuta fuori dal DOM e dagli eventi così soglie e regole di esclusione si testano a numeri.
 */

/** Spostamento (px del dito) sotto il quale non si decide ancora se è un tap, uno scroll o un pull. */
export const GESTURE_SLOP = 8;
/** Il dito deve andare in giù almeno così tanto più che di lato: i gesti diagonali non fanno pull. */
export const VERTICAL_DOMINANCE = 1.5;
/** Resistenza iniziale: all'inizio il contenuto segue il dito a questa frazione della sua corsa. */
export const PULL_RESISTANCE = 0.7;
/** Asintoto della distanza visualizzata (px): ci si avvicina sempre più piano, senza un arresto netto. */
export const MAX_PULL = 150;
/** Distanza visualizzata (dopo la resistenza) da raggiungere perché il rilascio aggiorni (px). */
export const PULL_THRESHOLD = 64;

export type GestureDecision = 'pending' | 'pull' | 'ignore';

/** Decide cosa sta facendo il dito dall'inizio del tocco. `scrollTop` è quello del contenitore a gesto avviato. */
export function classifyGesture(dx: number, dy: number, scrollTop: number): GestureDecision {
  if (scrollTop > 0) return 'ignore';
  if (Math.abs(dx) < GESTURE_SLOP && Math.abs(dy) < GESTURE_SLOP) return 'pending';
  if (dy > 0 && dy > Math.abs(dx) * VERTICAL_DOMINANCE) return 'pull';
  return 'ignore';
}

/**
 * Distanza mostrata per uno spostamento del dito di `dy` px: effetto elastico come su iOS, che parte da
 * PULL_RESISTANCE e rallenta via via verso MAX_PULL (la soglia arriva a circa 160px di dito).
 */
export function pullDistance(dy: number): number {
  if (dy <= 0) return 0;
  return MAX_PULL * (1 - 1 / (1 + (dy * PULL_RESISTANCE) / MAX_PULL));
}

export function isPastThreshold(distance: number): boolean {
  return distance >= PULL_THRESHOLD;
}

/** Controlli dove il tocco appartiene all'elemento, non alla pagina. */
const INTERACTIVE_SELECTOR =
  'input, textarea, select, [contenteditable=""], [contenteditable="true"], [data-no-pull-refresh], .recharts-wrapper';

/** Sheet, dialog e menu di Radix vivono in un portale fuori da <main>: se uno è aperto la pagina sotto non si tira. */
const OPEN_OVERLAY_SELECTOR = '[role="dialog"], [role="alertdialog"], [role="menu"]';

/** True se il gesto deve essere lasciato al suo bersaglio (campo di testo, grafico, overlay aperto). */
export function shouldIgnoreTarget(target: EventTarget | null, doc: Document = document): boolean {
  if (target instanceof Element && target.closest(INTERACTIVE_SELECTOR)) return true;
  const active = doc.activeElement;
  if (active instanceof HTMLElement && active.matches('input, textarea, select, [contenteditable=""], [contenteditable="true"]')) {
    return true;
  }
  return doc.querySelector(OPEN_OVERLAY_SELECTOR) !== null;
}

interface StandaloneNavigator extends Navigator {
  /** Solo Safari iOS: true se la pagina gira come app aggiunta alla Home. */
  standalone?: boolean;
}

/**
 * Regola di attivazione: il gesto serve solo dove il browser non ha già il suo pull-to-refresh, cioè
 * quando l'app gira "installata" (display-mode standalone, o `navigator.standalone` su iOS) e nel layout
 * mobile (sotto md, dove c'è la BottomNav). In una scheda normale esiste quello nativo: due gesti
 * insieme si pesterebbero i piedi, e Android in standalone lo perde come iOS.
 */
export function isPullToRefreshEnabled(win: Window = window): boolean {
  if (typeof win.matchMedia !== 'function') return false;
  const standalone =
    win.matchMedia('(display-mode: standalone)').matches || (win.navigator as StandaloneNavigator).standalone === true;
  return standalone && win.matchMedia('(max-width: 767px)').matches;
}
