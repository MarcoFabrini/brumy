import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Wrench } from 'lucide-react';
import { ListRow } from '@/components/ui';
import { MetaLine } from './MetaLine';
import { useFormat } from '@/hooks/useFormat';
import type { Maintenance } from '@/api/types/maintenance';

export interface MaintenanceCardProps {
  maintenance: Maintenance;
}

/** Una manutenzione come riga di lista iOS: tipo, poi data, km e officina; costo a destra. */
export function MaintenanceCard({ maintenance: m }: MaintenanceCardProps) {
  const { t } = useTranslation();
  const fmt = useFormat();

  return (
    <ListRow
      as={Link}
      to={`/maintenance/${m.id}`}
      icon={<Wrench />}
      iconClassName={m.category === 'scheduled' ? 'bg-[#ff9500]' : 'bg-[#ff3b30]'}
      label={t(`maintenance.type_options.${m.type}`)}
      description={
        <MetaLine
          parts={[fmt.date(m.performedAt), fmt.km(m.km), m.workshop, t(`maintenance.category_options.${m.category}`)]}
        />
      }
      value={<span className="font-medium text-foreground">{fmt.currency(m.cost)}</span>}
      chevron
    />
  );
}
