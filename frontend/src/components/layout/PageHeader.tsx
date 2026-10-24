import { useEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronLeft } from 'lucide-react';
import { Button, Heading, Text } from '@/components/ui';
import { cn } from '@/lib/utils';

/**
 * PageHeader — intestazione di pagina in stile iOS (navigation bar + large title).
 *
 * - Riga della barra: "‹ Indietro" a sinistra (se `onBack`), azioni a destra (`action`).
 * - Titolo grande sotto (34px su mobile). Scorrendo oltre il titolo compare in alto una barra compatta
 *   sfocata con il titolo piccolo e lo stesso "Indietro", come la UINavigationBar (solo mobile).
 * - `srTitle`: il titolo delle radici delle tab (liste, Home). Su mobile è il titolo grande, come nelle app
 *   iOS; da `md` è solo per screen reader, perché la sidebar dice già dove siamo.
 * Ogni pagina ha esattamente un h1. Se non c'è nulla da mostrare, non rende nulla.
 *
 * @example
 * <PageHeader srTitle={t('nav.vehicles')} action={<Button>+ Nuovo</Button>} />
 * <PageHeader title="Modifica" onBack={() => navigate('/vehicles')} />
 */
export interface PageHeaderProps {
  title?: string;
  /** Titolo delle radici delle tab: grande su mobile, solo per screen reader da `md`. */
  srTitle?: string;
  description?: string;
  /** Slot per action button(s) a destra della barra. */
  action?: ReactNode;
  /** Se presente, mostra "‹ Indietro" nella barra. */
  onBack?: () => void;
}

export function PageHeader({ title, srTitle, description, action, onBack }: PageHeaderProps) {
  const heading = title ?? srTitle;
  const titleRef = useRef<HTMLDivElement>(null);
  const collapsed = useScrolledPast(titleRef, Boolean(heading));

  if (!heading && !description && !action && !onBack) return null;

  return (
    <>
      {heading && <CompactBar title={heading} onBack={onBack} visible={collapsed} />}
      <div className="space-y-1">
        {(onBack || action) && (
          <div className="flex min-h-touch items-center justify-between gap-2">
            {onBack ? <BackButton onBack={onBack} /> : <span />}
            {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
          </div>
        )}
        {heading && (
          <div ref={titleRef}>
            <Heading
              level={1}
              className={cn(
                'text-[2.125rem] font-bold leading-[1.15] tracking-tight md:text-3xl',
                !title && 'md:sr-only',
              )}
            >
              {heading}
            </Heading>
          </div>
        )}
        {description && <Text variant="muted">{description}</Text>}
      </div>
    </>
  );
}

function BackButton({ onBack, className }: { onBack: () => void; className?: string }) {
  const { t } = useTranslation();
  return (
    <Button
      variant="ghost"
      onClick={onBack}
      className={cn('-ml-3 gap-0.5 px-2 text-[1.0625rem] font-normal text-primary hover:bg-transparent active:opacity-50', className)}
    >
      <ChevronLeft className="!size-6" strokeWidth={2.5} aria-hidden="true" />
      {t('actions.back')}
    </Button>
  );
}

/** Barra compatta in alto (solo mobile) che compare quando il titolo grande è uscito dallo schermo. */
function CompactBar({ title, onBack, visible }: { title: string; onBack?: () => void; visible: boolean }) {
  return (
    <div
      // Nascosta: fuori dall'albero di accessibilità e dal focus (il "visibility: hidden" del CSS da solo non basta
      // a chi non applica gli stili), così "Indietro" non risulta duplicato.
      aria-hidden={!visible}
      inert={!visible}
      className={cn(
        'fixed inset-x-0 top-0 z-30 border-b border-black/10 bg-card/80 pt-safe backdrop-blur-xl backdrop-saturate-150 transition-opacity duration-200 dark:border-white/10 md:hidden',
        visible ? 'opacity-100' : 'pointer-events-none invisible opacity-0',
      )}
    >
      <div className="relative flex h-11 items-center px-4">
        {onBack && <BackButton onBack={onBack} />}
        {/* Il titolo è già l'h1 della pagina: qui è solo un richiamo visivo. */}
        <p
          aria-hidden="true"
          className="pointer-events-none absolute inset-x-24 truncate text-center text-[1.0625rem] font-semibold"
        >
          {title}
        </p>
      </div>
    </div>
  );
}

const COMPACT_BAR_HEIGHT = 48;

/** True quando l'elemento è scorso sotto la barra compatta in cima allo schermo. */
function useScrolledPast(ref: RefObject<HTMLElement | null>, enabled: boolean): boolean {
  const [past, setPast] = useState(false);
  useEffect(() => {
    const el = ref.current;
    if (!enabled || !el || typeof IntersectionObserver === 'undefined') return;
    // Il margine toglie l'altezza della barra compatta (44px + status bar): il titolo "esce" quando ci passa sotto.
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry) setPast(!entry.isIntersecting && entry.boundingClientRect.top < COMPACT_BAR_HEIGHT);
      },
      { rootMargin: `-${COMPACT_BAR_HEIGHT}px 0px 0px 0px` },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [ref, enabled]);
  return past;
}
