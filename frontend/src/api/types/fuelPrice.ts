import { z } from 'zod';

/** Un prezzo comunicato dal distributore (€/l, tre decimali) e il giorno della comunicazione. */
export const fuelPricePointSchema = z.object({ price: z.string(), communicatedAt: z.string() });
export type FuelPricePoint = z.infer<typeof fuelPricePointSchema>;

/**
 * Distributore dell'open data MIMIT con i prezzi del carburante chiesto (self e servito, null se mancano).
 * `label` è il nome leggibile, quello con cui entra nell'elenco dei distributori; `distanceKm` solo nei vicini.
 */
export const fuelStationSchema = z.object({
  id: z.number().int(),
  label: z.string(),
  brand: z.string(),
  address: z.string(),
  city: z.string(),
  province: z.string(),
  distanceKm: z.number().nullable(),
  prices: z.object({ self: fuelPricePointSchema.nullable(), served: fuelPricePointSchema.nullable() }),
});
export type FuelStation = z.infer<typeof fuelStationSchema>;

/** Prezzi accesi sull'istanza (solo Italia) e data dei dati, null prima del primo import. */
export const fuelPricesStatusSchema = z.object({ enabled: z.boolean(), extractedOn: z.string().nullable() });
export type FuelPricesStatus = z.infer<typeof fuelPricesStatusSchema>;

/** Un distributore seguito dall'utente con il prezzo self del carburante, giorno per giorno (dal più vecchio). */
export const followedFuelStationSchema = z.object({
  valueId: z.number().int(),
  name: z.string(),
  fuelStationId: z.number().int(),
  points: z.array(z.object({ day: z.string(), price: z.string() })),
});
export type FollowedFuelStation = z.infer<typeof followedFuelStationSchema>;

/** Self o servito: quale dei due prezzi proporre. */
export type FuelServiceMode = 'self' | 'served';
