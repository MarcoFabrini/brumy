import type { ReactElement } from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import i18n from '@/i18n';
import type { ConsumptionRow, MonthlySpendingRow } from '@/lib/dashboardCharts';
import type { FollowedFuelStation } from '@/api/types/fuelPrice';
import { ConsumptionTrendChart } from './ConsumptionTrendChart';
import { FuelPriceTrendChart } from './FuelPriceTrendChart';
import { MonthlySpendingChart } from './MonthlySpendingChart';

// In jsdom ResponsiveContainer misura 0×0 e non disegna nulla: dimensione fissa.
vi.mock('recharts', async (importOriginal) => {
  const { cloneElement } = await import('react');
  return {
    ...(await importOriginal<typeof import('recharts')>()),
    ResponsiveContainer: ({ children }: { children: ReactElement<{ width?: number; height?: number }> }) =>
      cloneElement(children, { width: 400, height: 240 }),
  };
});

/** La voce di legenda di recharts con quel testo (la legenda è solo visiva, dentro aria-hidden). */
function legendItem(container: HTMLElement, text: string): HTMLElement {
  const item = [...container.querySelectorAll<HTMLElement>('.recharts-legend-item')].find((li) => li.textContent === text);
  if (!item) throw new Error(`voce di legenda "${text}" non trovata`);
  return item;
}

const drawn = (container: HTMLElement, selector: string) => container.querySelectorAll(selector).length;

const spending: MonthlySpendingRow[] = [
  { month: '2026-09', label: 'set 26', refuelings: 84.58, scheduled: 120, unscheduled: 0, inspection: 0, taxes: 0, insurance: 0, expenses: 12, total: 216.58 },
];
const consumption: ConsumptionRow[] = [
  { month: '2026-09', label: 'set 26', values: { gasoline: 14.25, lpg: 10.5 } },
  { month: '2026-10', label: 'ott 26', values: { gasoline: 15.1, lpg: 11 } },
];
const followed: FollowedFuelStation[] = [
  {
    valueId: 5,
    name: 'Q8 sotto casa',
    fuelStationId: 59183,
    self: [{ day: '2026-10-24', price: '1.729' }, { day: '2026-10-25', price: '1.719' }],
    served: [{ day: '2026-10-24', price: '1.899' }, { day: '2026-10-25', price: '1.889' }],
  },
  { valueId: 8, name: 'Eni lavoro', fuelStationId: 1234, self: [], served: [{ day: '2026-10-24', price: '0.749' }, { day: '2026-10-25', price: '0.759' }] },
];

describe('legenda di solo testo: un tocco nasconde la serie, il successivo la rimostra', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('spese mensili', async () => {
    const user = userEvent.setup();
    const { container } = render(<MonthlySpendingChart rows={spending} />);
    const bars = drawn(container, '.recharts-bar');

    await user.click(legendItem(container, 'Rifornimenti'));
    expect(legendItem(container, 'Rifornimenti')).toHaveClass('inactive');
    expect(drawn(container, '.recharts-bar')).toBe(bars - 1);
    // La tabella per gli screen reader resta completa
    expect(screen.getByRole('columnheader', { name: 'Rifornimenti' })).toBeInTheDocument();

    await user.click(legendItem(container, 'Rifornimenti'));
    expect(legendItem(container, 'Rifornimenti')).not.toHaveClass('inactive');
    expect(drawn(container, '.recharts-bar')).toBe(bars);
  });

  it('consumo', async () => {
    const user = userEvent.setup();
    const { container } = render(<ConsumptionTrendChart rows={consumption} fuelTypes={['gasoline', 'lpg']} />);
    expect(drawn(container, '.recharts-line')).toBe(2);

    await user.click(legendItem(container, 'GPL'));
    expect(legendItem(container, 'GPL')).toHaveClass('inactive');
    expect(drawn(container, '.recharts-line')).toBe(1);

    await user.click(legendItem(container, 'GPL'));
    expect(drawn(container, '.recharts-line')).toBe(2);
  });

  it('prezzi dei distributori: per distributore e per self/servito', async () => {
    const user = userEvent.setup();
    const { container } = render(<FuelPriceTrendChart title="Prezzi" fuel="lpg" stations={followed} action={null} />);
    // Una voce per distributore e una per tratto, non una per linea
    expect([...container.querySelectorAll('.recharts-legend-item')].map((li) => li.textContent)).toEqual([
      'Q8 sotto casa',
      'Eni lavoro',
      'Self',
      'Servito',
    ]);
    expect(drawn(container, '.recharts-line')).toBe(3);

    await user.click(legendItem(container, 'Q8 sotto casa'));
    expect(legendItem(container, 'Q8 sotto casa')).toHaveClass('inactive');
    expect(drawn(container, '.recharts-line')).toBe(1);

    await user.click(legendItem(container, 'Q8 sotto casa'));
    expect(drawn(container, '.recharts-line')).toBe(3);

    await user.click(legendItem(container, 'Servito'));
    expect(drawn(container, '.recharts-line')).toBe(1);
  });
});
