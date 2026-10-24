import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { ChartsSection } from './ChartsSection';
import { DASHBOARD_CHART_MONTHS } from '@/hooks/useDashboardCharts';
import { useVehicleCharts } from '@/hooks/useVehicles';

export interface VehicleChartsProps {
  vehicleId: number;
}

/** I grafici del solo veicolo, in fondo alla sua scheda (la Home ha solo le spese mensili di tutti i veicoli). */
export function VehicleCharts({ vehicleId }: VehicleChartsProps) {
  const { t } = useTranslation();
  const headingId = useId();
  const charts = useVehicleCharts(vehicleId, DASHBOARD_CHART_MONTHS);

  return (
    <ChartsSection
      headingId={headingId}
      title={t('vehicle.summary.charts')}
      data={charts.data}
      isLoading={charts.isLoading}
      error={charts.error}
    />
  );
}
