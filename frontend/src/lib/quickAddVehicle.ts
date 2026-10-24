/**
 * Veicolo già scelto quando si apre il "+": quello che si sta guardando (dettaglio o lista filtrata), altrimenti
 * l'ultimo usato, altrimenti il primo. Solo fra i veicoli proposti (propri e attivi); null se non ce ne sono.
 */
export function pickQuickAddVehicle(
  vehicleIds: readonly number[],
  contextVehicleId: number | null,
  lastVehicleId: number | null,
): number | null {
  if (contextVehicleId !== null && vehicleIds.includes(contextVehicleId)) return contextVehicleId;
  if (lastVehicleId !== null && vehicleIds.includes(lastVehicleId)) return lastVehicleId;
  return vehicleIds[0] ?? null;
}

/** Veicolo della schermata corrente: `/vehicles/:id` (e sotto-pagine) o `?vehicleId=N` delle liste e dei form. */
export function vehicleIdFromLocation(pathname: string, search: string): number | null {
  const fromPath = /^\/vehicles\/(\d+)(?:\/|$)/.exec(pathname)?.[1];
  const raw = fromPath ?? new URLSearchParams(search).get('vehicleId');
  const id = Number(raw);
  return Number.isInteger(id) && id > 0 ? id : null;
}
