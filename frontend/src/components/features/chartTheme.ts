import type { CSSProperties } from 'react';
import type { LegendProps } from 'recharts';

/**
 * Stile condiviso dei grafici recharts: solo variabili CSS del tema (`--chart-*` e quelle
 * shadcn in index.css), così il passaggio chiaro/scuro si applica senza ricaricare.
 */

/** `--chart-1` … `--chart-6`: una tinta per serie, definita sia in `:root` sia in `.dark`. */
export const CHART_COLORS = [1, 2, 3, 4, 5, 6].map((n) => `hsl(var(--chart-${n}))`);

/** Colore della n-esima serie (ciclico sulla palette). */
export function chartColor(index: number): string {
  return CHART_COLORS[index % CHART_COLORS.length] ?? 'hsl(var(--chart-1))';
}

export const AXIS_TICK = { fill: 'hsl(var(--muted-foreground))', fontSize: 12 };
export const AXIS_STROKE = 'hsl(var(--border))';
export const GRID_STROKE = 'hsl(var(--border))';

export const TOOLTIP_CONTENT_STYLE: CSSProperties = {
  backgroundColor: 'hsl(var(--popover))',
  border: '1px solid hsl(var(--border))',
  borderRadius: 'var(--radius)',
  color: 'hsl(var(--popover-foreground))',
  fontSize: 12,
};
export const TOOLTIP_LABEL_STYLE: CSSProperties = { color: 'hsl(var(--popover-foreground))', fontWeight: 600 };
export const TOOLTIP_CURSOR = { fill: 'hsl(var(--muted))', opacity: 0.6 };
export const LEGEND_STYLE: CSSProperties = { fontSize: 12, color: 'hsl(var(--muted-foreground))' };

/**
 * Legenda di solo testo che nasconde e rimostra la serie toccata (vedi `useHiddenSeries`): la chiave è il nome della
 * serie, o l'`id` di una voce di legenda scritta a mano. Una serie con `hide` appare spenta.
 */
export function toggleLegendProps(toggle: (key: string) => void): Pick<LegendProps, 'wrapperStyle' | 'iconSize' | 'inactiveColor' | 'onClick'> {
  return {
    wrapperStyle: { ...LEGEND_STYLE, cursor: 'pointer' },
    iconSize: 10,
    inactiveColor: 'hsl(var(--muted-foreground) / 0.4)',
    onClick: (entry) => toggle(String(entry.id ?? entry.value)),
  };
}
