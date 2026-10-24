import { z } from 'zod';

/** Importo come stringa decimale a 2 cifre ("84.58"), il formato del backend. */
const money = z.string().regex(/^-?\d+\.\d{2}$/);
const yearMonth = z.string().regex(/^\d{4}-(0[1-9]|1[0-2])$/);

/**
 * Risposta di `GET /api/dashboard/charts` e di `GET /api/vehicles/{id}/charts` (stesso contratto),
 * validata a runtime al confine: un payload malformato
 * diventa un errore della query, non un crash di rendering dei grafici.
 */
export const dashboardChartsSchema = z.object({
  from: yearMonth,
  to: yearMonth,
  /** Carburanti dei veicoli di proprietà: le chiavi di `consumption` di ogni mese. */
  fuelTypes: z.array(z.string()),
  months: z.array(
    z.object({
      month: yearMonth,
      spending: z.object({ refuelings: money, maintenances: money, expenses: money, total: money }),
      kmDriven: z.number().int(),
      /** Carburante → km/l, null se nel mese non si chiude nessun intervallo tra pieni. */
      consumption: z.record(z.string(), z.number().nullable()),
    }),
  ),
  /** `fuel`, `maintenance` o una categoria di spesa; importo decrescente, zeri omessi. */
  spendingByCategory: z.array(z.object({ category: z.string(), amount: money })),
  totals: z.object({ spending: money, kmDriven: z.number().int() }),
});

export type DashboardCharts = z.infer<typeof dashboardChartsSchema>;
