import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Car, Plus } from 'lucide-react';
import { Alert, Button, ListSection, Skeleton } from '@/components/ui';
import { EmptyState } from './EmptyState';
import { VehiclePicker } from './VehiclePicker';
import { useVehicles } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';

/**
 * VehicleScopedList — scaffold comune delle liste per-veicolo
 * (manutenzioni/rifornimenti/spese/scadenze):
 * - vehicleId === 0 → griglia VehiclePicker (con skeleton e empty "nessun veicolo");
 * - vehicleId > 0   → skeleton/errore/empty/lista.
 * Il veicolo attivo e l'azione "cambia veicolo" sono nel PageHeader della pagina
 * (titolo + freccia back). La pagina fornisce query, empty state e render della card.
 */
export interface VehicleScopedListProps<T> {
  vehicleId: number;
  onSelectVehicle: (id: number) => void;
  items: T[] | undefined;
  isLoading: boolean;
  error: Error | null;
  /** EmptyState già configurato per l'entità (icona/chiavi/CTA "nuovo"). */
  empty: ReactNode;
  /** Render della card; includere `key` sull'elemento. */
  renderItem: (item: T) => ReactNode;
}

export function VehicleScopedList<T>({
  vehicleId,
  onSelectVehicle,
  items,
  isLoading,
  error,
  empty,
  renderItem,
}: VehicleScopedListProps<T>) {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();
  const vehiclesQuery = useVehicles();

  const vehicles = vehiclesQuery.data ?? [];

  if (vehicleId === 0) {
    return (
      <>
        {vehiclesQuery.isLoading && (
          <div className="grid gap-3 sm:grid-cols-2">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-24 w-full rounded-lg" />
            ))}
          </div>
        )}
        {!vehiclesQuery.isLoading && vehicles.length === 0 && (
          <EmptyState
            Icon={Car}
            title={t('vehicle_picker.no_vehicles.title')}
            description={t('vehicle_picker.no_vehicles.description')}
            action={
              <Button asChild>
                <Link to="/vehicles/new">
                  <Plus />
                  {t('vehicle_picker.add_vehicle')}
                </Link>
              </Button>
            }
          />
        )}
        {!vehiclesQuery.isLoading && vehicles.length > 0 && (
          <VehiclePicker vehicles={vehicles} onSelect={onSelectVehicle} />
        )}
      </>
    );
  }

  return (
    <>
      {isLoading && (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24 w-full rounded-lg" />
          ))}
        </div>
      )}

      {error && <Alert variant="error">{errorMessage(error)}</Alert>}

      {items && items.length === 0 && empty}

      {items && items.length > 0 && <ListSection>{items.map(renderItem)}</ListSection>}
    </>
  );
}
