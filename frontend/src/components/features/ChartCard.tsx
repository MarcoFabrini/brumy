import type { ReactNode } from 'react';
import { ChartDataTable, type ChartDataTableProps } from './ChartDataTable';
import { Card, CardContent, CardHeader, CardTitle, Text } from '@/components/ui';

export interface ChartCardProps {
  title: string;
  /** Messaggio al posto del grafico quando la serie non ha dati nella finestra. */
  emptyMessage: string;
  isEmpty: boolean;
  /** Il grafico (dentro un `ResponsiveContainer`): riceve un'altezza fissa, mobile-first. */
  children: ReactNode;
  /** Gli stessi dati del grafico come tabella per gli screen reader (la didascalia è il titolo). */
  table: Pick<ChartDataTableProps, 'columns' | 'rows'>;
  /** Contenuto sotto il grafico (es. legenda con importi). */
  footer?: ReactNode;
  /** Controllo accanto al titolo (es. la scelta del carburante), visibile anche col messaggio vuoto. */
  action?: ReactNode;
}

/**
 * Card comune ai grafici della dashboard: titolo, grafico ad altezza fissa o messaggio vuoto.
 * Il grafico (e la legenda sotto) è solo visivo: agli screen reader arriva la tabella dei dati.
 * Niente `accessibilityLayer` di recharts: renderebbe focalizzabile un SVG dentro `aria-hidden`.
 */
export function ChartCard({ title, emptyMessage, isEmpty, children, table, footer, action }: ChartCardProps) {
  return (
    <Card className="min-w-0">
      <CardHeader className={action ? 'flex-row items-center justify-between gap-3 space-y-0' : undefined}>
        <CardTitle>{title}</CardTitle>
        {action}
      </CardHeader>
      <CardContent>
        {isEmpty ? (
          <Text variant="muted" className="flex h-24 items-center text-sm">
            {emptyMessage}
          </Text>
        ) : (
          <>
            <div aria-hidden="true">
              <div className="h-56 w-full md:h-64">{children}</div>
              {footer}
            </div>
            <ChartDataTable caption={title} columns={table.columns} rows={table.rows} />
          </>
        )}
      </CardContent>
    </Card>
  );
}
