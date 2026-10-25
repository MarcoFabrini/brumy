export const MAINTENANCE_TYPES = [
  'oil_change',
  'filters',
  'brakes',
  'tires',
  'inspection',
  'repair',
  'battery',
  'belt_change',
  'spark_plugs',
  'coolant',
  'clutch',
  'suspension',
  'body_work',
  'wash',
  'other',
] as const;
export type MaintenanceType = (typeof MAINTENANCE_TYPES)[number];

/** Sezione di un lavoro: ordinario, imprevisto, revisione (che va sempre con il tipo `inspection`). */
export const MAINTENANCE_CATEGORIES = ['scheduled', 'unscheduled', 'inspection'] as const;
export type MaintenanceCategory = (typeof MAINTENANCE_CATEGORIES)[number];

/**
 * Raw backend response — match Maintenance entity con groups serializzati.
 * Lista usa groups più snelli (no workshop), detail usa quelli completi.
 */
export interface MaintenanceResponse {
  id: number;
  vehicle: { id: number; name: string; brand: string; model: string };
  performedAt: string;        // ISO datetime "2026-05-15T00:00:00+00:00"
  km: number;
  type: MaintenanceType;
  category: MaintenanceCategory;
  description: string;
  cost?: string | null;
  notes?: string | null;
  workshop?: { id: number; name: string } | null;
  shop?: { id: number; name: string } | null;
  components?: { id: number; name: string; deleted?: boolean }[];
}

/** Normalized client-side shape — vehicle nested → vehicleId flat, date ISO sliced. */
export interface Maintenance {
  id: number;
  vehicleId: number;
  performedAt: string;        // YYYY-MM-DD (slice 0,10)
  km: number;
  type: MaintenanceType;
  category: MaintenanceCategory;
  description: string;
  cost: string | null;
  notes: string | null;
  workshop: string | null;
  shop: string | null;
  /** Componenti usati; `deleted`: eliminato dopo, si mostra ma non si apre. */
  components: { id: number; name: string; deleted?: boolean }[];
}

export interface CreateMaintenanceDto {
  vehicleId: number;
  performedAt: string;        // YYYY-MM-DD
  km: number;
  type: MaintenanceType;
  category: MaintenanceCategory;
  description: string;
  cost?: string | null;
  notes?: string | null;
  workshop?: string | null;
  shop?: string | null;
  /** Nomi dei componenti del veicolo usati nel lavoro: quelli nuovi si creano. */
  components?: string[];
  /** Solo in creazione della revisione: aggiorna o crea il promemoria con questa scadenza. */
  nextDueDate?: string | null;
}

export type UpdateMaintenanceDto = CreateMaintenanceDto;

/** Normalize backend response a client shape. */
export function adaptMaintenance(raw: MaintenanceResponse): Maintenance {
  return {
    id: raw.id,
    vehicleId: raw.vehicle.id,
    performedAt: raw.performedAt.slice(0, 10),
    km: raw.km,
    type: raw.type,
    category: raw.category,
    description: raw.description,
    cost: raw.cost ?? null,
    notes: raw.notes ?? null,
    workshop: raw.workshop?.name ?? null,
    shop: raw.shop?.name ?? null,
    components: raw.components ?? [],
  };
}
