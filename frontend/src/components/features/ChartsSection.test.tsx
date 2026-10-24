import type { ReactElement } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import i18n from '@/i18n';
import type { DashboardCharts } from '@/api/types/dashboardCharts';
import { ChartsSection } from './ChartsSection';

// In jsdom ResponsiveContainer misura 0×0 e non disegna nulla: dimensione fissa.
vi.mock('recharts', async (importOriginal) => {
  const { cloneElement } = await import('react');
  return {
    ...(await importOriginal<typeof import('recharts')>()),
    ResponsiveContainer: ({ children }: { children: ReactElement<{ width?: number; height?: number }> }) =>
      cloneElement(children, { width: 400, height: 240 }),
  };
});

const zero = { refuelings: '0.00', maintenances: '0.00', expenses: '0.00', total: '0.00' };
const data: DashboardCharts = {
  from: '2026-09',
  to: '2026-10',
  fuelTypes: ['gasoline'],
  months: [
    { month: '2026-09', spending: zero, kmDriven: 1200, consumption: { gasoline: 14.2 } },
    { month: '2026-10', spending: zero, kmDriven: 300, consumption: { gasoline: 15.1 } },
  ],
  spendingByCategory: [],
  totals: { spending: '0.00', kmDriven: 1500 },
};

// h-72 è l'altezza di ChartCard: i segnaposto la ripetono per non spostare il layout (jsdom non ha layout).
const skeletons = (container: HTMLElement) => container.querySelectorAll('.h-72');

function renderSection(props: Partial<Parameters<typeof ChartsSection>[0]>) {
  return render(
    <ChartsSection headingId="t" title="Grafici" data={undefined} isLoading={false} error={null} {...props} />,
  );
}

describe('ChartsSection — grafici in lazy', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  // Il chunk dei grafici si risolve una volta per modulo: questo deve essere il primo test che lo monta.
  it('con i dati pronti mostra prima i 4 segnaposto e poi i grafici, senza altro cambio di titolo', async () => {
    const { container } = renderSection({ data });

    expect(screen.getByRole('heading', { name: 'Grafici' })).toBeInTheDocument();
    expect(skeletons(container)).toHaveLength(4);

    expect(await screen.findByText('Spese mensili')).toBeInTheDocument();
    expect(skeletons(container)).toHaveLength(0);
    expect(screen.getByRole('heading', { name: 'Grafici' })).toBeInTheDocument();
  });

  it('mentre i dati si caricano mostra i 4 segnaposto e nessun grafico', async () => {
    const { container } = renderSection({ isLoading: true });

    // il fallback e lo stato di caricamento della griglia sono lo stesso segnaposto
    await waitFor(() => expect(skeletons(container)).toHaveLength(4));
    expect(screen.queryByText('Spese mensili')).not.toBeInTheDocument();
  });

  it("con un errore e senza dati mostra l'avviso e nessun segnaposto", () => {
    const { container } = renderSection({ error: new Error('boom') });

    expect(screen.getByRole('alert')).toHaveTextContent('boom');
    expect(skeletons(container)).toHaveLength(0);
  });
});
