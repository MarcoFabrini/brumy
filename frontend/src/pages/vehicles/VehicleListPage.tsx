import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Car, Plus } from 'lucide-react';
import { Alert, Button, ListSection, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { ArchivedVehiclesSection, EmptyState, VehicleCard } from '@/components/features';
import { useArchivedVehicles, useVehicles } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';

/**
 * VehicleListPage — lista veicoli con loading skeleton + empty state.
 * Pagina pura orchestratore: 0 markup raw, 0 logic.
 */
export function VehicleListPage() {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();
  const { data, isLoading, error } = useVehicles();
  // Se la query degli archiviati fallisce la sezione semplicemente non compare: la lista resta usabile.
  const archived = useArchivedVehicles();

  return (
    <div className="space-y-6">
      <PageHeader
        srTitle={t('nav.vehicles')}
        action={
          <>
            {/* Come nelle app iOS: un "+" nella barra su mobile, il pulsante con testo su desktop. */}
            <Button asChild variant="ghost" size="icon" className="-mr-2 text-primary md:hidden">
              <Link to="/vehicles/new" aria-label={t('vehicle.new')}>
                <Plus className="!size-6" />
              </Link>
            </Button>
            <Button asChild className="hidden md:inline-flex">
              <Link to="/vehicles/new">
                <Plus />
                {t('vehicle.new')}
              </Link>
            </Button>
          </>
        }
      />

      {isLoading && (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24 w-full rounded-lg" />
          ))}
        </div>
      )}

      {error && <Alert variant="error">{errorMessage(error)}</Alert>}

      {data && data.length === 0 && (
        <EmptyState
          Icon={Car}
          title={t('vehicle.empty.title')}
          description={t('vehicle.empty.description')}
          action={
            <Button asChild>
              <Link to="/vehicles/new">
                <Plus />
                {t('vehicle.new')}
              </Link>
            </Button>
          }
        />
      )}

      {data && data.length > 0 && (
        <ListSection>
          {data.map((vehicle) => (
            <VehicleCard key={vehicle.id} vehicle={vehicle} />
          ))}
        </ListSection>
      )}

      <ArchivedVehiclesSection vehicles={archived.data ?? []} />
    </div>
  );
}
