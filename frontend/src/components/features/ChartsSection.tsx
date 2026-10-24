import { Suspense } from 'react';
import { useTranslation } from 'react-i18next';
import { ZodError } from 'zod';
import { Alert } from '@/components/ui';
import { ChartsSkeleton } from './ChartsSkeleton';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import type { DashboardCharts } from '@/api/types/dashboardCharts';
import { lazyNamed } from '@/lib/lazyNamed';

// recharts (~400 kB) si scarica solo quando una sezione grafici compare davvero.
const ChartsGrid = lazyNamed(() => import('./ChartsGrid'), 'ChartsGrid');

export interface ChartsSectionProps {
  /** Id dell'heading, unico nella pagina (la sezione ne è etichettata). */
  headingId: string;
  title: string;
  data: DashboardCharts | undefined;
  isLoading: boolean;
  error: unknown;
}

/**
 * Sezione con i quattro grafici (spese mensili, spese per categoria, km percorsi, consumo),
 * nella scheda del veicolo. Solo presentazione: i dati arrivano dal chiamante. Intestazione, errore e segnaposto
 * sono leggeri; i grafici (`ChartsGrid`) arrivano in un chunk a parte mentre i dati si caricano.
 */
export function ChartsSection({ headingId, title, data, isLoading, error }: ChartsSectionProps) {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();

  return (
    <section aria-labelledby={headingId} className="space-y-1.5">
      {/* Titoletto come quello delle liste (ListSection). */}
      <h2 id={headingId} className="px-4 text-[0.8125rem] font-normal uppercase tracking-wide text-muted-foreground">
        {title}
      </h2>

      {error ? (
        <Alert variant="error">
          {error instanceof ZodError ? t('dashboard.charts.invalid_response') : errorMessage(error)}
        </Alert>
      ) : null}

      {/* Senza dati e con un errore non c'è nulla da disegnare: il chunk non serve. */}
      {data || !error ? (
        <Suspense fallback={<ChartsSkeleton />}>
          <ChartsGrid data={data} isLoading={isLoading} />
        </Suspense>
      ) : null}
    </section>
  );
}
