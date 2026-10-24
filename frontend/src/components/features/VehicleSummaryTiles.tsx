import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { ChevronRight } from 'lucide-react';
import { Skeleton } from '@/components/ui';
import { useFormat } from '@/hooks/useFormat';
import { cn } from '@/lib/utils';
import type { VehicleStats } from '@/api/types/vehicleStats';
import type { Reminder, ReminderUrgency } from '@/api/types/reminder';

export interface VehicleSummaryTilesProps {
  stats: VehicleStats | undefined;
  /** Le statistiche non sono arrivate: si mostra un trattino, mai i km iniziali come attuali. */
  statsError: boolean;
  /** Speso nel mese corrente (dai grafici del veicolo); undefined finché non arriva. */
  monthSpent: number | undefined;
  monthError: boolean;
  next: { reminder: Reminder; urgency: ReminderUrgency } | null;
}

const URGENCY_TEXT: Record<ReminderUrgency, string> = {
  overdue: 'text-destructive-text',
  soon: 'text-[#c93400] dark:text-[#ff9f0a]',
  ok: 'text-foreground',
  done: 'text-foreground',
};

/**
 * Cima della scheda del veicolo: i numeri che servono a colpo d'occhio (km attuali, consumo, spesa del mese) e la prossima
 * scadenza, che porta al promemoria. Riquadri bianchi sullo sfondo raggruppato, come i widget iOS.
 */
export function VehicleSummaryTiles({ stats, statsError, monthSpent, monthError, next }: VehicleSummaryTilesProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const consumption = stats ? Object.values(stats.consumption).find((v): v is number => v !== null) : undefined;
  const pending = <Skeleton className="h-7 w-20" />;
  const value = (shown: string | undefined, failed = statsError) => (shown !== undefined ? shown : failed ? '—' : pending);

  return (
    <div className="space-y-3">
      <dl className="grid grid-cols-2 gap-3 md:grid-cols-3">
        <Tile label={t('vehicle.current_km')} value={value(stats ? fmt.km(stats.currentKm) : undefined)} />
        <Tile
          label={t('vehicle.stats.consumption')}
          value={value(stats ? (consumption !== undefined ? `${fmt.decimal(consumption, 1)} km/l` : '—') : undefined)}
        />
        <Tile
          className="col-span-2 md:col-span-1"
          label={t('dashboard.month.title')}
          value={value(monthSpent !== undefined ? fmt.currency(monthSpent) : undefined, monthError)}
        />
      </dl>
      {next && (
        <Link
          to={`/reminders/${next.reminder.id}`}
          className="flex min-h-touch items-center gap-3 rounded-xl bg-card px-4 py-3 transition-colors active:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <div className="min-w-0 flex-1">
            <p className="text-[0.8125rem] text-muted-foreground">{t('vehicle.summary.next_deadline')}</p>
            <p className={cn('truncate text-[1.0625rem] font-semibold', URGENCY_TEXT[next.urgency])}>
              {next.reminder.description}
            </p>
            <p className="truncate text-[0.9375rem] text-muted-foreground">
              {[
                next.reminder.dueDate ? fmt.date(next.reminder.dueDate) : null,
                next.reminder.dueKm !== null ? fmt.km(next.reminder.dueKm) : null,
                t(`reminder.urgency.${next.urgency}`),
              ]
                .filter(Boolean)
                .join(' · ')}
            </p>
          </div>
          <ChevronRight aria-hidden="true" className="size-5 shrink-0 text-muted-foreground/60" />
        </Link>
      )}
    </div>
  );
}

function Tile({ label, value, className }: { label: string; value: ReactNode; className?: string }) {
  return (
    <div className={cn('rounded-xl bg-card px-4 py-3', className)}>
      <dt className="text-[0.8125rem] text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 text-[1.375rem] font-semibold tabular-nums tracking-tight">{value}</dd>
    </div>
  );
}
