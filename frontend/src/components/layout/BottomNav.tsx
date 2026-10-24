import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus } from 'lucide-react';
import { TabBarItem } from './TabBarItem';
import { NAVIGATION, TAB_BAR_LEADING } from './navigation';
import { QuickAddSheet } from '@/components/features';

/**
 * BottomNav — tab bar mobile in stile iOS, nascosta su desktop (md+).
 * Home · Veicoli · [+] · Scadenze · Profilo: icona sopra, etichetta piccola sotto, voce attiva nel colore
 * dell'app. Sfondo traslucido sfocato e separatore sottile come la UITabBar; il "+" è il pulsante tondo pieno
 * al centro (hub delle aggiunte). `pb-safe-nav` accomoda l'home indicator iPhone in PWA standalone.
 */
export function BottomNav() {
  const { t } = useTranslation();
  const [addOpen, setAddOpen] = useState(false);
  const tabs = NAVIGATION.filter((entry) => entry.mobile);

  return (
    <>
      <nav
        aria-label={t('nav.primary_mobile')}
        className="fixed bottom-0 left-0 right-0 z-20 flex items-stretch border-t border-black/10 bg-card/80 pb-safe-nav backdrop-blur-xl backdrop-saturate-150 dark:border-white/10 md:hidden"
      >
        {tabs.slice(0, TAB_BAR_LEADING).map((entry) => (
          <TabBarItem key={entry.to} entry={entry} />
        ))}

        <div className="flex flex-1 items-center justify-center">
          <button
            type="button"
            onClick={() => setAddOpen(true)}
            aria-label={t('quick_add.open')}
            className="flex size-12 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-md transition-transform active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
          >
            <Plus className="size-7" strokeWidth={2.5} />
          </button>
        </div>

        {tabs.slice(TAB_BAR_LEADING).map((entry) => (
          <TabBarItem key={entry.to} entry={entry} />
        ))}
      </nav>

      <QuickAddSheet open={addOpen} onOpenChange={setAddOpen} />
    </>
  );
}
