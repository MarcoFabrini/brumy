import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import type { NavEntry } from './navigation';

/** Una voce della tab bar iOS: icona 26px sopra, etichetta da 10px sotto; attiva nel colore dell'app. */
export function TabBarItem({ entry }: { entry: NavEntry }) {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const { to, labelKey, tabLabelKey, Icon, TabIcon } = entry;
  const active = pathname === to || (to !== '/' && pathname.startsWith(`${to}/`));
  const TabGlyph = TabIcon ?? Icon;

  return (
    <Link
      to={to}
      aria-current={active ? 'page' : undefined}
      className={cn(
        'flex min-h-touch flex-1 flex-col items-center justify-center gap-0.5 pb-0.5 pt-1.5 transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
        active ? 'text-primary' : 'text-muted-foreground active:opacity-60',
      )}
    >
      <TabGlyph className="size-[26px]" strokeWidth={active ? 2.25 : 1.75} aria-hidden="true" />
      <span className="text-[0.625rem] font-medium leading-tight">{t(tabLabelKey ?? labelKey)}</span>
    </Link>
  );
}
