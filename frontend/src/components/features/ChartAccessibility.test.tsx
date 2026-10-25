import type { ReactElement } from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import i18n from '@/i18n';
import type { ConsumptionRow, KmDrivenRow, MonthlySpendingRow, CategorySlice } from '@/lib/dashboardCharts';
import { ConsumptionTrendChart } from './ConsumptionTrendChart';
import { KmDrivenChart } from './KmDrivenChart';
import { MonthlySpendingChart } from './MonthlySpendingChart';
import { SpendingByCategoryChart } from './SpendingByCategoryChart';

// In jsdom ResponsiveContainer misura 0×0 e non disegna nulla: dimensione fissa.
vi.mock('recharts', async (importOriginal) => {
  const { cloneElement } = await import('react');
  return {
    ...(await importOriginal<typeof import('recharts')>()),
    ResponsiveContainer: ({ children }: { children: ReactElement<{ width?: number; height?: number }> }) =>
      cloneElement(children, { width: 400, height: 240 }),
  };
});

const spending: MonthlySpendingRow[] = [
  { month: '2026-09', label: 'set 26', refuelings: 84.58, scheduled: 120, unscheduled: 0, inspection: 0, taxes: 0, insurance: 0, expenses: 12345.6, total: 12550.18 },
  { month: '2026-10', label: 'ott 26', refuelings: 0, scheduled: 0, unscheduled: 0, inspection: 0, taxes: 0, insurance: 0, expenses: 0, total: 0 },
];
const km: KmDrivenRow[] = [
  { month: '2026-09', label: 'set 26', km: 12345 },
  { month: '2026-10', label: 'ott 26', km: 300 },
];
const consumption: ConsumptionRow[] = [
  { month: '2026-09', label: 'set 26', values: { gasoline: 14.25, lpg: 10.5 } },
  { month: '2026-10', label: 'ott 26', values: { gasoline: 15.1, lpg: null } },
];
const slices: CategorySlice[] = [
  { category: 'expense:1', name: 'Assicurazione', amount: 1650 },
  { category: 'fuel', name: null, amount: 84.58 },
];

/** Testo di ogni cella di una riga, con gli spazi non separabili di Intl resi spazi normali. */
function rowTexts(table: HTMLElement, rowLabel: string): string[] {
  const row = within(table).getByRole('rowheader', { name: rowLabel }).closest('tr');
  if (!row) throw new Error(`riga "${rowLabel}" non trovata`);
  return within(row)
    .getAllByRole('cell')
    .map((cell) => (cell.textContent ?? '').replaceAll('\u00a0', ' '));
}

function headers(table: HTMLElement): string[] {
  return within(table)
    .getAllByRole('columnheader')
    .map((h) => h.textContent ?? '');
}

/** Il grafico disegnato è nascosto agli screen reader e la tabella, no. */
function expectVisualHiddenAndTableExposed(container: HTMLElement, table: HTMLElement) {
  const svgWrapper = container.querySelector('.recharts-wrapper');
  expect(svgWrapper).not.toBeNull();
  expect(svgWrapper?.closest('[aria-hidden="true"]')).not.toBeNull();
  expect(table.closest('[aria-hidden="true"]')).toBeNull();
}

