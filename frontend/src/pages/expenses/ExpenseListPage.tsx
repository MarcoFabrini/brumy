import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { Button } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  EmptyState,
  ExpenseCard,
  ListSearchField,
  LoadMoreButton,
  NoSearchResults,
  VehicleScopedList,
} from '@/components/features';
import { expenseKind, sectionOf, withVehicle } from '@/components/features/vehicleRecordActions';
import { useExpenses } from '@/hooks/useExpenses';
import { useListSearch } from '@/hooks/useListSearch';
import { useExpenseSectionParam } from '@/hooks/useSectionParam';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';
import { useVehicleName } from '@/hooks/useVehicleName';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { LIST_SEARCH_FAMILIES, shouldShowListSearch } from '@/lib/listSearch';

/** Una sezione delle spese (`?section=tax|insurance|other`) per il veicolo scelto, con ricerca e pagine successive. */
export function ExpenseListPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const sectionParam = useExpenseSectionParam();
  const section = sectionOf(expenseKind(sectionParam));
  const { vehicleId, setParams } = useVehicleIdParam();
  const vehicleName = useVehicleName(vehicleId);
  // Veicolo condiviso in sola lettura: nessun "nuovo".
  const canEdit = useCanEditVehicle(vehicleId);
  const search = useListSearch(LIST_SEARCH_FAMILIES.expense);
  const list = useExpenses(vehicleId, sectionParam, search.searchQuery);
  const items = list.data?.pages.flat();

  // Link condiviso: nell'header è nascosto su mobile (c'è il "+" della barra), nello stato vuoto no.
  const newLink = (
    <Link to={withVehicle(section.path, vehicleId)}>
      <Plus />
      {t(section.labelKey)}
    </Link>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={vehicleId > 0 ? t(section.titleKey) : undefined}
        srTitle={t(section.titleKey)}
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
        onSelectVehicle={(id) => setParams({ section: sectionParam, vehicleId: String(id) })}
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
              Icon={section.Icon}
              title={t(`sections.${section.kind}.empty`)}
              description={t('expense.empty.description')}
              action={canEdit ? <Button asChild>{newLink}</Button> : undefined}
            />
          )
        }
        renderItem={(e) => <ExpenseCard key={e.id} expense={e} />}
      />
    </div>
  );
}
