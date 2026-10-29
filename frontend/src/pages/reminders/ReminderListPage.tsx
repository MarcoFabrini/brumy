import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Bell, Plus } from 'lucide-react';
import { Alert, Button, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  EmptyState,
  ListSearchField,
  NoSearchResults,
  ReminderCard,
  ReminderOverviewList,
  VehicleScopedList,
} from '@/components/features';
import { useReminderOverview, useReminders } from '@/hooks/useReminders';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useListSearch, type ListSearch } from '@/hooks/useListSearch';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';
import { useVehicleName } from '@/hooks/useVehicleName';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { LIST_SEARCH_FAMILIES, shouldShowListSearch } from '@/lib/listSearch';
import { cn } from '@/lib/utils';

/**
 * Promemoria del veicolo scelto (`?vehicleId=N`) o, dalla tab "Scadenze", di tutti i veicoli propri; entrambi con la
 * ricerca (`?q=`).
 */
export function ReminderListPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { vehicleId, setParams } = useVehicleIdParam();
  const vehicleName = useVehicleName(vehicleId);
  // Veicolo condiviso in sola lettura: nessun "nuovo".
  const canEdit = useCanEditVehicle(vehicleId);
  const search = useListSearch(LIST_SEARCH_FAMILIES.reminder);
  // Spenta senza veicolo: dalla tab "Scadenze" la ricerca va alla panoramica (AllDeadlines)
  const { data, isLoading, error, isPlaceholderData } = useReminders(vehicleId, search.searchQuery);

  // Link condiviso: nell'header è nascosto su mobile (c'è il "+" della barra), nello stato vuoto no.
  const newLink = (
    <Link to={`/reminders/new?vehicleId=${vehicleId}`}>
      <Plus />
      {t('reminder.new')}
    </Link>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={vehicleId > 0 ? t('nav.reminders') : undefined}
        srTitle={t('nav.tabs.deadlines')}
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

      {/* Dalla tab "Scadenze" (nessun veicolo): tutte le scadenze dei veicoli propri, dalle più urgenti. */}
      {vehicleId === 0 ? (
        <AllDeadlines search={search} />
      ) : (
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
                Icon={Bell}
                title={t('reminder.empty.title')}
                description={t('reminder.empty.description')}
                action={canEdit ? <Button asChild>{newLink}</Button> : undefined}
              />
            )
          }
          renderItem={(r) => <ReminderCard key={r.id} reminder={r} />}
        />
      )}
    </div>
  );
}

/** Tab "Scadenze": le scadenze di tutti i veicoli propri, dalle più urgenti, con la ricerca della pagina. */
function AllDeadlines({ search }: { search: ListSearch }) {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();
  const { data, isLoading, error, isPlaceholderData } = useReminderOverview({ search: search.searchQuery });

  let content: ReactNode;
  if (error) {
    content = <Alert variant="error">{errorMessage(error)}</Alert>;
  } else if (isLoading || !data) {
    content = <Skeleton className="h-48 w-full rounded-xl" />;
  } else if (data.length === 0) {
    content = search.term ? (
      <NoSearchResults query={search.term} onClear={search.clear} />
    ) : (
      <EmptyState Icon={Bell} title={t('reminder.overview.empty_title')} description={t('reminder.overview.empty_description')} />
    );
  } else {
    content = (
      <div aria-busy={isPlaceholderData || undefined} className={cn('transition-opacity', isPlaceholderData && 'opacity-60')}>
        <ReminderOverviewList items={data} />
      </div>
    );
  }

  return (
    <>
      {shouldShowListSearch(search.query, search.term, data) && (
        <ListSearchField value={search.query} onChange={search.setQuery} onClear={search.clear} />
      )}
      {content}
    </>
  );
}
