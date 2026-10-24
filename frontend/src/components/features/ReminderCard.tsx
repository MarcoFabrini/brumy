import { Link } from 'react-router-dom';
import { Bell } from 'lucide-react';
import { ListRow } from '@/components/ui';
import { MetaLine } from './MetaLine';
import { ReminderBadge } from './ReminderBadge';
import { ReminderRecurrenceText } from './ReminderRecurrenceText';
import { useFormat } from '@/hooks/useFormat';
import { useVehicleName } from '@/hooks/useVehicleName';
import { isRecurringReminder, type Reminder } from '@/api/types/reminder';

export interface ReminderCardProps {
  reminder: Reminder;
  /** Mostra il nome del veicolo (liste che mescolano più veicoli, es. la Home). */
  showVehicleName?: boolean;
}

/** Un promemoria come riga di lista iOS: cosa, poi veicolo, data, km e ricorrenza; urgenza a destra. */
export function ReminderCard({ reminder: r, showVehicleName }: ReminderCardProps) {
  const fmt = useFormat();
  const vehicleName = useVehicleName(r.vehicleId);

  return (
    <ListRow
      as={Link}
      to={`/reminders/${r.id}`}
      icon={<Bell />}
      iconClassName="bg-[#ff3b30]"
      label={r.description}
      description={
        <MetaLine
          parts={[
            showVehicleName ? vehicleName : null,
            r.dueDate ? fmt.date(r.dueDate) : null,
            r.dueKm != null ? fmt.km(r.dueKm) : null,
            isRecurringReminder(r) ? <ReminderRecurrenceText reminder={r} /> : null,
          ]}
        />
      }
      value={<ReminderBadge reminder={r} />}
      chevron
    />
  );
}
