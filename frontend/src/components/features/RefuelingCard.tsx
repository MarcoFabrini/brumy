import { Link } from 'react-router-dom';
import { useListReturnState } from '@/hooks/useListReturnState';
import { useTranslation } from 'react-i18next';
import { Fuel } from 'lucide-react';
import { ListRow } from '@/components/ui';
import { MetaLine } from './MetaLine';
import { useFormat } from '@/hooks/useFormat';
import type { Refueling } from '@/api/types/refueling';

export interface RefuelingCardProps {
  refueling: Refueling;
}

/** Un rifornimento come riga di lista iOS: data, poi km, litri e prezzo; importo a destra. Va dentro una ListSection. */
export function RefuelingCard({ refueling: r }: RefuelingCardProps) {
  const returnState = useListReturnState();
  const { t } = useTranslation();
  const fmt = useFormat();

  return (
    <ListRow
      as={Link}
      to={`/refueling/${r.id}`}
      state={returnState}
      icon={<Fuel />}
      iconClassName="bg-[#34c759]"
      label={r.description ?? fmt.date(r.refueledAt)}
      description={
        <MetaLine
          parts={[
            r.description ? fmt.date(r.refueledAt) : null,
            fmt.km(r.km),
            `${fmt.decimal(Number.parseFloat(r.liters), 2)} L`,
            `${fmt.currency(r.pricePerLiter)}/L`,
            !r.fullTank && t('refueling.partial'),
            r.station,
          ]}
        />
      }
      value={<span className="font-medium text-foreground">{fmt.currency(r.totalCost)}</span>}
      chevron
    />
  );
}