describe('grafici — alternativa testuale per screen reader', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });
  afterEach(async () => {
    cleanup();
    await i18n.changeLanguage('it');
  });

  describe('spese mensili', () => {
    it('tabella con una riga per mese e importi in formato italiano', () => {
      const { container } = render(<MonthlySpendingChart rows={spending} />);
      const table = screen.getByRole('table', { name: 'Spese mensili' });

      // Solo le sezioni con una spesa nella finestra
      expect(headers(table)).toEqual(['Mese', 'Rifornimenti', 'Lavori ordinari', 'Altre spese', 'Totale']);
      expect(rowTexts(table, 'set 26')).toEqual(['84,58 €', '120,00 €', '12.345,60 €', '12.550,18 €']);
      expect(rowTexts(table, 'ott 26')).toEqual(['0,00 €', '0,00 €', '0,00 €', '0,00 €']);
      expectVisualHiddenAndTableExposed(container, table);
    });

    it('in inglese intestazioni e importi seguono la lingua', async () => {
      await i18n.changeLanguage('en');
      render(<MonthlySpendingChart rows={spending} />);
      const table = screen.getByRole('table', { name: 'Monthly spending' });

      expect(headers(table)).toEqual(['Month', 'Refuelings', 'Scheduled work', 'Other expenses', 'Total']);
      expect(rowTexts(table, 'set 26')).toEqual(['€84.58', '€120.00', '€12,345.60', '€12,550.18']);
    });

    it('senza spese mostra il messaggio e nessuna tabella', () => {
      render(<MonthlySpendingChart rows={spending.map((r) => ({ ...r, total: 0 }))} />);

      expect(screen.getByText('Nessuna spesa nel periodo.')).toBeInTheDocument();
      expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
  });

  describe('km percorsi', () => {
    it('tabella con i km di ogni mese in formato italiano', () => {
      const { container } = render(<KmDrivenChart rows={km} />);
      const table = screen.getByRole('table', { name: 'Km percorsi' });

      expect(headers(table)).toEqual(['Mese', 'Km']);
      expect(rowTexts(table, 'set 26')).toEqual(['12.345 km']);
      expect(rowTexts(table, 'ott 26')).toEqual(['300 km']);
      expectVisualHiddenAndTableExposed(container, table);
    });

    it('in inglese separatore delle migliaia inglese', async () => {
      await i18n.changeLanguage('en');
      render(<KmDrivenChart rows={km} />);

      expect(rowTexts(screen.getByRole('table', { name: 'Distance driven' }), 'set 26')).toEqual(['12,345 km']);
    });

    it('senza km mostra il messaggio e nessuna tabella', () => {
      render(<KmDrivenChart rows={km.map((r) => ({ ...r, km: 0 }))} />);

      expect(screen.getByText('Nessuna lettura del contachilometri nel periodo.')).toBeInTheDocument();
      expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
  });

  describe('consumo medio', () => {
    it('una colonna per carburante col nome tradotto e "Nessun dato" dove manca l\'intervallo', () => {
      const { container } = render(<ConsumptionTrendChart rows={consumption} fuelTypes={['gasoline', 'lpg']} />);
      const table = screen.getByRole('table', { name: 'Consumo medio (km/l)' });

      expect(headers(table)).toEqual(['Mese', 'Benzina', 'GPL']);
      expect(rowTexts(table, 'set 26')).toEqual(['14,25', '10,5']);
      expect(rowTexts(table, 'ott 26')).toEqual(['15,1', 'Nessun dato']);
      expectVisualHiddenAndTableExposed(container, table);
    });

    it('in inglese punto decimale e testo per i valori mancanti', async () => {
      await i18n.changeLanguage('en');
      render(<ConsumptionTrendChart rows={consumption} fuelTypes={['gasoline', 'lpg']} />);
      const table = screen.getByRole('table', { name: 'Average consumption (km/l)' });

      expect(headers(table)).toEqual(['Month', 'Gasoline', 'LPG']);
      expect(rowTexts(table, 'set 26')).toEqual(['14.25', '10.5']);
      expect(rowTexts(table, 'ott 26')).toEqual(['15.1', 'No data']);
    });

    it('senza intervalli tra pieni mostra il messaggio e nessuna tabella', () => {
      render(
        <ConsumptionTrendChart
          rows={consumption.map((r) => ({ ...r, values: { gasoline: null, lpg: null } }))}
          fuelTypes={['gasoline', 'lpg']}
        />,
      );

      expect(screen.getByText('Servono almeno due pieni consecutivi per calcolare il consumo.')).toBeInTheDocument();
      expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
  });

  describe('spese per categoria', () => {
    it('tabella con categoria e importo; il nome è testo, non solo colore', () => {
      const { container } = render(<SpendingByCategoryChart slices={slices} />);
      const table = screen.getByRole('table', { name: 'Spese per categoria' });

      expect(headers(table)).toEqual(['Categoria', 'Importo']);
      expect(rowTexts(table, 'Assicurazione')).toEqual(['1650,00 €']);
      expect(rowTexts(table, 'Rifornimenti')).toEqual(['84,58 €']);
      expectVisualHiddenAndTableExposed(container, table);
    });

    it('una fetta di spesa usa il nome della categoria scelto dall\'utente', () => {
      render(<SpendingByCategoryChart slices={[{ category: 'expense:4', name: 'Revisione camper', amount: 80 }]} />);
      const table = screen.getByRole('table', { name: 'Spese per categoria' });

      expect(rowTexts(table, 'Revisione camper')).toEqual(['80,00 €']);
    });

    it('in inglese categorie e importi in inglese', async () => {
      await i18n.changeLanguage('en');
      render(<SpendingByCategoryChart slices={[{ category: 'expense:1', name: 'Assicurazione', amount: 12345.5 }]} />);
      const table = screen.getByRole('table', { name: 'Spending by category' });

      expect(headers(table)).toEqual(['Category', 'Amount']);
      expect(rowTexts(table, 'Assicurazione')).toEqual(['€12,345.50']);
    });

    it('la legenda sotto l\'anello è nascosta agli screen reader come il grafico', () => {
      render(<SpendingByCategoryChart slices={slices} />);

      // Le stesse categorie sono già nella tabella: la lista visiva non va letta due volte.
      expect(screen.getAllByText('Assicurazione')).toHaveLength(2);
      expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });

    it('senza spese mostra il messaggio e nessuna tabella', () => {
      render(<SpendingByCategoryChart slices={[]} />);

      expect(screen.getByText('Nessuna spesa da suddividere nel periodo.')).toBeInTheDocument();
      expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
  });
});
