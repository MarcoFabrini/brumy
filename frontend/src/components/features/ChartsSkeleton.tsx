import { Skeleton } from '@/components/ui';

/**
 * Griglia di segnaposto dei quattro grafici (stessa griglia e stessa altezza di `ChartCard`):
 * la usano sia l'attesa dei dati sia l'attesa del chunk dei grafici, così il passaggio dall'una
 * all'altra e al grafico vero non sposta nulla.
 */
export function ChartsSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-3 md:gap-4 xl:grid-cols-2">
      {Array.from({ length: 4 }).map((_, i) => (
        <Skeleton key={i} className="h-72 w-full rounded-lg" />
      ))}
    </div>
  );
}
