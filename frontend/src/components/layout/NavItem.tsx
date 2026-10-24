import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * NavItem molecule — voce nav riusabile in Sidebar e BottomNav.
 *
 * Stato active (confronto pathname con `to`) reso ben visibile:
 * - bottom nav (horizontal): pill colorata dietro l'icona + testo primary in grassetto;
 * - sidebar (vertical): riga piena primary in grassetto.
 *
 * @example
 * <NavItem to="/vehicles" labelKey="nav.vehicles" Icon={Car} orientation="vertical" />
 * <NavItem to="/" labelKey="nav.dashboard" Icon={Home} orientation="horizontal" />
 */
export interface NavItemProps {
  to: string;
  labelKey: string;
  Icon: LucideIcon;
  /** Etichetta breve (la stessa della tab bar): se c'è, la sidebar usa questa. */
  tabLabelKey?: string;
  /** Layout: sidebar (icon+label inline) vs bottom nav (icon sopra label) */
  orientation: 'vertical' | 'horizontal';
}

export function NavItem({ to, labelKey, tabLabelKey, Icon, orientation }: NavItemProps) {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const active = pathname === to || (to !== '/' && pathname.startsWith(`${to}/`));

  const isHorizontal = orientation === 'horizontal';

  return (
    <Link
      to={to}
      aria-current={active ? 'page' : undefined}
      aria-label={isHorizontal ? t(labelKey) : undefined}
      title={isHorizontal ? t(labelKey) : undefined}
      className={cn(
        'min-h-touch transition-colors',
        isHorizontal
          ? 'flex flex-1 flex-col items-center justify-center gap-1 py-2 text-xs'
          : 'flex items-center gap-3 rounded-[10px] px-2 py-1.5 text-[0.9375rem]',
        // sidebar (vertical)
        !isHorizontal &&
          (active ? 'bg-secondary font-semibold text-foreground' : 'text-foreground hover:bg-secondary/60'),
        // bottom nav (horizontal)
        isHorizontal &&
          (active ? 'font-semibold text-primary' : 'font-medium text-muted-foreground hover:text-accent-foreground'),
      )}
    >
      {isHorizontal ? (
        <span
          className={cn(
            'flex items-center justify-center rounded-full px-5 py-1 transition-colors',
            active && 'bg-primary/10',
          )}
        >
          <Icon className="size-6" />
        </span>
      ) : (
        <>
          <Icon className="size-5 text-primary" aria-hidden="true" />
          <span>{t(tabLabelKey ?? labelKey)}</span>
        </>
      )}
    </Link>
  );
}
