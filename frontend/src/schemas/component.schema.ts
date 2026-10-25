import { z } from 'zod';
import { decimalString } from './decimal';

const optionalText = (max: number) => z.string().max(max).nullable().optional();

export const componentSchema = z.object({
  vehicleId: z.number().int().positive(),
  name: z.string().trim().min(1, 'component.name.required').max(200),
  code: optionalText(200),
  serialNumber: optionalText(200),
  shop: optionalText(200),
  purchasedAt: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, 'common.invalid_date')
    .nullable()
    .optional(),
  price: decimalString(2, { maxIntegerDigits: 8, allowZero: true }).nullable().optional(),
  notes: optionalText(5000),
});

export type ComponentFormData = z.infer<typeof componentSchema>;
