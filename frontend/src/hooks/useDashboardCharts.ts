import { useQuery } from '@tanstack/react-query';
import { getDashboardCharts } from '@/api/endpoints/dashboard';

/** Finestra dei grafici in dashboard (il backend accetta 1–24 mesi). */
export const DASHBOARD_CHART_MONTHS = 12;

/**
 * Query keys della dashboard. `all` è la radice che invalidano le mutazioni di veicoli e record
 * (vedi `invalidateVehicleStats` in useVehicles): qui nessun import da useVehicles, niente cicli.
 */
export const dashboardKeys = {
  all: ['dashboard'] as const,
  charts: (months: number) => [...dashboardKeys.all, 'charts', months] as const,
};

export function useDashboardCharts(months = DASHBOARD_CHART_MONTHS, options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: dashboardKeys.charts(months),
    queryFn: () => getDashboardCharts(months),
    staleTime: 60_000,
    enabled: options.enabled ?? true,
  });
}
