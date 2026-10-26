import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { PriceFuel } from '@/lib/fuelPriceTrend';

interface FuelTrendState {
  /** Carburante scelto nel grafico dell'andamento dei prezzi; null = quello dei propri veicoli. */
  fuel: PriceFuel | null;
  setFuel: (fuel: PriceFuel) => void;
}

/** Preferenza UI del grafico dei prezzi (non sensibile), persistita in localStorage. */
export const useFuelTrendStore = create<FuelTrendState>()(
  persist(
    (set) => ({
      fuel: null,
      setFuel: (fuel) => set({ fuel }),
    }),
    { name: 'brumy-fuel-trend' },
  ),
);
