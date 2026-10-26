import { useEffect, useRef, useState } from 'react';
import { classifyGesture } from '@/lib/pullToRefresh';
import { shouldDismiss, shouldIgnoreSwipeTarget } from '@/lib/swipeToDismiss';

/** Ritorno al posto se il rilascio non chiude: stessa curva dei fogli iOS. */
const SETTLE = 'transform 400ms cubic-bezier(0.32, 0.72, 0, 1)';
const SETTLE_OVERLAY = 'opacity 400ms cubic-bezier(0.32, 0.72, 0, 1)';
/** Se il dito si è fermato da più di così prima di staccarsi, il rilascio non è un colpo veloce. */
const FLICK_WINDOW_MS = 100;

export interface SwipeToDismissOptions {
  enabled: boolean;
  /** Media query in cui il gesto vale (es. solo dove il dialog è un foglio a tutto schermo); senza, sempre. */
  media?: string;
}

/**
 * useSwipeToDismiss — il foglio segue il dito verso il basso e, rilasciato abbastanza giù o con un colpo veloce,
 * chiama `onDismiss` (il foglio esce poi con la sua animazione di chiusura, partendo da dov'è); altrimenti torna su.
 * Parte solo con il contenuto del foglio in cima e un trascinamento chiaramente verticale (stesse regole del
 * pull-to-refresh, lib/pullToRefresh.classifyGesture). Solo tocco: col mouse non cambia niente.
 *
 * Ritorna la ref da dare al foglio. Lo spostamento si scrive direttamente nello stile, senza render a ogni movimento;
 * l'overlay è il fratello che Radix monta subito prima del contenuto e si schiarisce mentre il foglio scende.
 * Listener nativi: `touchmove` non passivo per fermare lo scroll del contenuto mentre il foglio scende.
 */
export function useSwipeToDismiss(onDismiss: () => void, { enabled, media }: SwipeToDismissOptions) {
  const [node, setNode] = useState<HTMLElement | null>(null);
  const onDismissRef = useRef(onDismiss);
  useEffect(() => {
    onDismissRef.current = onDismiss;
  });

  useEffect(() => {
    if (!enabled || !node) return;

    let tracking = false;
    let decision: ReturnType<typeof classifyGesture> = 'pending';
    let startX = 0;
    let startY = 0;
    let distance = 0;
    let lastY = 0;
    let lastTime = 0;
    let velocity = 0;

    const overlay = (): HTMLElement | null => {
      const sibling = node.previousElementSibling;
      return sibling instanceof HTMLElement && sibling.hasAttribute('data-state') && !sibling.hasAttribute('role') ? sibling : null;
    };

    const move = (d: number, animate: boolean) => {
      node.style.transition = animate ? SETTLE : 'none';
      node.style.transform = d > 0 ? `translate3d(0, ${d}px, 0)` : '';
      const backdrop = overlay();
      if (backdrop) {
        backdrop.style.transition = animate ? SETTLE_OVERLAY : 'none';
        backdrop.style.opacity = d > 0 ? String(Math.max(0, 1 - d / Math.max(node.offsetHeight, 1))) : '';
      }
    };

    const onTouchStart = (event: TouchEvent) => {
      const touch = event.touches[0];
      tracking = false;
      if (event.touches.length !== 1 || !touch) return;
      if (media && !window.matchMedia(media).matches) return;
      if (shouldIgnoreSwipeTarget(event.target, node)) return;
      tracking = true;
      decision = 'pending';
      distance = 0;
      velocity = 0;
      startX = touch.clientX;
      startY = touch.clientY;
      lastY = touch.clientY;
      lastTime = event.timeStamp;
    };

    const onTouchMove = (event: TouchEvent) => {
      const touch = event.touches[0];
      if (!tracking || !touch) return;
      if (decision === 'pending') {
        decision = classifyGesture(touch.clientX - startX, touch.clientY - startY, 0);
        if (decision === 'ignore') {
          tracking = false;
          return;
        }
        if (decision === 'pending') return;
        // Si parte da qui, non da dove il dito ha toccato: niente salto della soglia di decisione
        startY = touch.clientY;
      }
      if (event.cancelable) event.preventDefault();
      distance = Math.max(0, touch.clientY - startY);
      const elapsed = event.timeStamp - lastTime;
      if (elapsed > 0) velocity = (touch.clientY - lastY) / elapsed;
      lastY = touch.clientY;
      lastTime = event.timeStamp;
      move(distance, false);
    };

    const onTouchEnd = (event: TouchEvent) => {
      if (!tracking) return;
      tracking = false;
      if (decision !== 'pull') return;
      const flick = event.timeStamp - lastTime <= FLICK_WINDOW_MS ? velocity : 0;
      if (shouldDismiss(distance, flick, node.offsetHeight)) {
        // Resta dov'è: l'animazione di chiusura parte da qui
        node.style.transition = '';
        onDismissRef.current();
      } else {
        move(0, true);
      }
    };

    const onTouchCancel = () => {
      if (tracking && decision === 'pull') move(0, true);
      tracking = false;
    };

    node.addEventListener('touchstart', onTouchStart, { passive: true });
    node.addEventListener('touchmove', onTouchMove, { passive: false });
    node.addEventListener('touchend', onTouchEnd);
    node.addEventListener('touchcancel', onTouchCancel);
    return () => {
      node.removeEventListener('touchstart', onTouchStart);
      node.removeEventListener('touchmove', onTouchMove);
      node.removeEventListener('touchend', onTouchEnd);
      node.removeEventListener('touchcancel', onTouchCancel);
    };
  }, [enabled, media, node]);

  return setNode;
}
