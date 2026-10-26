import type { FollowedFuelStation, FuelServiceMode } from '@/api/types/fuelPrice';
import type { FuelType, Vehicle } from '@/api/types/vehicle';

/** I carburanti base di cui il ministero pubblica i prezzi: le scelte del grafico dell'andamento. */
export const PRICE_FUELS = ['gasoline', 'diesel', 'lpg', 'cng'] as const satisfies readonly FuelType[];
export type PriceFuel = (typeof PRICE_FUELS)[number];

/** Il carburante base di cui cercare il prezzo, come sul server: un'ibrida fa benzina, l'elettrico non ha prezzo. */
export function priceFuelOf(fuel: FuelType): PriceFuel | null {
  if (fuel === 'hybrid') return 'gasoline';
  return (PRICE_FUELS as readonly FuelType[]).includes(fuel) ? (fuel as PriceFuel) : null;
}

/** Carburante proposto: il più frequente tra i veicoli propri (a parità il primo), altrimenti benzina. */
export function defaultTrendFuel(vehicles: Pick<Vehicle, 'fuelType' | 'ownership'>[]): PriceFuel {
  const counts = new Map<PriceFuel, number>();
  for (const v of vehicles) {
    const fuel = v.ownership === 'owned' ? priceFuelOf(v.fuelType) : null;
    if (fuel) counts.set(fuel, (counts.get(fuel) ?? 0) + 1);
  }
  let best: PriceFuel = 'gasoline';
  let bestCount = 0;
  for (const [fuel, count] of counts) {
    if (count > bestCount) [best, bestCount] = [fuel, count];
  }
  return best;
}

export const SERVICE_MODES = ['self', 'served'] as const satisfies readonly FuelServiceMode[];

/** Una linea del grafico: il prezzo self o servito di un distributore. */
export function seriesKey(valueId: number, mode: FuelServiceMode): string {
  return `${valueId}:${mode}`;
}

export interface TrendRow {
  day: string;
  label: string;
  /** Prezzo €/l per {@link seriesKey}; manca se quel giorno la serie non ha prezzo. */
  values: Record<string, number>;
}

/** Una riga per giorno con un prezzo di almeno una serie, dal più vecchio. */
export function toTrendRows(stations: FollowedFuelStation[], formatDay: (iso: string) => string): TrendRow[] {
  const byDay = new Map<string, Record<string, number>>();
  for (const station of stations) {
    for (const mode of SERVICE_MODES) {
      for (const point of station[mode]) {
        const values = byDay.get(point.day) ?? {};
        values[seriesKey(station.valueId, mode)] = Number.parseFloat(point.price);
        byDay.set(point.day, values);
      }
    }
  }
  return [...byDay.keys()].sort().map((day) => ({ day, label: formatDay(day), values: byDay.get(day) ?? {} }));
}
