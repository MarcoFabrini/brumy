import { useMemo, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis, type LegendProps } from 'recharts';
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
import type { FollowedFuelStation, FuelServiceMode } from '@/api/types/fuelPrice';
import { SERVICE_MODES, seriesKey, toTrendRows, type PriceFuel, type TrendRow } from '@/lib/fuelPriceTrend';
import { useFormat } from '@/hooks/useFormat';
import { usePrefersReducedMotion } from '@/hooks/usePrefersReducedMotion';
import { useHiddenSeries } from '@/hooks/useHiddenSeries';

export interface FuelPriceTrendChartProps {
  title: string;
  fuel: PriceFuel;
  /** I distributori seguiti: una linea per ogni prezzo (self, servito) che hanno. */
  stations: FollowedFuelStation[];
  /** La scelta del carburante, nell'intestazione. */
  action: ReactNode;
}

/** Il servito tratteggiato: stesso colore del distributore, linea diversa. */
const SERVED_DASH = '5 4';

const stationKey = (valueId: number) => `station:${valueId}`;
const modeKey = (mode: FuelServiceMode) => `mode:${mode}`;

/**
 * Prezzo self e servito giorno per giorno dei distributori seguiti: un colore per distributore, il servito tratteggiato
 * (GPL e metano ne hanno quasi solo quello). Nella legenda di solo testo un distributore, o tutto il self o tutto il
 * servito, si nasconde e si rimostra al tocco. Porta con sé recharts: si carica con `lazyNamed`
 * (vedi {@link FollowedFuelPrices}), mai con un import statico.
 */
export function FuelPriceTrendChart({ title, fuel, stations, action }: FuelPriceTrendChartProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const reducedMotion = usePrefersReducedMotion();
  const hidden = useHiddenSeries();
  const rows = useMemo(() => toTrendRows(stations, fmt.day), [stations, fmt]);
  const withPrices = stations.filter((s) => s.self.length > 0 || s.served.length > 0);
  const series = withPrices.flatMap((station, i) =>
    SERVICE_MODES.filter((mode) => station[mode].length > 0).map((mode) => ({
      key: seriesKey(station.valueId, mode),
      station,
      mode,
      color: chartColor(i),
      name: `${station.name} · ${t(`fuel_prices.mode_${mode}`)}`,
    })),
  );
  const modes = SERVICE_MODES.filter((mode) => series.some((s) => s.mode === mode));
  const noValue = t('dashboard.charts.table.no_value');

  // Una voce per distributore (non per linea) e una per tratto: toccata, nasconde tutte le linee che le appartengono
  const legend: NonNullable<LegendProps['payload']> = [
    ...withPrices.map((station, i) => ({
      id: stationKey(station.valueId),
      value: station.name,
      type: 'circle' as const,
      color: chartColor(i),
      inactive: hidden.isHidden(stationKey(station.valueId)),
    })),
    ...modes.map((mode) => ({
      id: modeKey(mode),
      value: t(`fuel_prices.mode_${mode}`),
      type: 'plainline' as const,
      color: 'hsl(var(--muted-foreground))',
      payload: { strokeDasharray: mode === 'served' ? SERVED_DASH : '0' },
      inactive: hidden.isHidden(modeKey(mode)),
    })),
  ];

  return (
    <ChartCard
      title={title}
      action={action}
      emptyMessage={t('fuel_prices.trend_empty', { fuel: t(`vehicle.fuel.${fuel}`) })}
      isEmpty={series.length === 0}
      table={{
        columns: [t('fuel_prices.trend_day'), ...series.map((s) => s.name)],
        rows: rows.map((row) => [
          row.label,
          ...series.map((s) => {
            const value = row.values[s.key];
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
          <Legend {...toggleLegendProps(hidden.toggle)} payload={legend} />
          {series.map((s) => (
            <Line
              key={s.key}
              type="monotone"
              dataKey={(row: TrendRow) => row.values[s.key]}
              name={s.name}
              stroke={s.color}
              strokeWidth={2}
              strokeDasharray={s.mode === 'served' ? SERVED_DASH : undefined}
              dot={{ r: 3, fill: s.color }}
              hide={hidden.isHidden(stationKey(s.station.valueId)) || hidden.isHidden(modeKey(s.mode))}
              connectNulls
              isAnimationActive={!reducedMotion}
            />
          ))}
        </LineChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}
