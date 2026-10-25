import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { useFormat } from '@/hooks/useFormat';
import type { MonthSpending } from '@/lib/monthSpending';
import { SPENDING_SERIES } from '@/lib/dashboardCharts';
import { SPENDING_SERIES_SECTIONS, sectionOf } from './vehicleRecordActions';

/**
 * Il riquadro in cima alla Home: quanto si è speso nel mese corrente, il confronto col mese prima e da dove viene
 * la spesa (le sezioni con una spesa nel mese; il carburante se non ce n'è nessuna), più i km percorsi nel mese.
 */
export function MonthSummaryCard({ summary }: { summary: MonthSpending }) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const headingId = useId();
  const delta = summary.previousTotal === null ? null : summary.total - summary.previousTotal;
  const spent = SPENDING_SERIES.filter((key) => summary.bySeries[key] !== 0);
  const shown = spent.length > 0 ? spent : (['refuelings'] as const);

  return (
    <section aria-labelledby={headingId} className="rounded-xl bg-card px-4 py-3">
      <h2 id={headingId} className="text-[0.8125rem] text-muted-foreground">
        {t('dashboard.month.title')}
      </h2>
      <p className="mt-0.5 text-[2.125rem] font-bold leading-tight tabular-nums tracking-tight">
        {fmt.currency(summary.total)}
      </p>
      {delta !== null && (
        <p className="text-[0.9375rem] text-muted-foreground">
          {delta === 0
            ? t('dashboard.month.same')
            : t(delta > 0 ? 'dashboard.month.more' : 'dashboard.month.less', { amount: fmt.currency(Math.abs(delta)) })}
        </p>
      )}
      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 border-t border-border pt-3 md:grid-cols-4">
        {shown.map((key) => (
          <Figure key={key} label={t(sectionOf(SPENDING_SERIES_SECTIONS[key]).titleKey)} value={fmt.currency(summary.bySeries[key])} />
        ))}
        <Figure label={t('dashboard.charts.km_driven')} value={fmt.km(summary.kmDriven)} />
      </dl>
    </section>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-[0.8125rem] text-muted-foreground">{label}</dt>
      <dd className="text-[1.0625rem] font-semibold tabular-nums">{value}</dd>
    </div>
  );
}
