import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui';

/**
 * LoadMoreButton feature — in fondo a un elenco paginato dal server: carica la pagina successiva quando l'utente ci
 * arriva scorrendo (IntersectionObserver sul pulsante, con un po' di anticipo) e resta come pulsante "Mostra altri"
 * per tastiera, screen reader e browser senza IntersectionObserver. Non mostra nulla a fine elenco. Se l'ultima
 * pagina chiesta è fallita (`failed`, `isFetchNextPageError` di `useInfiniteQuery`) non riprova da solo: niente
 * richieste a ciclo contro un server che risponde errore o da offline; si riprova premendo il pulsante.
 *
 * `onLoadMore` deve avere un'identità stabile (es. `fetchNextPage` di `useInfiniteQuery`): è una dipendenza
 * dell'osservatore.
 */
export interface LoadMoreButtonProps {
  hasNextPage: boolean;
  isFetchingNextPage: boolean;
  /** L'ultima pagina successiva chiesta è fallita: caricamento automatico sospeso fino al prossimo click. */
  failed: boolean;
  onLoadMore: () => unknown;
}

export function LoadMoreButton({ hasNextPage, isFetchingNextPage, failed, onLoadMore }: LoadMoreButtonProps) {
  const { t } = useTranslation();
  const ref = useRef<HTMLButtonElement>(null);

  // Sistema esterno: l'osservatore della visibilità. Si ricrea a fine caricamento, così se il fondo è ancora in
  // vista (pagina corta, schermo alto) parte subito la pagina dopo.
  useEffect(() => {
    const target = ref.current;
    if (!target || !hasNextPage || isFetchingNextPage || failed || typeof IntersectionObserver === 'undefined') return;
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          observer.disconnect();
          onLoadMore();
        }
      },
      { rootMargin: '0px 0px 400px 0px' },
    );
    observer.observe(target);
    return () => observer.disconnect();
  }, [hasNextPage, isFetchingNextPage, failed, onLoadMore]);

  if (!hasNextPage) return null;

  return (
    <div className="flex justify-center">
      <Button
        ref={ref}
        type="button"
        variant="outline"
        disabled={isFetchingNextPage}
        aria-busy={isFetchingNextPage || undefined}
        onClick={() => onLoadMore()}
      >
        {isFetchingNextPage ? t('list.loading_more') : t('list.load_more')}
      </Button>
    </div>
  );
}
