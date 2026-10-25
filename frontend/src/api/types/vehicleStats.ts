export interface VehicleStats {
  consumption: Record<string, number | null>; // fuel type → km/l
  totals: {
    cost: string; // decimal string
    refuelings: number;
    maintenances: number;
    expenses: number;
    /** Record per sezione (scheduled, unscheduled, inspection, tax, insurance, expense, component). */
    sections?: Partial<Record<string, number>>;
  };
  currentKm: number;
  kmDriven: number;
  costPerKm: number | null;
}
