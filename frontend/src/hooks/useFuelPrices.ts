import { useQuery } from '@tanstack/react-query';
import {
  getFuelPricesStatus,
  getFuelStation,
  getLiveFuelStations,
  listFollowedFuelPrices,
  listNearbyFuelStations,
  searchFuelStations,
} from '@/api/endpoints/fuelPrices';
import type { FuelStation } from '@/api/types/fuelPrice';
import type { FuelType } from '@/api/types/vehicle';
import type { GeoPosition } from '@/lib/geolocation';
import { withLivePrices } from '@/lib/fuelPrice';
import { useDebouncedValue } from './useDebouncedValue';

/** Distributori e prezzi del file giornaliero: in sessione un'ora di cache basta e avanza. */
const STALE_MS = 60 * 60 * 1000;
/** Prezzi del giorno stesso: il server non richiede lo stesso distributore al ministero prima di 15 minuti. */
const LIVE_STALE_MS = 15 * 60 * 1000;
const LIVE_DEBOUNCE_MS = 400;

export const fuelPriceKeys = {
  all: ['fuel-prices'] as const,
  status: () => [...fuelPriceKeys.all, 'status'] as const,
  nearby: (position: GeoPosition, fuel: FuelType | undefined) =>
    [...fuelPriceKeys.all, 'nearby', position.latitude, position.longitude, fuel ?? null] as const,
  search: (query: string, fuel: FuelType | undefined) => [...fuelPriceKeys.all, 'search', query, fuel ?? null] as const,
  station: (id: number, fuel: FuelType | undefined) => [...fuelPriceKeys.all, 'station', id, fuel ?? null] as const,
  live: (ids: number[], fuel: FuelType) => [...fuelPriceKeys.all, 'live', ids.join(','), fuel] as const,
  followed: () => [...fuelPriceKeys.all, 'followed'] as const,
};

/** Prezzi accesi sull'istanza? Chiederlo fa anche partire l'aggiornamento sul server se i dati sono vecchi. */
export function useFuelPricesStatus() {
  return useQuery({ queryKey: fuelPriceKeys.status(), queryFn: getFuelPricesStatus, staleTime: STALE_MS });
}

export function useNearbyFuelStations(position: GeoPosition | null, fuel: FuelType | undefined) {
  return useQuery({
    queryKey: position ? fuelPriceKeys.nearby(position, fuel) : [...fuelPriceKeys.all, 'nearby', null],
    queryFn: () => (position ? listNearbyFuelStations(position.latitude, position.longitude, fuel) : []),
    enabled: position !== null,
    staleTime: STALE_MS,
  });
}

/** Ricerca per nome, bandiera, indirizzo o comune: parte da 2 caratteri. */
export function useFuelStationSearch(query: string, fuel: FuelType | undefined) {
  const q = query.trim();
  return useQuery({
    queryKey: fuelPriceKeys.search(q, fuel),
    queryFn: () => searchFuelStations(q, fuel),
    enabled: q.length >= 2,
    staleTime: fuel ? LIVE_STALE_MS : STALE_MS,
    placeholderData: (previous) => previous,
  });
}

/**
 * I distributori trovati (vicini o ricerca) coi prezzi del giorno stesso: subito quelli del file, poi, appena arrivano,
 * quelli in tempo reale. Se il server non li ha (o rifiuta per troppe richieste) restano quelli del file.
 */
export function useLiveFuelPrices(stations: FuelStation[] | undefined, fuel: FuelType | undefined): FuelStation[] | undefined {
  // Mentre si scrive nella ricerca i risultati cambiano a ogni lettera: si chiede solo la lista su cui ci si ferma
  const ids = useDebouncedValue(stations?.map((s) => s.id).join(',') ?? '', LIVE_DEBOUNCE_MS)
    .split(',')
    .filter(Boolean)
    .map(Number);
  const live = useQuery({
    queryKey: fuel ? fuelPriceKeys.live(ids, fuel) : [...fuelPriceKeys.all, 'live', null],
    queryFn: () => (fuel ? getLiveFuelStations(ids, fuel) : []),
    enabled: fuel !== undefined && ids.length > 0,
    staleTime: LIVE_STALE_MS,
    retry: false,
  });
  return stations && live.data ? withLivePrices(stations, live.data) : stations;
}

/**
 * Il distributore ufficiale collegato a una voce dell'elenco. Col carburante, prima i prezzi del giorno stesso; se non
 * arrivano, quelli del file.
 */
async function fetchStation(id: number, fuel: FuelType | undefined): Promise<FuelStation> {
  if (fuel) {
    const [fresh] = await getLiveFuelStations([id], fuel).catch((): FuelStation[] => []);
    if (fresh) return fresh;
  }
  return getFuelStation(id, fuel);
}

export function useFuelStation(id: number | null, fuel: FuelType | undefined) {
  return useQuery({
    queryKey: id === null ? [...fuelPriceKeys.all, 'station', null] : fuelPriceKeys.station(id, fuel),
    queryFn: () => (id === null ? Promise.reject(new Error('no station')) : fetchStation(id, fuel)),
    enabled: id !== null,
    staleTime: STALE_MS,
    retry: false,
  });
}

/** Andamento dei prezzi dei distributori seguiti, per un carburante base. */
export function useFollowedFuelPrices(fuel: FuelType, options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: [...fuelPriceKeys.followed(), fuel],
    queryFn: () => listFollowedFuelPrices(fuel),
    enabled: options.enabled ?? true,
    staleTime: STALE_MS,
  });
}
