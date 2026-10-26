import { Suspense } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Select, SelectContent, SelectItem, SelectTrigger, SelectValue, Skeleton } from '@/components/ui';
import { useFollowedFuelPrices, useFuelPricesStatus } from '@/hooks/useFuelPrices';
import { useVehicles } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFuelTrendStore } from '@/stores/useFuelTrendStore';
import { PRICE_FUELS, defaultTrendFuel } from '@/lib/fuelPriceTrend';
import { lazyNamed } from '@/lib/lazyNamed';

// recharts arriva in un chunk a parte, solo quando il grafico compare.
const FuelPriceTrendChart = lazyNamed(() => import('./FuelPriceTrendChart'), 'FuelPriceTrendChart');

/**
 * Andamento dei prezzi dei distributori seguiti, con la scelta del carburante (ricordata sul dispositivo; all'inizio
 * quello dei propri veicoli). Senza `valueId` è il riquadro della Home, con tutti i distributori seguiti, e non compare
 * se non se ne segue nessuno; con `valueId` mostra solo quel distributore dell'elenco.
 */
export function FollowedFuelPrices({ valueId }: { valueId?: number }) {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();
  const status = useFuelPricesStatus();
  const vehicles = useVehicles();
  const chosen = useFuelTrendStore((s) => s.fuel);
  const setFuel = useFuelTrendStore((s) => s.setFuel);
  const fuel = chosen ?? defaultTrendFuel(vehicles.data ?? []);
  const enabled = status.data?.enabled === true;
  const followed = useFollowedFuelPrices(fuel, { enabled });

  if (!enabled) return null;
  if (followed.error) return <Alert variant="error">{errorMessage(followed.error)}</Alert>;
  if (!followed.data) return <Skeleton className="h-72 w-full rounded-xl" />;

  const stations = valueId === undefined ? followed.data : followed.data.filter((s) => s.valueId === valueId);
  if (stations.length === 0) return null;

  const fuelSelect = (
    <Select value={fuel} onValueChange={(value) => setFuel(PRICE_FUELS.find((f) => f === value) ?? fuel)}>
      <SelectTrigger className="w-auto shrink-0" aria-label={t('fuel_prices.trend_fuel')}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {PRICE_FUELS.map((f) => (
          <SelectItem key={f} value={f}>
            {t(`vehicle.fuel.${f}`)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );

  return (
    <Suspense fallback={<Skeleton className="h-72 w-full rounded-xl" />}>
      <FuelPriceTrendChart
        title={valueId === undefined ? t('fuel_prices.followed_title') : t('fuel_prices.trend_title')}
        fuel={fuel}
        stations={stations}
        action={fuelSelect}
      />
    </Suspense>
  );
}
