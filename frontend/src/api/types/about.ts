import { z } from 'zod';

/** `GET /api/about`: versione installata; `update` solo per l'amministratore dell'istanza, se ne è uscita una più nuova. */
export const aboutSchema = z.object({
  version: z.string(),
  update: z.object({ version: z.string(), url: z.string() }).nullable(),
});
export type About = z.infer<typeof aboutSchema>;
