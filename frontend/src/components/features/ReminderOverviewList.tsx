import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useListReturnState } from '@/hooks/useListReturnState';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Bell, Clock } from 'lucide-react';
import { ListRow, ListSection, SwipeActions } from '@/components/ui';
import { CompleteReminderDialog } from './CompleteReminderDialog';
import { useFormat } from '@/hooks/useFormat';
import type { ReminderOverviewItem } from '@/api/types/reminder';

const URGENCY_STYLE = {
  overdue: { Icon: AlertTriangle, iconClassName: 'bg-[#ff3b30]' },
  soon: { Icon: Clock, iconClassName: 'bg-[#ff9500]' },
  ok: { Icon: Bell, iconClassName: 'bg-[#8e8e93]' },
} as const;

const SECTIONS = [
  { urgency: 'overdue', titleKey: 'reminder.overview.overdue' },
  { urgency: 'soon', titleKey: 'reminder.overview.soon' },
  { urgency: 'ok', titleKey: 'reminder.overview.later' },
] as const;

/**
 * Tab "Scadenze": le scadenze di tutti i veicoli propri divise in Scadute, In scadenza e Più avanti (già ordinate
 * dal backend).
 */
export function ReminderOverviewList({ items }: { items: ReminderOverviewItem[] }) {
  const { t } = useTranslation();

  return (
    <div className="space-y-6">
      {SECTIONS.map(({ urgency, titleKey }) => {
        const rows = items.filter((r) => r.urgency === urgency);
        if (rows.length === 0) return null;
        return <ReminderOverviewSection key={urgency} title={`${t(titleKey)} · ${rows.length}`} items={rows} />;
      })}
    </div>
  );
}

export interface ReminderOverviewSectionProps {
  title?: ReactNode;
  items: ReminderOverviewItem[];
  /** Righe in coda al gruppo (es. "Vedi tutte"). */
  after?: ReactNode;
}

/**
 * Un gruppo di scadenze. Ogni riga dice cosa, quale veicolo e quando (data e/o km, con i km mancanti); tocca per
 * aprirla, scorri verso sinistra per completarla (data e km effettivi nel foglio; per un ricorrente nasce la
 * successiva). Usato dalla tab "Scadenze" e dal "Da fare" della Home.
 */
export function ReminderOverviewSection({ title, items, after }: ReminderOverviewSectionProps) {
  const returnState = useListReturnState();
  const { t } = useTranslation();
  const [completing, setCompleting] = useState<ReminderOverviewItem | null>(null);

  return (
    <>
      <ListSection title={title}>
        {items.map((r) => {
          const { Icon, iconClassName } = URGENCY_STYLE[r.urgency];
          return (
            <SwipeActions
              key={r.id}
              actions={[
                {
                  label: t('reminder.overview.complete'),
                  className: 'bg-[#34c759]',
                  onAction: () => setCompleting(r),
                },
              ]}
            >
              <ListRow
                as={Link}
                to={`/reminders/${r.id}`}
                state={returnState}
                icon={<Icon />}
                iconClassName={iconClassName}
                label={r.description}
                description={<DueLine reminder={r} />}
                chevron
              />
            </SwipeActions>
          );
        })}
        {after}
      </ListSection>
      {completing && (
        <CompleteReminderDialog
          open
          onOpenChange={(open) => !open && setCompleting(null)}
          reminder={completing}
          onCompleted={() => setCompleting(null)}
        />
      )}
    </>
  );
}

function DueLine({ reminder: r }: { reminder: ReminderOverviewItem }) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const kmLeft = r.dueKm != null ? r.dueKm - r.currentKm : null;
  const parts = [r.vehicleName];
  if (r.dueDate) parts.push(fmt.date(r.dueDate));
  if (r.dueKm != null && kmLeft != null) {
    parts.push(
      kmLeft >= 0
        ? t('reminder.overview.km_left', { due: fmt.km(r.dueKm), left: fmt.km(kmLeft) })
        : t('reminder.overview.km_over', { due: fmt.km(r.dueKm), over: fmt.km(-kmLeft) }),
    );
  }
  return <span className="block">{parts.join(' · ')}</span>;
}
