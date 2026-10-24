import { useMemo } from 'react';
import { MonthlySpendingChart } from './MonthlySpendingChart';
import type { DashboardCharts } from '@/api/types/dashboardCharts';
import { toMonthlySpendingRows } from '@/lib/dashboardCharts';
import { useFormat } from '@/hooks/useFormat';

/**
 * L'unico grafico della Home (spese mensili). Come `ChartsGrid` porta con sé recharts: la Home lo carica con
 * `lazyNamed`, mai con un import statico (né dal barrel), o recharts finisce nel bundle iniziale.
 */
export function HomeSpendingChart({ data }: { data: DashboardCharts }) {
  const fmt = useFormat();
  const rows = useMemo(() => toMonthlySpendingRows(data, fmt.month), [data, fmt]);
  return <MonthlySpendingChart rows={rows} />;
}
