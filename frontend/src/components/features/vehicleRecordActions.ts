import { Bell, Fuel, Receipt, Wrench, type LucideIcon } from 'lucide-react';

export type VehicleRecordKind = 'refueling' | 'maintenance' | 'expense' | 'reminder';

/**
 * Cosa si può aggiungere a un veicolo: tipo, rotta `/new` (con `?vehicleId=`), label i18n, icona e colore dell'icona
 * (quadratino iOS). Unica lista per il "+" mobile (QuickAddSheet → QuickAddDialog) e il menu "Aggiungi" del
 * dettaglio su desktop. In ordine di frequenza: il rifornimento è la cosa che si registra più spesso.
 */
export const VEHICLE_RECORD_ACTIONS: {
  kind: VehicleRecordKind;
  path: string;
  labelKey: string;
  Icon: LucideIcon;
  iconClassName: string;
}[] = [
  { kind: 'refueling', path: '/refueling/new', labelKey: 'refueling.new', Icon: Fuel, iconClassName: 'bg-[#34c759]' },
  { kind: 'maintenance', path: '/maintenance/new', labelKey: 'maintenance.new', Icon: Wrench, iconClassName: 'bg-[#ff9500]' },
  { kind: 'expense', path: '/expenses/new', labelKey: 'expense.new', Icon: Receipt, iconClassName: 'bg-[#5856d6]' },
  { kind: 'reminder', path: '/reminders/new', labelKey: 'reminder.new', Icon: Bell, iconClassName: 'bg-[#ff3b30]' },
];
