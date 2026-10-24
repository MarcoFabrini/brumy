import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { NavItem } from './NavItem';
import { NAVIGATION } from './navigation';
import { OrganizationSwitcher, UserAvatar } from '@/components/features';
import { useAuthStore } from '@/stores/useAuthStore';
import { cn } from '@/lib/utils';

/**
 * Sidebar — nav verticale desktop (md+). Nascosta su mobile. Solo le voci `desktop`: i record
 * dei veicoli (manutenzioni, rifornimenti, spese, promemoria) si raggiungono dal veicolo.
 * Impostazioni separata in fondo, sotto le altre voci, con l'avatar utente
 * al posto dell'icona (coerente con la Topbar mobile).
 */
export function Sidebar() {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const user = useAuthStore((s) => s.user);

  const mainEntries = NAVIGATION.filter((entry) => entry.desktop !== false && entry.to !== '/settings');
  const settingsActive = pathname === '/settings' || pathname.startsWith('/settings/');

  return (
    // Barra laterale come in iPadOS/macOS: superficie chiara, voce attiva su un fondo grigio arrotondato.
    <aside className="hidden md:flex md:w-60 md:shrink-0 md:flex-col md:overflow-y-auto md:overscroll-contain md:border-r md:border-black/10 md:bg-card md:px-3 md:py-5 dark:md:border-white/10">
      {/* Nome org (testo, non heading: precederebbe l'h1 della pagina); menu di cambio se ne ha più d'una. */}
      <OrganizationSwitcher as="p" className="mb-5 w-full px-2" />
      <nav aria-label={t('nav.primary')} className="flex flex-1 flex-col gap-1">
        {mainEntries.map((entry) => (
          <NavItem key={entry.to} {...entry} orientation="vertical" />
        ))}
      </nav>
      {user && (
        <Link
          to="/settings"
          aria-current={settingsActive ? 'page' : undefined}
          className={cn(
            'mt-2 flex min-h-touch items-center gap-3 rounded-[10px] px-2 py-1.5 text-[0.9375rem] transition-colors',
            settingsActive ? 'bg-secondary font-semibold text-foreground' : 'text-foreground hover:bg-secondary/60',
          )}
        >
          <UserAvatar user={user} size="sm" />
          <span>{t('nav.tabs.profile')}</span>
        </Link>
      )}
    </aside>
  );
}
