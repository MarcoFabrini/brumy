import { useSearchParams } from 'react-router-dom';
import { EXPENSE_SECTIONS, type ExpenseSection } from '@/api/types/expense';
import type { MaintenanceCategory, MaintenanceSection } from '@/api/types/maintenance';

/**
 * Sezione dei lavori dalla query string (`?category=`): `inspection` = revisione, tutto il resto = lavori (anche i
 * vecchi link `scheduled`/`unscheduled`). `newCategory` è la categoria proposta creando un lavoro: imprevisto solo
 * se il link lo chiede.
 */
export function useMaintenanceSectionParam(): { section: MaintenanceSection; newCategory: MaintenanceCategory } {
  const [params] = useSearchParams();
  const raw = params.get('category');
  if (raw === 'inspection') return { section: 'inspection', newCategory: 'inspection' };
  return { section: 'work', newCategory: raw === 'unscheduled' ? 'unscheduled' : 'scheduled' };
}

/** Sezione delle spese dalla query string (`?section=`); assente o sconosciuta = altre spese. */
export function useExpenseSectionParam(): ExpenseSection {
  const [params] = useSearchParams();
  const raw = params.get('section');
  return EXPENSE_SECTIONS.find((s) => s === raw) ?? 'other';
}
