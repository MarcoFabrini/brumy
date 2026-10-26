import { useTranslation } from 'react-i18next';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartCard } from './ChartCard';
import {
  AXIS_STROKE,
  AXIS_TICK,
  GRID_STROKE,
  TOOLTIP_CONTENT_STYLE,
  TOOLTIP_LABEL_STYLE,
  chartColor,
  toggleLegendProps,
} from './chartTheme';
import { hasConsumption, type ConsumptionRow } from '@/lib/dashboardCharts';
import { useFormat } from '@/hooks/useFormat';
import { usePrefersReducedMotion } from '@/hooks/usePrefersReducedMotion';
import { useHiddenSeries } from '@/hooks/useHiddenSeries';

export interface ConsumptionTrendChartProps {
  rows: ConsumptionRow[];
  /** Una linea per carburante dei veicoli propri. */
  fuelTypes: string[];
}

/**
 * Consumo medio mensile (km/l) per carburante, fill-to-fill: il punto di un mese c'è solo se nel
 * mese si è chiuso un intervallo tra due pieni; la linea collega i mesi con un valore.
 */
export function ConsumptionTrendChart({ rows, fuelTypes }: ConsumptionTrendChartProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const reducedMotion = usePrefersReducedMotion();
  const series = useHiddenSeries();
  const unit = t('dashboard.charts.unit_km_per_liter');
  const fuelName = (fuel: string) => t(`vehicle.fuel.${fuel}`, { defaultValue: fuel });
  const noValue = t('dashboard.charts.table.no_value');

  return (
    <ChartCard
      title={`${t('dashboard.charts.consumption')} (${unit})`}
      emptyMessage={t('dashboard.charts.empty.consumption')}
      isEmpty={!hasConsumption(rows)}
      table={{
        columns: [t('dashboard.charts.table.month'), ...fuelTypes.map(fuelName)],
        rows: rows.map((row) => [
          row.label,
          ...fuelTypes.map((fuel) => {
            const value = row.values[fuel];
            return value == null ? noValue : fmt.decimal(value, 2);
          }),
        ]),
      }}
    >
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={rows} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
          <CartesianGrid stroke={GRID_STROKE} strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="label" tick={AXIS_TICK} stroke={AXIS_STROKE} tickLine={false} interval="preserveStartEnd" minTickGap={12} />
          <YAxis
            tick={AXIS_TICK}
            stroke={AXIS_STROKE}
            tickLine={false}
            width={40}
            domain={['auto', 'auto']}
            tickFormatter={(v: number) => fmt.decimal(v, 1)}
          />
          <Tooltip
            contentStyle={TOOLTIP_CONTENT_STYLE}
            labelStyle={TOOLTIP_LABEL_STYLE}
            formatter={(value) => (value == null ? '—' : `${fmt.decimal(Number(value), 2)} ${unit}`)}
          />
          <Legend {...toggleLegendProps(series.toggle)} />
          {fuelTypes.map((fuel, i) => (
            <Line
              key={fuel}
              type="monotone"
              dataKey={(row: ConsumptionRow) => row.values[fuel]}
              name={fuelName(fuel)}
              hide={series.isHidden(fuelName(fuel))}
              stroke={chartColor(i)}
              strokeWidth={2}
              dot={{ r: 3, fill: chartColor(i) }}
              connectNulls
              isAnimationActive={!reducedMotion}
            />
          ))}
        </LineChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}
