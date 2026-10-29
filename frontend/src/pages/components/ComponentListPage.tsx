import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Package, Plus } from 'lucide-react';
import { Button } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  ComponentCard,
  EmptyState,
  ListSearchField,
  NoSearchResults,
  VehicleScopedList,
} from '@/components/features';
import { useComponents } from '@/hooks/useComponents';
import { useListSearch } from '@/hooks/useListSearch';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';
import { useVehicleName } from '@/hooks/useVehicleName';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { LIST_SEARCH_FAMILIES, shouldShowListSearch } from '@/lib/listSearch';

/** Componenti (pezzi comprati) del veicolo scelto, per nome, con ricerca. */
export function ComponentListPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { vehicleId, setParams } = useVehicleIdParam();
  const vehicleName = useVehicleName(vehicleId);
  const canEdit = useCanEditVehicle(vehicleId);
  const search = useListSearch(LIST_SEARCH_FAMILIES.component);
  const { data, isLoading, error, isPlaceholderData } = useComponents(vehicleId, search.searchQuery);

  const newLink = (
    <Link to={`/components/new?vehicleId=${vehicleId}`}>
      <Plus />
      {t('sections.component.new')}
    </Link>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={vehicleId > 0 ? t('sections.component.title') : undefined}
        srTitle={t('sections.component.title')}
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
          shouldShowListSearch(search.query, search.term, data) && (
            <ListSearchField value={search.query} onChange={search.setQuery} onClear={search.clear} />
          )
        }
        items={data}
        isLoading={isLoading}
        error={error}
        busy={isPlaceholderData}
        empty={
          search.term ? (
            <NoSearchResults query={search.term} onClear={search.clear} />
          ) : (
            <EmptyState
              Icon={Package}
              title={t('sections.component.empty')}
              description={t('component.empty_description')}
              action={canEdit ? <Button asChild>{newLink}</Button> : undefined}
            />
          )
        }
        renderItem={(c) => <ComponentCard key={c.id} component={c} />}
      />
    </div>
  );
}
