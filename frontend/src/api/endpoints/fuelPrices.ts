import { z } from 'zod';
import { authFetch } from '@/api/client';
import {
  followedFuelStationSchema,
  fuelPricesStatusSchema,
  fuelStationSchema,
  type FollowedFuelStation,
  type FuelPricesStatus,
  type FuelStation,
} from '@/api/types/fuelPrice';
import type { FuelType } from '@/api/types/vehicle';

/** Il carburante, se c'è, come parametro: senza, i distributori arrivano senza prezzi. */
function fuelParam(fuel: FuelType | undefined): string {
  return fuel ? `&fuel=${encodeURIComponent(fuel)}` : '';
}

export async function getFuelPricesStatus(): Promise<FuelPricesStatus> {
  return fuelPricesStatusSchema.parse(await authFetch<unknown>('/api/fuel-prices/status'));
}

/** La posizione va al server solo per questa ricerca: non si salva. */
export async function listNearbyFuelStations(latitude: number, longitude: number, fuel?: FuelType): Promise<FuelStation[]> {
  const raw = await authFetch<unknown>(`/api/fuel-prices/nearby?lat=${latitude}&lng=${longitude}${fuelParam(fuel)}`);
  return z.array(fuelStationSchema).parse(raw);
}

export async function searchFuelStations(query: string, fuel?: FuelType): Promise<FuelStation[]> {
  const raw = await authFetch<unknown>(`/api/fuel-prices/search?q=${encodeURIComponent(query)}${fuelParam(fuel)}`);
  return z.array(fuelStationSchema).parse(raw);
}

export async function getFuelStation(id: number, fuel?: FuelType): Promise<FuelStation> {
  const query = fuel ? `?fuel=${encodeURIComponent(fuel)}` : '';
  return fuelStationSchema.parse(await authFetch<unknown>(`/api/fuel-prices/stations/${id}${query}`));
}

/** Andamento del prezzo self di un carburante base negli ultimi 30 giorni, per ogni distributore seguito. */
export async function listFollowedFuelPrices(fuel: FuelType): Promise<FollowedFuelStation[]> {
  const raw = await authFetch<unknown>(`/api/fuel-prices/followed?fuel=${encodeURIComponent(fuel)}`);
  return z.array(followedFuelStationSchema).parse(raw);
}
