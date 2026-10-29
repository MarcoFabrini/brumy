import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Fuel, Plus } from 'lucide-react';
import { Button } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  EmptyState,
  ListSearchField,
  LoadMoreButton,
  NoSearchResults,
  RefuelingCard,
  VehicleScopedList,
} from '@/components/features';
import { useListSearch } from '@/hooks/useListSearch';
import { useRefuelingList } from '@/hooks/useRefuelings';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';
import { useVehicleName } from '@/hooks/useVehicleName';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { LIST_SEARCH_FAMILIES, shouldShowListSearch } from '@/lib/listSearch';

/** Rifornimenti del veicolo scelto, con ricerca e pagine successive caricate scorrendo. */
export function RefuelingListPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { vehicleId, setParams } = useVehicleIdParam();
  const vehicleName = useVehicleName(vehicleId);
  // Veicolo condiviso in sola lettura: nessun "nuovo".
  const canEdit = useCanEditVehicle(vehicleId);
  const search = useListSearch(LIST_SEARCH_FAMILIES.refueling);
  const list = useRefuelingList(vehicleId, search.searchQuery);
  const items = list.data?.pages.flat();

  // Link condiviso: nell'header è nascosto su mobile (c'è il "+" della barra), nello stato vuoto no.
  const newLink = (
    <Link to={`/refueling/new?vehicleId=${vehicleId}`}>
      <Plus />
      {t('refueling.new')}
    </Link>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={vehicleId > 0 ? t('nav.refueling') : undefined}
        srTitle={t('nav.refueling')}
        description={vehicleId > 0 ? vehicleName : undefined}
        onBack={vehicleId > 0 ? () => navigate(`/vehicles/${vehicleId}`) : undefined}
        action={
          vehicleId > 0 && canEdit && (
            <Button asChild className="hidden md:inline-flex">
              {newLink}
            </Button>
          )
        }
      />

      <VehicleScopedList
        vehicleId={vehicleId}
        onSelectVehicle={(id) => setParams({ vehicleId: String(id) })}
        toolbar={
          shouldShowListSearch(search.query, search.term, items) && (
            <ListSearchField value={search.query} onChange={search.setQuery} onClear={search.clear} />
          )
        }
        items={items}
        isLoading={list.isLoading}
        error={list.error}
        busy={list.isPlaceholderData}
        footer={
          <LoadMoreButton
            hasNextPage={list.hasNextPage}
            isFetchingNextPage={list.isFetchingNextPage}
            failed={list.isFetchNextPageError}
            onLoadMore={list.fetchNextPage}
          />
        }
        empty={
          search.term ? (
            <NoSearchResults query={search.term} onClear={search.clear} />
          ) : (
            <EmptyState
              Icon={Fuel}
              title={t('refueling.empty.title')}
              description={t('refueling.empty.description')}
              action={canEdit ? <Button asChild>{newLink}</Button> : undefined}
            />
          )
        }
        renderItem={(r) => <RefuelingCard key={r.id} refueling={r} />}
      />
    </div>
  );
}
