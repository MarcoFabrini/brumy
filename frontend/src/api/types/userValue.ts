import { z } from 'zod';

/** Tipi di voce privata dell'utente: distributori, officine, negozi, categorie di spesa, tipi di tassa, compagnie. */
export const USER_VALUE_KINDS = ['station', 'workshop', 'shop', 'expense_category', 'tax_type', 'insurer'] as const;
export type UserValueKind = (typeof USER_VALUE_KINDS)[number];

/**
 * `starterKey` (solo nei suggerimenti): la voce di partenza da cui viene, es. `road_tax` per il bollo.
 * `fuelStationId` (solo distributori): il distributore ufficiale collegato, da cui proporre il prezzo.
 */
export const userValueSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  starterKey: z.string().nullable().optional(),
  fuelStationId: z.number().int().nullable().optional(),
});
export type UserValue = z.infer<typeof userValueSchema>;

/**
 * Voce nella pagina di gestione: con il numero di record che la usano. `followsPrice` (distributori collegati): il suo
 * prezzo si salva ogni giorno per il grafico dell'andamento.
 */
export const managedUserValueSchema = userValueSchema.extend({ usageCount: z.number().int(), followsPrice: z.boolean() });
export type ManagedUserValue = z.infer<typeof managedUserValueSchema>;
