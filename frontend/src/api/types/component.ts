import { z } from 'zod';

const valueSchema = z.object({ id: z.number().int(), name: z.string() }).nullable();

/** Componente nella lista del veicolo. */
export const componentSchema = z.object({
  id: z.number().int(),
  vehicle: z.object({ id: z.number().int() }),
  name: z.string(),
  code: z.string().nullable(),
  shop: valueSchema,
  purchasedAt: z.string().nullable(),
  price: z.string().nullable(),
});

/** Scheda del componente: tutti i campi e i lavori in cui è stato usato (dal più recente). */
export const componentDetailSchema = componentSchema.extend({
  serialNumber: z.string().nullable(),
  notes: z.string().nullable(),
  maintenances: z.array(
    z.object({
      id: z.number().int(),
      performedAt: z.string(),
      category: z.enum(['scheduled', 'unscheduled', 'inspection']),
      description: z.string(),
    }),
  ),
});

export type ComponentResponse = z.infer<typeof componentSchema>;
export type ComponentDetailResponse = z.infer<typeof componentDetailSchema>;

export interface Component {
  id: number;
  vehicleId: number;
  name: string;
  code: string | null;
  /** Nome del negozio (voce privata dell'utente). */
  shop: string | null;
  purchasedAt: string | null; // YYYY-MM-DD
  price: string | null;
}

export interface ComponentDetail extends Component {
  serialNumber: string | null;
  notes: string | null;
  maintenances: ComponentDetailResponse['maintenances'];
}

export interface ComponentDto {
  vehicleId: number;
  name: string;
  code?: string | null;
  serialNumber?: string | null;
  shop?: string | null;
  purchasedAt?: string | null;
  price?: string | null;
  notes?: string | null;
}

export function adaptComponent(raw: ComponentResponse): Component {
  return {
    id: raw.id,
    vehicleId: raw.vehicle.id,
    name: raw.name,
    code: raw.code,
    shop: raw.shop?.name ?? null,
    purchasedAt: raw.purchasedAt ? raw.purchasedAt.slice(0, 10) : null,
    price: raw.price,
  };
}

export function adaptComponentDetail(raw: ComponentDetailResponse): ComponentDetail {
  return { ...adaptComponent(raw), serialNumber: raw.serialNumber, notes: raw.notes, maintenances: raw.maintenances };
}
