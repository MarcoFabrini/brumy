import { useSearchParams } from 'react-router-dom';
import { EXPENSE_SECTIONS, type ExpenseSection } from '@/api/types/expense';
import { MAINTENANCE_CATEGORIES, type MaintenanceCategory } from '@/api/types/maintenance';

/** Sezione dei lavori dalla query string (`?category=`); assente o sconosciuta = lavori ordinari. */
export function useMaintenanceCategoryParam(): MaintenanceCategory {
  const [params] = useSearchParams();
  const raw = params.get('category');
  return MAINTENANCE_CATEGORIES.find((c) => c === raw) ?? 'scheduled';
}

/** Sezione delle spese dalla query string (`?section=`); assente o sconosciuta = altre spese. */
export function useExpenseSectionParam(): ExpenseSection {
  const [params] = useSearchParams();
  const raw = params.get('section');
  return EXPENSE_SECTIONS.find((s) => s === raw) ?? 'other';
}
