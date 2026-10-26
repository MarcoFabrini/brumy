import type { FuelType } from './vehicle';
export type { FuelType } from './vehicle';

export interface RefuelingResponse {
  id: number;
  vehicle: { id: number; name: string; brand: string; model: string };
  refueledAt: string;          // ISO datetime
  km: number;
  liters: string;              // decimal string
  pricePerLiter: string;       // decimal string
  totalCost: string | null;    // decimal string (server-computed)
  fuelType: FuelType;
  fullTank: boolean;
  station?: { id: number; name: string } | null;
  description?: string | null;
  notes?: string | null;
}

export interface Refueling {
  id: number;
  vehicleId: number;
  refueledAt: string;          // YYYY-MM-DD
  km: number;
  liters: string;
  pricePerLiter: string;
  totalCost: string | null;
  fuelType: FuelType;
  fullTank: boolean;
  station: string | null;
  description: string | null;
  notes: string | null;
}

export interface CreateRefuelingDto {
  vehicleId: number;
  refueledAt: string;
  km: number;
  liters: string;
  pricePerLiter: string;
  fuelType: FuelType;
  fullTank: boolean;
  station?: string | null;
  /** Distributore ufficiale scelto dai vicini o dalla ricerca: la voce del distributore gli si collega. */
  fuelStationId?: number | null;
  description?: string | null;
  notes?: string | null;
}

export type UpdateRefuelingDto = CreateRefuelingDto;

export function adaptRefueling(raw: RefuelingResponse): Refueling {
  return {
    id: raw.id,
    vehicleId: raw.vehicle.id,
    refueledAt: raw.refueledAt.slice(0, 10),
    km: raw.km,
    liters: raw.liters,
    pricePerLiter: raw.pricePerLiter,
    totalCost: raw.totalCost,
    fuelType: raw.fuelType,
    fullTank: raw.fullTank,
    station: raw.station?.name ?? null,
    description: raw.description ?? null,
    notes: raw.notes ?? null,
  };
}
