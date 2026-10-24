import { Suspense } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Car, CheckCircle2, Plus } from 'lucide-react';
import { Alert, ListRow, ListSection, Skeleton } from '@/components/ui';
import { EmailVerificationBanner, PageHeader } from '@/components/layout';
import { MonthSummaryCard, ReminderOverviewSection } from '@/components/features';
import { useDashboard } from '@/hooks/useDashboard';
import { useDashboardCharts } from '@/hooks/useDashboardCharts';
import { useReminderOverview } from '@/hooks/useReminders';
import { useActiveMembership } from '@/hooks/useActiveMembership';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFormat } from '@/hooks/useFormat';
import { lazyNamed } from '@/lib/lazyNamed';
import { monthSpending } from '@/lib/monthSpending';
import type { ReminderOverviewItem } from '@/api/types/reminder';

// recharts (~400 kB) arriva in un chunk a parte, solo quando il grafico compare.
const HomeSpendingChart = lazyNamed(() => import('@/components/features/HomeSpendingChart'), 'HomeSpendingChart');

/** Quante scadenze urgenti mostra il "Da fare" prima del "Vedi tutte". */
const TODO_LIMIT = 4;

const URGENCY_TEXT = {
  overdue: 'text-destructive-text',
  soon: 'text-[#c93400] dark:text-[#ff9f0a]',
} as const;

/**
 * Home: cosa è successo questo mese e cosa c'è da fare, sui veicoli propri dell'utente.
 * 1. Speso questo mese, col confronto sul mese prima e la ripartizione.
 * 2. Da fare: le scadenze scadute o in scadenza, completabili scorrendo.
 * 3. I veicoli, con km attuali e stato delle scadenze.
 * 4. Un solo grafico, le spese mensili; gli altri stanno nella scheda di ogni veicolo.
 */
export function DashboardPage() {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();
  const dash = useDashboard();
  const hasVehicles = dash.vehicles.length > 0;
  // Grafici e scadenze solo con almeno un veicolo proprio: altrimenti niente richieste (il backend filtra comunque).
  const charts = useDashboardCharts(undefined, { enabled: hasVehicles });
  const overview = useReminderOverview({ enabled: hasVehicles });
  // Su mobile il titolo grande della Home è l'organizzazione attiva (la cambi dal Profilo); da md è l'h1 per
  // screen reader e il nome sta nella sidebar.
  const orgName = useActiveMembership()?.organization.name?.trim() || t('nav.dashboard');

  return (
    <div className="space-y-6">
      <PageHeader srTitle={orgName} />
      <EmailVerificationBanner />

      {dash.error ? <Alert variant="error">{errorMessage(dash.error)}</Alert> : null}

      {dash.isLoading ? (
        <div className="space-y-6">
          <Skeleton className="h-40 w-full rounded-xl" />
          <Skeleton className="h-32 w-full rounded-xl" />
        </div>
      ) : null}

      {hasVehicles && (
        <>
          {charts.data ? (
            <MonthSummaryCard summary={monthSpending(charts.data)} />
          ) : charts.isLoading ? (
            <Skeleton className="h-40 w-full rounded-xl" />
          ) : null}

          <TodoSection items={overview.data} error={overview.error} />
        </>
      )}

      {!dash.isLoading && !dash.error && <VehiclesSection dash={dash} reminders={overview.data} />}

      {charts.error ? <Alert variant="error">{errorMessage(charts.error)}</Alert> : null}
      {hasVehicles && charts.data && (
        <Suspense fallback={<Skeleton className="h-72 w-full rounded-xl" />}>
          <HomeSpendingChart data={charts.data} />
        </Suspense>
      )}
    </div>
  );
}

function TodoSection({ items, error }: { items: ReminderOverviewItem[] | undefined; error: unknown }) {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();

  if (error) return <Alert variant="error">{errorMessage(error)}</Alert>;
  if (!items) return <Skeleton className="h-32 w-full rounded-xl" />;

  const urgent = items.filter((r) => r.urgency !== 'ok');
  const seeAll = (
    <ListRow as={Link} to="/reminders" label={<span className="text-primary">{t('dashboard.see_all_deadlines')}</span>} chevron />
  );

  if (urgent.length === 0) {
    return (
      <ListSection title={t('dashboard.todo')}>
        <ListRow icon={<CheckCircle2 />} iconClassName="bg-[#34c759]" label={t('dashboard.todo_none')} />
        {items.length > 0 && seeAll}
      </ListSection>
    );
  }

  return <ReminderOverviewSection title={t('dashboard.todo')} items={urgent.slice(0, TODO_LIMIT)} after={seeAll} />;
}

function VehiclesSection({
  dash,
  reminders,
}: {
  dash: ReturnType<typeof useDashboard>;
  reminders: ReminderOverviewItem[] | undefined;
}) {
  const { t } = useTranslation();
  const fmt = useFormat();

  // La scadenza peggiore di ogni veicolo: la panoramica è già ordinata dalla più urgente.
  const worst = new Map<number, 'overdue' | 'soon'>();
  for (const r of reminders ?? []) {
    if (r.urgency !== 'ok' && !worst.has(r.vehicleId)) worst.set(r.vehicleId, r.urgency);
  }

  return (
    <ListSection
      title={t('nav.vehicles')}
      footer={dash.excludedCount > 0 ? t('dashboard.owned_only_hint', { count: dash.excludedCount }) : undefined}
    >
      {dash.vehicles.map((v) => {
        const stats = dash.statsById.get(v.id);
        const urgency = worst.get(v.id);
        return (
          <ListRow
            key={v.id}
            as={Link}
            to={`/vehicles/${v.id}`}
            icon={<Car />}
            iconClassName="bg-[#007aff]"
            label={v.name}
            description={stats ? fmt.km(stats.currentKm) : `${v.brand} ${v.model}`}
            value={
              urgency ? (
                <span className={`text-[0.9375rem] ${URGENCY_TEXT[urgency]}`}>{t(`reminder.urgency.${urgency}`)}</span>
              ) : undefined
            }
            chevron
          />
        );
      })}
      <ListRow
        as={Link}
        to="/vehicles/new"
        icon={<Plus />}
        iconClassName="bg-[#8e8e93]"
        label={<span className="text-primary">{t('dashboard.add_vehicle')}</span>}
      />
    </ListSection>
  );
}
