import { useQuery } from '@tanstack/react-query';
import {
  getFuelPricesStatus,
  getFuelStation,
  listFollowedFuelPrices,
  listNearbyFuelStations,
  searchFuelStations,
} from '@/api/endpoints/fuelPrices';
import type { FuelType } from '@/api/types/vehicle';
import type { GeoPosition } from '@/lib/geolocation';

/** I prezzi si aggiornano una volta al giorno: in sessione un'ora di cache basta e avanza. */
const STALE_MS = 60 * 60 * 1000;

export const fuelPriceKeys = {
  all: ['fuel-prices'] as const,
  status: () => [...fuelPriceKeys.all, 'status'] as const,
  nearby: (position: GeoPosition, fuel: FuelType | undefined) =>
    [...fuelPriceKeys.all, 'nearby', position.latitude, position.longitude, fuel ?? null] as const,
  search: (query: string, fuel: FuelType | undefined) => [...fuelPriceKeys.all, 'search', query, fuel ?? null] as const,
  station: (id: number, fuel: FuelType | undefined) => [...fuelPriceKeys.all, 'station', id, fuel ?? null] as const,
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
    staleTime: STALE_MS,
    placeholderData: (previous) => previous,
  });
}

/** Il distributore ufficiale collegato a una voce dell'elenco, con i prezzi del carburante. */
export function useFuelStation(id: number | null, fuel: FuelType | undefined) {
  return useQuery({
    queryKey: id === null ? [...fuelPriceKeys.all, 'station', null] : fuelPriceKeys.station(id, fuel),
    queryFn: () => (id === null ? Promise.reject(new Error('no station')) : getFuelStation(id, fuel)),
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
