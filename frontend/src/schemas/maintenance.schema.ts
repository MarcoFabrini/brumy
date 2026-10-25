import { z } from 'zod';
import { MAINTENANCE_CATEGORIES, MAINTENANCE_TYPES } from '@/api/types/maintenance';
import { decimalString } from './decimal';

const dateString = z
  .string()
  .regex(/^\d{4}-\d{2}-\d{2}$/, 'common.invalid_date');

export const maintenanceSchema = z.object({
  vehicleId: z.number({ message: 'maintenance.vehicle.required' }).int().positive(),
  performedAt: dateString,
  km: z.number({ message: 'maintenance.km.required' }).int().nonnegative().max(9_999_999, 'common.km_too_large'),
  type: z.enum(MAINTENANCE_TYPES, { message: 'maintenance.type.required' }),
  category: z.enum(MAINTENANCE_CATEGORIES, { message: 'maintenance.category.required' }),
  description: z.string().min(1, 'maintenance.description.required').max(5000),
  cost: decimalString(2, { maxIntegerDigits: 8, allowZero: true }).nullable().optional(),
  workshop: z.string().max(200).nullable().optional(),
  shop: z.string().max(200).nullable().optional(),
  components: z.array(z.string().max(200)).max(30),
  notes: z.string().max(2000).nullable().optional(),
  // Solo in creazione della revisione: prossima scadenza per il promemoria (null = nessuno)
  nextDueDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'common.invalid_date').or(z.literal('')).nullable().optional(),
});

export type MaintenanceFormData = z.infer<typeof maintenanceSchema>;
