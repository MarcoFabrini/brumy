import { Bell, Car, Home, Settings, UserRound, type LucideIcon } from 'lucide-react';

/**
 * Navigation tree — single source of truth per Sidebar e tab bar (BottomNav).
 * La Sidebar desktop mostra le voci `desktop` con le stesse etichette della tab bar (Home, Veicoli, Scadenze; il
 * Profilo sta in fondo con l'avatar): rifornimenti, manutenzioni e spese si gestiscono dentro il veicolo, con il suo
 * registro e il suo "Aggiungi". La tab bar mobile, come in un'app iOS, mostra le voci `mobile` con la loro etichetta breve
 * (`tabLabelKey`): Home · Veicoli · [+] · Scadenze · Profilo, col pulsante "+" iniettato in mezzo.
 */
export interface NavEntry {
  to: string;
  labelKey: string;
  Icon: LucideIcon;
  /** Mostrato nella tab bar mobile? */
  mobile?: boolean;
  /** Etichetta sotto l'icona nella tab bar (più corta della voce della sidebar). */
  tabLabelKey?: string;
  /** Icona nella tab bar, se diversa da quella della sidebar. */
  TabIcon?: LucideIcon;
  /** Mostrato nella sidebar desktop? (default sì) */
  desktop?: boolean;
}

export const NAVIGATION: NavEntry[] = [
  { to: '/', labelKey: 'nav.dashboard', Icon: Home, mobile: true, tabLabelKey: 'nav.tabs.home' },
  { to: '/vehicles', labelKey: 'nav.vehicles', Icon: Car, mobile: true, tabLabelKey: 'nav.tabs.vehicles' },
  { to: '/reminders', labelKey: 'nav.reminders', Icon: Bell, mobile: true, tabLabelKey: 'nav.tabs.deadlines' },
  {
    to: '/settings',
    // Profilo e impostazioni stanno nella stessa pagina: "Profilo" sia nella tab bar sia nella sidebar.
    labelKey: 'nav.tabs.profile',
    Icon: Settings,
    mobile: true,
    tabLabelKey: 'nav.tabs.profile',
    TabIcon: UserRound,
  },
];

/** Quante voci della tab bar stanno a sinistra del "+" (le altre a destra). */
export const TAB_BAR_LEADING = 2;
