import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Bell, Repeat } from 'lucide-react';
import { Card, CardContent } from '@/components/ui';
import { ReminderBadge } from './ReminderBadge';
import { ReminderRecurrenceText } from './ReminderRecurrenceText';
import { useFormat } from '@/hooks/useFormat';
import { useVehicleName } from '@/hooks/useVehicleName';
import { isRecurringReminder, type Reminder } from '@/api/types/reminder';

export interface ReminderCardProps {
  reminder: Reminder;
  /** Mostra il nome del veicolo (utile in liste che aggregano più veicoli, es. dashboard). */
  showVehicleName?: boolean;
}

export function ReminderCard({ reminder, showVehicleName }: ReminderCardProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const r = reminder;
  const vehicleName = useVehicleName(r.vehicleId);

  return (
    <Card>
      <Link to={`/reminders/${r.id}`} className="block">
        <CardContent standalone className="flex items-start gap-4">
          <div className="rounded-md bg-primary/10 p-3">
            <Bell className="size-5 text-primary" />
          </div>

          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex items-center justify-between gap-2">
              <h3 className="truncate text-base font-semibold">
                {t(`reminder.type_options.${r.type}`)}
              </h3>
              <ReminderBadge reminder={r} />
            </div>
            {showVehicleName && vehicleName && (
              <p className="truncate text-sm text-muted-foreground">{vehicleName}</p>
            )}
            <p className="line-clamp-2 text-sm text-muted-foreground">{r.description}</p>
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
              {r.dueDate && <span>{fmt.date(r.dueDate)}</span>}
              {r.dueDate && r.dueKm != null && <span>·</span>}
              {r.dueKm != null && <span>{fmt.km(r.dueKm)}</span>}
              {isRecurringReminder(r) && (
                <>
                  <span>·</span>
                  <span className="inline-flex items-center gap-1">
                    <Repeat className="size-3" aria-hidden="true" />
                    <ReminderRecurrenceText reminder={r} />
                  </span>
                </>
              )}
            </div>
          </div>
        </CardContent>
      </Link>
    </Card>
  );
}
