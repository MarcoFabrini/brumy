import { useRef, useState, type PointerEvent, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface SwipeAction {
  label: string;
  onAction: () => void;
  /** Colore di sfondo del pulsante (es. `bg-[#34c759]` per "Completa", `bg-destructive` per "Elimina"). */
  className?: string;
}

export interface SwipeActionsProps {
  actions: SwipeAction[];
  children: ReactNode;
  className?: string;
}

const ACTION_WIDTH = 88;
/** Oltre questa distanza (px) un trascinamento orizzontale non è più un tocco: il clic sul contenuto si annulla. */
const DRAG_SLOP = 8;

/**
 * Azioni a scorrimento come nelle liste iOS: si trascina la riga verso sinistra e compaiono i pulsanti dietro
 * (si aprono oltre metà corsa, si richiudono toccando la riga o scorrendo indietro). Lo scorrimento verticale resta
 * del browser (`touch-action: pan-y`). Accessibile senza gesto: i pulsanti sono veri bottoni nell'ordine di
 * tabulazione e, quando prendono il focus, la riga si apre per mostrarli.
 */
export function SwipeActions({ actions, children, className }: SwipeActionsProps) {
  const width = actions.length * ACTION_WIDTH;
  const [offset, setOffset] = useState(0);
  const [dragging, setDragging] = useState(false);
  const start = useRef<{ x: number; y: number; offset: number; horizontal: boolean | null } | null>(null);
  const moved = useRef(false);

  function onPointerDown(e: PointerEvent<HTMLDivElement>) {
    start.current = { x: e.clientX, y: e.clientY, offset, horizontal: null };
    moved.current = false;
  }

  function onPointerMove(e: PointerEvent<HTMLDivElement>) {
    const s = start.current;
    if (!s) return;
    const dx = e.clientX - s.x;
    const dy = e.clientY - s.y;
    if (s.horizontal === null) {
      if (Math.abs(dx) < DRAG_SLOP && Math.abs(dy) < DRAG_SLOP) return;
      // Gesto verticale: è uno scroll della pagina, la riga non si muove.
      s.horizontal = Math.abs(dx) > Math.abs(dy);
      if (!s.horizontal) {
        start.current = null;
        return;
      }
      setDragging(true);
    }
    moved.current = true;
    setOffset(Math.min(0, Math.max(-width, s.offset + dx)));
  }

  function onPointerUp() {
    if (start.current?.horizontal) setOffset((o) => (o < -width / 2 ? -width : 0));
    start.current = null;
    setDragging(false);
  }

  return (
    <div className={cn('relative overflow-hidden', className)}>
      <div
        className={cn('relative z-[1] bg-card [touch-action:pan-y]', !dragging && 'transition-transform duration-200')}
        style={{ transform: `translateX(${offset}px)` }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
        // Dopo un trascinamento, o con la riga aperta, il tocco non apre il link: richiude la riga.
        onClickCapture={(e) => {
          if (moved.current || offset !== 0) {
            e.preventDefault();
            e.stopPropagation();
            moved.current = false;
            setOffset(0);
          }
        }}
      >
        {children}
      </div>
      {/* Dopo il contenuto: con Tab si arriva prima alla riga, poi alle sue azioni. Stanno sotto (z) la riga. */}
      <div className="absolute inset-y-0 right-0 z-0 flex" style={{ width }}>
        {actions.map((action) => (
          <button
            key={action.label}
            type="button"
            onFocus={() => setOffset(-width)}
            onBlur={() => setOffset(0)}
            onClick={() => {
              setOffset(0);
              action.onAction();
            }}
            className={cn(
              'flex h-full flex-1 items-center justify-center px-2 text-[0.9375rem] font-semibold text-white',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white',
              action.className ?? 'bg-primary',
            )}
          >
            {action.label}
          </button>
        ))}
      </div>
    </div>
  );
}
