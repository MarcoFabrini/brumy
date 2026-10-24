import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface QuickAddState {
  /** Ultimo veicolo su cui si è registrato qualcosa col "+": la prossima volta è già scelto. */
  lastVehicleId: number | null;
  setLastVehicleId: (id: number) => void;
}

/** Preferenza UI del "+" (non sensibile), persistita in localStorage. */
export const useQuickAddStore = create<QuickAddState>()(
  persist(
    (set) => ({
      lastVehicleId: null,
      setLastVehicleId: (lastVehicleId) => set({ lastVehicleId }),
    }),
    { name: 'brumy-quick-add' },
  ),
);
