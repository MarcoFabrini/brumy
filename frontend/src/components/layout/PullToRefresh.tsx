import { useCallback, useRef, type ReactNode, type RefObject } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { ArrowDown, Loader2 } from 'lucide-react';
import { usePullToRefresh, type PullStatus } from '@/hooks/usePullToRefresh';
import { PULL_THRESHOLD } from '@/lib/pullToRefresh';
import { refreshInPlace } from '@/lib/pwaUpdate';

export interface PullToRefreshProps {
  /** Il contenitore che scrolla (`<main>` di AppLayout): deve essere `relative`, l'indicatore è assoluto al suo interno. */
  scrollRef: RefObject<HTMLElement | null>;
  /** Il contenuto della pagina: scende insieme al dito e torna su a gesto finito. */
  children: ReactNode;
}

/** Durata minima della rotellina: un aggiornamento istantaneo non deve far lampeggiare l'indicatore. */
export const MIN_REFRESH_MS = 700;
/** Ritorno al posto: lento all'arrivo, come i fogli iOS. Durante il trascinamento niente transizione, segue il dito. */
const SETTLE = 'transform 500ms cubic-bezier(0.22, 1, 0.36, 1), opacity 400ms ease-out';

const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * PullToRefresh — gesto "trascina per aggiornare" (solo PWA installata, layout mobile: regola in lib/pullToRefresh).
 * La pagina scende col dito e svela l'indicatore sopra di sé; al rilascio oltre la soglia resta abbassata con la
 * rotellina mentre i dati si aggiornano sul posto (`refreshInPlace`: ricarica la pagina solo se c'è una versione nuova),
 * poi risale con calma. A riposo niente trasformazioni, quindi nessun effetto sul layout.
 */
export function PullToRefresh({ scrollRef, children }: PullToRefreshProps) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const refresh = useCallback(
    () => Promise.all([refreshInPlace(() => queryClient.refetchQueries({ type: 'active' })), wait(MIN_REFRESH_MS)]).then(() => undefined),
    [queryClient],
  );
  const { status, distance } = usePullToRefresh(scrollRef, refresh);

  // Mentre l'indicatore svanisce mostra ancora l'ultima scritta, non quella di riposo
  const lastActive = useRef<Exclude<PullStatus, 'idle'>>('pulling');
  if (status !== 'idle') lastActive.current = status;
  const shown = lastActive.current;

  const dragging = status === 'pulling' || status === 'ready';
  const offset = status === 'refreshing' ? PULL_THRESHOLD : distance;
  const progress = Math.min(1, offset / PULL_THRESHOLD);
  const transition = dragging ? 'none' : SETTLE;
  const label =
    shown === 'refreshing' ? t('pull_to_refresh.refreshing') : shown === 'ready' ? t('pull_to_refresh.release') : t('pull_to_refresh.pull');

  return (
    <>
      <div
        role="status"
        aria-live="polite"
        aria-hidden={status === 'idle' || undefined}
        className="pointer-events-none absolute inset-x-0 top-safe-top z-10 flex justify-center"
        // Alto quanto la soglia, appena sopra il bordo della pagina che scende
        style={{ height: PULL_THRESHOLD, transform: `translateY(${offset - PULL_THRESHOLD}px)`, opacity: progress, transition }}
      >
        <div className="mt-2 flex h-fit items-center gap-2 rounded-full border bg-card px-3 py-2 text-xs font-medium text-muted-foreground shadow-md">
          {shown === 'refreshing' ? (
            <Loader2 aria-hidden="true" className="size-4 animate-spin" />
          ) : (
            // La freccia gira man mano che ci si avvicina alla soglia
            <ArrowDown aria-hidden="true" className="size-4" style={{ transform: `rotate(${progress * 180}deg)` }} />
          )}
          <span>{label}</span>
        </div>
      </div>
      <div style={{ transform: offset > 0 ? `translateY(${offset}px)` : undefined, transition }}>{children}</div>
    </>
  );
}
