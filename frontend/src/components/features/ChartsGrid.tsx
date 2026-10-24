import { useMemo } from 'react';
import { ChartsSkeleton } from './ChartsSkeleton';
import { ConsumptionTrendChart } from './ConsumptionTrendChart';
import { KmDrivenChart } from './KmDrivenChart';
import { MonthlySpendingChart } from './MonthlySpendingChart';
import { SpendingByCategoryChart } from './SpendingByCategoryChart';
import type { DashboardCharts } from '@/api/types/dashboardCharts';
import { groupCategories, toConsumptionRows, toKmDrivenRows, toMonthlySpendingRows } from '@/lib/dashboardCharts';
import { useFormat } from '@/hooks/useFormat';

export interface ChartsGridProps {
  data: DashboardCharts | undefined;
  isLoading: boolean;
}

/**
 * I quattro grafici (recharts) di `ChartsSection`. Vive in un file a sé perché `ChartsSection` lo
 * carica con `React.lazy`: recharts pesa più di tutta l'app e non deve stare nel bundle iniziale.
 * Non importarlo da altri file in modo statico, o il chunk torna a farne parte.
 */
export function ChartsGrid({ data, isLoading }: ChartsGridProps) {
  const fmt = useFormat();

  // Righe derivate dalla risposta, ricalcolate solo se cambiano i dati o la lingua.
  const series = useMemo(() => {
    if (!data) return null;
    return {
      spending: toMonthlySpendingRows(data, fmt.month),
      categories: groupCategories(data.spendingByCategory),
      km: toKmDrivenRows(data, fmt.month),
      consumption: toConsumptionRows(data, fmt.month),
      fuelTypes: data.fuelTypes,
    };
  }, [data, fmt]);

  if (isLoading) return <ChartsSkeleton />;
  if (!series) return null;

  return (
    <div className="grid grid-cols-1 gap-3 md:gap-4 xl:grid-cols-2">
      <MonthlySpendingChart rows={series.spending} />
      <SpendingByCategoryChart slices={series.categories} />
      <KmDrivenChart rows={series.km} />
      <ConsumptionTrendChart rows={series.consumption} fuelTypes={series.fuelTypes} />
    </div>
  );
}
