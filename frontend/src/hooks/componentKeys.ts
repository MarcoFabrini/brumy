/** Chiavi delle query dei componenti, in un modulo foglia: le usano anche gli hook dei lavori senza un ciclo di import. */
export const componentKeys = {
  all: ['components'] as const,
  byVehicle: (vehicleId: number) => [...componentKeys.all, 'vehicle', vehicleId] as const,
  detail: (id: number) => [...componentKeys.all, 'detail', id] as const,
};
