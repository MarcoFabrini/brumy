import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartCard } from './ChartCard';
import {
  AXIS_STROKE,
  AXIS_TICK,
  GRID_STROKE,
  TOOLTIP_CONTENT_STYLE,
  TOOLTIP_CURSOR,
  TOOLTIP_LABEL_STYLE,
  toggleLegendProps,
} from './chartTheme';
import { SPENDING_SERIES as SERIES, hasSpending, type MonthlySpendingRow } from '@/lib/dashboardCharts';
import { SPENDING_SERIES_SECTIONS, sectionOf } from './vehicleRecordActions';
import { useFormat } from '@/hooks/useFormat';
import { usePrefersReducedMotion } from '@/hooks/usePrefersReducedMotion';
import { useHiddenSeries } from '@/hooks/useHiddenSeries';

export interface MonthlySpendingChartProps {
  rows: MonthlySpendingRow[];
}

const section = (key: (typeof SERIES)[number]) => sectionOf(SPENDING_SERIES_SECTIONS[key]);

/** Spese mensili impilate per sezione di costo, con il nome e il colore della sezione. */
export function MonthlySpendingChart({ rows }: MonthlySpendingChartProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const reducedMotion = usePrefersReducedMotion();
  const hidden = useHiddenSeries();
  // Solo le sezioni con una spesa nella finestra: sette voci di legenda vuote non dicono nulla
  const series = SERIES.filter((key) => rows.some((row) => row[key] !== 0));

  return (
    <ChartCard
      title={t('dashboard.charts.monthly_spending')}
      emptyMessage={t('dashboard.charts.empty.spending')}
      isEmpty={!hasSpending(rows)}
      table={{
        columns: [
          t('dashboard.charts.table.month'),
          ...series.map((key) => t(section(key).titleKey)),
          t('dashboard.charts.table.total'),
        ],
        rows: rows.map((row) => [
          row.label,
          ...series.map((key) => fmt.currency(row[key])),
          fmt.currency(row.total),
        ]),
      }}
    >
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
          <CartesianGrid stroke={GRID_STROKE} strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="label" tick={AXIS_TICK} stroke={AXIS_STROKE} tickLine={false} interval="preserveStartEnd" minTickGap={12} />
          <YAxis tick={AXIS_TICK} stroke={AXIS_STROKE} tickLine={false} width={48} tickFormatter={(v: number) => fmt.decimal(v, 0)} />
          <Tooltip
            cursor={TOOLTIP_CURSOR}
            contentStyle={TOOLTIP_CONTENT_STYLE}
            labelStyle={TOOLTIP_LABEL_STYLE}
            formatter={(value) => fmt.currency(Number(value))}
          />
          <Legend {...toggleLegendProps(hidden.toggle)} />
          {series.map((key) => (
            <Bar
              key={key}
              dataKey={key}
              name={t(section(key).titleKey)}
              hide={hidden.isHidden(t(section(key).titleKey))}
              stackId="spending"
              fill={section(key).color}
              maxBarSize={32}
              isAnimationActive={!reducedMotion}
            />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}
