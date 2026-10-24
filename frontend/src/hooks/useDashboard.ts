import { useQueries } from '@tanstack/react-query';
import { getVehicleStats } from '@/api/endpoints/vehicles';
import { vehicleStatsKey, useVehicles } from '@/hooks/useVehicles';
import type { Vehicle } from '@/api/types/vehicle';
import type { VehicleStats } from '@/api/types/vehicleStats';

export interface DashboardData {
  /** Solo i veicoli propri: la base della Home. */
  vehicles: Vehicle[];
  /** Veicoli visibili ma non propri (condivisi, o di altri membri per owner/admin): esclusi. */
  excludedCount: number;
  /** Statistiche per veicolo proprio; manca la voce finché non arriva (o se la richiesta fallisce). */
  statsById: ReadonlyMap<number, VehicleStats>;
  isLoading: boolean;
  /** Errore bloccante (lista veicoli): senza veicoli non c'è nulla da mostrare. */
  error: unknown;
}

/**
 * useDashboard — i veicoli PROPRI dell'utente con le loro statistiche (km attuali), caricate in parallelo.
 * Adatto a flotte personali (pochi veicoli). I veicoli condivisi in sola lettura e, per owner/admin dell'org,
 * quelli degli altri membri si vedono nella tab Veicoli ma qui non entrano.
 */
export function useDashboard(): DashboardData {
  const vehiclesQuery = useVehicles();
  const visible = vehiclesQuery.data ?? [];
  const vehicles = visible.filter((v) => v.ownership === 'owned');

  const statsQueries = useQueries({
    queries: vehicles.map((v) => ({
      queryKey: vehicleStatsKey(v.id),
      queryFn: () => getVehicleStats(v.id),
      staleTime: 60_000,
    })),
  });

  const statsById = new Map<number, VehicleStats>();
  vehicles.forEach((v, i) => {
    const stats = statsQueries[i]?.data;
    if (stats) statsById.set(v.id, stats);
  });

  return {
    vehicles,
    excludedCount: visible.length - vehicles.length,
    statsById,
    isLoading: vehiclesQuery.isLoading,
    error: vehiclesQuery.error,
  };
}
