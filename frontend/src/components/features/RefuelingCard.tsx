import { Link } from 'react-router-dom';
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
  const { t } = useTranslation();
  const fmt = useFormat();

  return (
    <ListRow
      as={Link}
      to={`/refueling/${r.id}`}
      icon={<Fuel />}
      iconClassName="bg-[#34c759]"
      label={fmt.date(r.refueledAt)}
      description={
        <MetaLine
          parts={[
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
