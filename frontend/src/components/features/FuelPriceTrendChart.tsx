import { useMemo, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartCard } from './ChartCard';
import {
  AXIS_STROKE,
  AXIS_TICK,
  GRID_STROKE,
  LEGEND_STYLE,
  TOOLTIP_CONTENT_STYLE,
  TOOLTIP_LABEL_STYLE,
  chartColor,
} from './chartTheme';
import type { FollowedFuelStation } from '@/api/types/fuelPrice';
import { toTrendRows, type PriceFuel, type TrendRow } from '@/lib/fuelPriceTrend';
import { useFormat } from '@/hooks/useFormat';
import { usePrefersReducedMotion } from '@/hooks/usePrefersReducedMotion';

export interface FuelPriceTrendChartProps {
  title: string;
  fuel: PriceFuel;
  /** I distributori seguiti: una linea per ognuno che ha almeno un prezzo. */
  stations: FollowedFuelStation[];
  /** La scelta del carburante, nell'intestazione. */
  action: ReactNode;
}

/**
 * Prezzo self giorno per giorno dei distributori seguiti. Porta con sé recharts: si carica con `lazyNamed`
 * (vedi {@link FollowedFuelPrices}), mai con un import statico.
 */
export function FuelPriceTrendChart({ title, fuel, stations, action }: FuelPriceTrendChartProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const reducedMotion = usePrefersReducedMotion();
  const rows = useMemo(() => toTrendRows(stations, fmt.day), [stations, fmt]);
  const lines = stations.filter((s) => s.points.length > 0);
  const noValue = t('dashboard.charts.table.no_value');

  return (
    <ChartCard
      title={title}
      action={action}
      emptyMessage={t('fuel_prices.trend_empty', { fuel: t(`vehicle.fuel.${fuel}`) })}
      isEmpty={lines.length === 0}
      table={{
        columns: [t('fuel_prices.trend_day'), ...lines.map((s) => s.name)],
        rows: rows.map((row) => [
          row.label,
          ...lines.map((s) => {
            const value = row.values[s.valueId];
            return value == null ? noValue : fmt.fuelPrice(value);
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
            width={48}
            domain={['auto', 'auto']}
            tickFormatter={(v: number) => fmt.decimal(v, 3)}
          />
          <Tooltip
            contentStyle={TOOLTIP_CONTENT_STYLE}
            labelStyle={TOOLTIP_LABEL_STYLE}
            formatter={(value) => (value == null ? '—' : fmt.fuelPrice(Number(value)))}
          />
          {lines.length > 1 && <Legend wrapperStyle={LEGEND_STYLE} iconSize={10} />}
          {lines.map((station, i) => (
            <Line
              key={station.valueId}
              type="monotone"
              dataKey={(row: TrendRow) => row.values[station.valueId]}
              name={station.name}
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
