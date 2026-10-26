/**
 * Logica pura del gesto "trascina giù per chiudere" dei fogli (vedi hooks/useSwipeToDismiss).
 * Tenuta fuori dal DOM e dagli eventi così soglie ed esclusioni si testano a numeri.
 */

/** Al rilascio il foglio si chiude se è sceso di questa frazione della sua altezza... */
export const DISMISS_FRACTION = 0.25;
/** ...o di questi px, per i fogli alti quanto lo schermo dove un quarto sarebbe troppo. */
export const DISMISS_DISTANCE = 140;
/** Un colpo deciso verso il basso (px/ms) chiude anche se corto... */
export const DISMISS_VELOCITY = 0.5;
/** ...purché il foglio sia sceso almeno un poco: un tocco nervoso non chiude niente. */
export const FLICK_MIN_DISTANCE = 24;

/** Decide al rilascio se il foglio sceso di `distance` px, a `velocity` px/ms, alto `height` px, va chiuso. */
export function shouldDismiss(distance: number, velocity: number, height: number): boolean {
  if (velocity >= DISMISS_VELOCITY && distance >= FLICK_MIN_DISTANCE) return true;
  return distance >= Math.min(height * DISMISS_FRACTION, DISMISS_DISTANCE);
}

/** Controlli dove il trascinamento appartiene all'elemento (testo da selezionare, grafici), non al foglio. */
const OWN_GESTURE_SELECTOR =
  'input, textarea, select, [contenteditable=""], [contenteditable="true"], [data-no-swipe-dismiss], .recharts-wrapper';

/**
 * True se il gesto va lasciato al bersaglio: un controllo con un gesto suo, oppure un'area già scorsa verso il basso
 * fra il bersaglio e il foglio (foglio compreso): lì il dito in giù riporta su il contenuto, non chiude.
 */
export function shouldIgnoreSwipeTarget(target: EventTarget | null, sheet: Element): boolean {
  if (!(target instanceof Element)) return false;
  if (target.closest(OWN_GESTURE_SELECTOR)) return true;
  for (let el: Element | null = target; el; el = el.parentElement) {
    if (el.scrollTop > 0) return true;
    if (el === sheet) break;
  }
  return false;
}
