import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import { ChartCard } from './ChartCard';
import { CHART_COLORS, TOOLTIP_CONTENT_STYLE, chartColor } from './chartTheme';
import { REST_CATEGORY, type CategorySlice } from '@/lib/dashboardCharts';
import { SPENDING_SLICE_SECTIONS, sectionOf } from './vehicleRecordActions';
import { useFormat } from '@/hooks/useFormat';
import { usePrefersReducedMotion } from '@/hooks/usePrefersReducedMotion';

export interface SpendingByCategoryChartProps {
  /** Già raggruppate (prime categorie + "Altre categorie"), importo decrescente. */
  slices: CategorySlice[];
}

/** Una fetta per sezione (nome e colore della sezione); le categorie delle altre spese col loro nome. */
function categoryLabel(t: TFunction, slice: CategorySlice): string {
  const section = SPENDING_SLICE_SECTIONS[slice.category];
  if (section) return t(sectionOf(section).titleKey);
  return slice.name ?? t(`dashboard.charts.category.${REST_CATEGORY}`);
}

/** Sezioni nel loro colore, "Altre categorie" nel colore neutro (l'ultimo della palette), le categorie in ordine. */
function sliceColor(slice: CategorySlice, index: number): string {
  const section = SPENDING_SLICE_SECTIONS[slice.category];
  if (section) return sectionOf(section).color;
  return slice.category === REST_CATEGORY ? chartColor(CHART_COLORS.length - 1) : chartColor(index);
}

/**
 * Distribuzione delle spese della finestra ad anello. La legenda è una lista con gli importi
 * sotto il grafico invece della legenda di recharts: su mobile resta leggibile e non copre l'anello.
 */
export function SpendingByCategoryChart({ slices }: SpendingByCategoryChartProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const reducedMotion = usePrefersReducedMotion();
  const data = slices.map((slice, i) => ({
    name: categoryLabel(t, slice),
    amount: slice.amount,
    color: sliceColor(slice, i),
  }));

  return (
    <ChartCard
      title={t('dashboard.charts.spending_by_category')}
      emptyMessage={t('dashboard.charts.empty.categories')}
      isEmpty={slices.length === 0}
      table={{
        columns: [t('dashboard.charts.table.category'), t('dashboard.charts.table.amount')],
        rows: data.map((entry) => [entry.name, fmt.currency(entry.amount)]),
      }}
      footer={
        <ul className="mt-3 grid grid-cols-1 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-2">
          {data.map((entry) => (
            <li key={entry.name} className="flex min-w-0 items-center gap-2">
              <span aria-hidden className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: entry.color }} />
              <span className="min-w-0 flex-1 truncate text-muted-foreground">{entry.name}</span>
              <span className="font-medium tabular-nums">{fmt.currency(entry.amount)}</span>
            </li>
          ))}
        </ul>
      }
    >
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Tooltip contentStyle={TOOLTIP_CONTENT_STYLE} itemStyle={{ color: 'hsl(var(--popover-foreground))' }} formatter={(value) => fmt.currency(Number(value))} />
          <Pie data={data} dataKey="amount" nameKey="name" innerRadius="55%" outerRadius="85%" paddingAngle={2} isAnimationActive={!reducedMotion} stroke="hsl(var(--card))">
            {data.map((entry) => (
              <Cell key={entry.name} fill={entry.color} />
            ))}
          </Pie>
        </PieChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}
