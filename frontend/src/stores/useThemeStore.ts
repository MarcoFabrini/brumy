import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export type Theme = 'light' | 'dark' | 'system';

interface ThemeState {
  theme: Theme;
  /** Testo più grande in tutta l'app: con lo zoom a pizzico spento è l'alternativa alla Dynamic Type di iOS. */
  largeText: boolean;
  setTheme: (theme: Theme) => void;
  setLargeText: (largeText: boolean) => void;
}

/**
 * Aspetto (tema e dimensione del testo) — persistito in localStorage (preferenza UI, non sensibile).
 * 'system' segue prefers-color-scheme. applyTheme()/applyTextSize() applicano le class su <html>.
 */
export const useThemeStore = create<ThemeState>()(
  persist(
    (set) => ({
      theme: 'system',
      largeText: false,
      setTheme: (theme) => set({ theme }),
      setLargeText: (largeText) => set({ largeText }),
    }),
    { name: 'brumy-theme' },
  ),
);

/** Colore delle barre di sistema (status bar, barra del browser): lo sfondo dell'app nel tema attivo. */
const SYSTEM_BAR_COLOR = { light: '#f2f2f7', dark: '#000000' } as const;

/** Risolve 'system' a 'light'|'dark', applica la class 'dark' su documentElement e allinea il theme-color. */
export function applyTheme(theme: Theme) {
  const resolved =
    theme === 'system'
      ? window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light'
      : theme;
  document.documentElement.classList.toggle('dark', resolved === 'dark');
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', SYSTEM_BAR_COLOR[resolved]);
}

export function applyTextSize(largeText: boolean) {
  document.documentElement.classList.toggle('text-large', largeText);
}
