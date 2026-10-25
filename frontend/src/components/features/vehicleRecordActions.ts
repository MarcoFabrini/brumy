import {
  Bell,
  CalendarCheck,
  ClipboardCheck,
  Fuel,
  Landmark,
  Package,
  Receipt,
  ShieldCheck,
  TriangleAlert,
  type LucideIcon,
} from 'lucide-react';
import type { ExpenseSection } from '@/api/types/expense';
import type { MaintenanceCategory } from '@/api/types/maintenance';

export type VehicleRecordKind =
  | 'refueling'
  | 'scheduled'
  | 'unscheduled'
  | 'inspection'
  | 'tax'
  | 'insurance'
  | 'expense'
  | 'component'
  | 'reminder';

/**
 * Le sezioni di un veicolo: tipo, pagina elenco (`listPath`) e rotta di inserimento (`path`), con la sezione come
 * parametro dove serve (lavori e revisione sono manutenzioni, tasse/assicurazione/altre sono spese), label i18n,
 * icona e colore dell'icona (quadratino iOS). Unica lista per il "+" mobile (QuickAddSheet → QuickAddDialog), il
 * menu "Aggiungi" del dettaglio su desktop e le righe del dettaglio veicolo. In ordine di frequenza.
 */
export const VEHICLE_RECORD_ACTIONS: {
  kind: VehicleRecordKind;
  path: string;
  listPath: string;
  /** "Nuovo …" */
  labelKey: string;
  /** Nome della sezione. */
  titleKey: string;
  Icon: LucideIcon;
  iconClassName: string;
  /** Lo stesso colore per i grafici (la classe Tailwind deve restare letterale). */
  color: string;
}[] = [
  { kind: 'refueling', path: '/refueling/new', listPath: '/refueling', labelKey: 'sections.refueling.new', titleKey: 'sections.refueling.title', Icon: Fuel, iconClassName: 'bg-[#34c759]', color: '#34c759' },
  { kind: 'scheduled', path: '/maintenance/new?category=scheduled', listPath: '/maintenance?category=scheduled', labelKey: 'sections.scheduled.new', titleKey: 'sections.scheduled.title', Icon: CalendarCheck, iconClassName: 'bg-[#ff9500]', color: '#ff9500' },
  { kind: 'unscheduled', path: '/maintenance/new?category=unscheduled', listPath: '/maintenance?category=unscheduled', labelKey: 'sections.unscheduled.new', titleKey: 'sections.unscheduled.title', Icon: TriangleAlert, iconClassName: 'bg-[#ff2d55]', color: '#ff2d55' },
  { kind: 'inspection', path: '/maintenance/new?category=inspection', listPath: '/maintenance?category=inspection', labelKey: 'sections.inspection.new', titleKey: 'sections.inspection.title', Icon: ClipboardCheck, iconClassName: 'bg-[#007aff]', color: '#007aff' },
  { kind: 'tax', path: '/expenses/new?section=tax', listPath: '/expenses?section=tax', labelKey: 'sections.tax.new', titleKey: 'sections.tax.title', Icon: Landmark, iconClassName: 'bg-[#af52de]', color: '#af52de' },
  { kind: 'insurance', path: '/expenses/new?section=insurance', listPath: '/expenses?section=insurance', labelKey: 'sections.insurance.new', titleKey: 'sections.insurance.title', Icon: ShieldCheck, iconClassName: 'bg-[#30b0c7]', color: '#30b0c7' },
  { kind: 'expense', path: '/expenses/new?section=other', listPath: '/expenses?section=other', labelKey: 'sections.expense.new', titleKey: 'sections.expense.title', Icon: Receipt, iconClassName: 'bg-[#5856d6]', color: '#5856d6' },
  { kind: 'component', path: '/components/new', listPath: '/components', labelKey: 'sections.component.new', titleKey: 'sections.component.title', Icon: Package, iconClassName: 'bg-[#a2845e]', color: '#a2845e' },
  { kind: 'reminder', path: '/reminders/new', listPath: '/reminders', labelKey: 'sections.reminder.new', titleKey: 'sections.reminder.title', Icon: Bell, iconClassName: 'bg-[#ff3b30]', color: '#ff3b30' },
];

/**
 * Sezioni di costo nei grafici: chiave della serie mensile (`spending` di `/api/dashboard/charts`) e della fetta di
 * `spendingByCategory` → sezione. Le altre spese hanno una fetta per categoria, non una sola.
 */
export const SPENDING_SERIES_SECTIONS = {
  refuelings: 'refueling',
  scheduled: 'scheduled',
  unscheduled: 'unscheduled',
  inspection: 'inspection',
  taxes: 'tax',
  insurance: 'insurance',
  expenses: 'expense',
} as const satisfies Record<string, VehicleRecordKind>;

export const SPENDING_SLICE_SECTIONS: Record<string, VehicleRecordKind> = {
  fuel: 'refueling',
  scheduled: 'scheduled',
  unscheduled: 'unscheduled',
  inspection: 'inspection',
  tax: 'tax',
  insurance: 'insurance',
};

/** Il percorso con il veicolo aggiunto ai parametri che ha già. */
export function withVehicle(path: string, vehicleId: number): string {
  return `${path}${path.includes('?') ? '&' : '?'}vehicleId=${vehicleId}`;
}

/** Sezione di un lavoro dalla sua categoria. */
export function maintenanceKind(category: MaintenanceCategory): VehicleRecordKind {
  return category;
}

/** Sezione di una spesa (le "altre" si chiamano `expense`). */
export function expenseKind(section: ExpenseSection): VehicleRecordKind {
  return section === 'other' ? 'expense' : section;
}

export function sectionOf(kind: VehicleRecordKind): (typeof VEHICLE_RECORD_ACTIONS)[number] {
  const section = VEHICLE_RECORD_ACTIONS.find((a) => a.kind === kind);
  if (!section) throw new Error(`Sezione sconosciuta: ${kind}`);
  return section;
}
