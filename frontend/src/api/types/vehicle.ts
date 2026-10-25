/**
 * Vehicle types — manuali per ora. In futuro autogen via
 * `npm run api:types` (openapi-typescript da /api/doc.json).
 */

export const VEHICLE_TYPES = [
  'car',
  'motorcycle',
  'van',
  'camper',
  'boat',
  'truck',
  'bicycle',
  'scooter',
  'trailer',
  'tractor',
] as const;
export type VehicleType = (typeof VEHICLE_TYPES)[number];

export const FUEL_TYPES = [
  'gasoline',
  'diesel',
  'lpg',
  'cng',
  'electric',
  'hybrid',
  'hydrogen',
  'ethanol',
  'biodiesel',
] as const;
export type FuelType = (typeof FUEL_TYPES)[number];

export interface Vehicle {
  id: number;
  name: string;
  brand: string;
  model: string;
  year: number;
  licensePlate: string | null;
  vin: string | null;
  type: VehicleType;
  fuelType: FuelType;
  secondaryFuelType: FuelType | null;
  initialKm: number;
  /** Prezzo d'acquisto (decimale), solo informativo. */
  purchasePrice?: string | null;
  notes: string | null;
  /** Presente in lista e dettaglio: null per i veicoli attivi. */
  archivedAt?: string | null;
  photoPath?: string | null;
  /** Rapporto dell'utente corrente con il veicolo (lista e dettaglio). */
  ownership: VehicleOwnership;
  /** Permessi dell'utente corrente su questo veicolo (il backend applica comunque i voter). */
  permissions: VehiclePermissions;
}

/**
 * - `owned`: è suo (l'ha creato). Solo questi entrano in totali, grafici, scadenze e notifiche.
 * - `shared`: condiviso con lui in sola lettura: lo vede ma non modifica nulla.
 * - `organization`: lo vede (e lo gestisce) come owner/admin dell'org, ma non è suo.
 */
export type VehicleOwnership = 'owned' | 'shared' | 'organization';

export interface VehiclePermissions {
  canEdit: boolean;
  canDelete: boolean;
  canShare: boolean;
}

export interface CreateVehicleDto {
  name: string;
  brand: string;
  model: string;
  year: number;
  type: VehicleType;
  fuelType: FuelType;
  secondaryFuelType?: FuelType | null;
  licensePlate?: string | null;
  vin?: string | null;
  initialKm: number;
  purchasePrice?: string | null;
  notes?: string | null;
}

export type UpdateVehicleDto = CreateVehicleDto;
