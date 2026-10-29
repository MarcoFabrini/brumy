import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Car, Plus } from 'lucide-react';
import { Alert, Button, ListSection, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { ArchivedVehiclesSection, EmptyState, ListSearchField, NoSearchResults, VehicleCard } from '@/components/features';
import { useArchivedVehicles, useVehicles } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useListSearch } from '@/hooks/useListSearch';
import { LIST_SEARCH_FAMILIES, shouldShowListSearch } from '@/lib/listSearch';
import { cn } from '@/lib/utils';

/**
 * VehicleListPage — lista veicoli con loading skeleton + empty state e ricerca (`?q=`), che cerca anche tra gli
 * archiviati: con una ricerca la loro sezione è già aperta e "nessun risultato" vale solo se non c'è niente in entrambe.
 * Pagina pura orchestratore: 0 markup raw, 0 logic.
 */
export function VehicleListPage() {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();
  const search = useListSearch(LIST_SEARCH_FAMILIES.vehicle);
  const { data, isLoading, error, isPlaceholderData } = useVehicles(search.searchQuery);
  // Se la query degli archiviati fallisce la sezione semplicemente non compare: la lista resta usabile.
  const archived = useArchivedVehicles(search.searchQuery);
  const archivedVehicles = archived.data ?? [];
  const searching = search.term !== '';
  const busy = isPlaceholderData || archived.isPlaceholderData;
  // Se la query degli archiviati fallisce contano come nessuno: "Nessun risultato" resta visibile
  const nothingFound = searching && data?.length === 0 && (archived.isError || archived.data?.length === 0);
  const allVehicles = data && archived.data ? [...data, ...archived.data] : undefined;

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

      {shouldShowListSearch(search.query, search.term, allVehicles) && (
        <ListSearchField value={search.query} onChange={search.setQuery} onClear={search.clear} />
      )}

      {isLoading && (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24 w-full rounded-lg" />
          ))}
        </div>
      )}

      {error && <Alert variant="error">{errorMessage(error)}</Alert>}

      {nothingFound && <NoSearchResults query={search.term} onClear={search.clear} />}

      {!searching && data && data.length === 0 && (
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

      <div aria-busy={busy || undefined} className={cn('space-y-6 transition-opacity', busy && 'opacity-60')}>
        {data && data.length > 0 && (
          <ListSection>
            {data.map((vehicle) => (
              <VehicleCard key={vehicle.id} vehicle={vehicle} />
            ))}
          </ListSection>
        )}

        <ArchivedVehiclesSection vehicles={archivedVehicles} alwaysOpen={searching} />
      </div>
    </div>
  );
}
