import { useTranslation } from 'react-i18next';
import { recurrenceInterval, type Reminder } from '@/api/types/reminder';
import { useFormat } from '@/hooks/useFormat';

export interface ReminderRecurrenceTextProps {
  reminder: Pick<Reminder, 'recurrenceMonths' | 'recurrenceKm'>;
}

/** "ogni 2 anni · ogni 10.000 km": le ricorrenze del promemoria, vuoto se è una tantum. */
export function ReminderRecurrenceText({ reminder }: ReminderRecurrenceTextProps) {
  const { t } = useTranslation();
  const fmt = useFormat();

  const parts: string[] = [];
  if (reminder.recurrenceMonths != null) {
    const { unit, count } = recurrenceInterval(reminder.recurrenceMonths);
    parts.push(t(`reminder.every_${unit}`, { count }));
  }
  if (reminder.recurrenceKm != null) {
    parts.push(t('reminder.every_km', { km: fmt.km(reminder.recurrenceKm) }));
  }

  return parts.join(' · ');
}
