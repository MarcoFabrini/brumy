import { z } from 'zod';
import { decimalString } from './decimal';
import { FUEL_TYPES, VEHICLE_TYPES } from '@/api/types/vehicle';

/** Lunghezza massima delle note: stessa del backend (`VehicleRequest::$notes`) e del `maxLength` del form. */
export const VEHICLE_NOTES_MAX_LENGTH = 5000;

/**
 * Vehicle Zod schema — single source of truth per form + validation.
 * Match con backend `App\Dto\Request\VehicleRequest`.
 */
export const vehicleSchema = z
  .object({
    name: z.string().min(1, 'vehicle.name.required').max(100),
    brand: z.string().min(1, 'vehicle.brand.required').max(100),
    model: z.string().min(1, 'vehicle.model.required').max(100),
    year: z
      .number({ message: 'vehicle.year.required' })
      .int()
      .min(1900, 'vehicle.year.out_of_range')
      // valutato a ogni validazione, non al caricamento del modulo: una PWA aperta a cavallo del 31/12
      // non deve tenersi il limite dell'anno vecchio
      .refine((y) => y <= new Date().getFullYear() + 1, 'vehicle.year.out_of_range'),
    type: z.enum(VEHICLE_TYPES, { message: 'vehicle.type.required' }),
    fuelType: z.enum(FUEL_TYPES, { message: 'vehicle.fuel_type.required' }),
    secondaryFuelType: z.enum(FUEL_TYPES).nullable().optional(),
    licensePlate: z.string().max(20).nullable().optional(),
    vin: z.string().max(17).nullable().optional(),
    initialKm: z.number({ message: 'vehicle.initial_km.required' }).int().nonnegative().max(9_999_999, 'common.km_too_large'),
    purchasePrice: decimalString(2, { maxIntegerDigits: 8, allowZero: true }).nullable().optional(),
    notes: z.string().max(VEHICLE_NOTES_MAX_LENGTH).nullable().optional(),
  })
  .refine((data) => !data.secondaryFuelType || data.secondaryFuelType !== data.fuelType, {
    message: 'vehicle.duplicate_fuel_type',
    path: ['secondaryFuelType'],
  });

export type VehicleFormData = z.infer<typeof vehicleSchema>;
