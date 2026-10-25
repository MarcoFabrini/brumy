import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { ListRow } from '@/components/ui';
import { MetaLine } from './MetaLine';
import { useFormat } from '@/hooks/useFormat';
import type { Maintenance } from '@/api/types/maintenance';
import { maintenanceKind, sectionOf } from './vehicleRecordActions';

export interface MaintenanceCardProps {
  maintenance: Maintenance;
}

/** Un lavoro come riga di lista iOS: descrizione, poi tipo, data, km e officina; costo a destra. Icona della sezione. */
export function MaintenanceCard({ maintenance: m }: MaintenanceCardProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const { Icon, iconClassName } = sectionOf(maintenanceKind(m.category));

  return (
    <ListRow
      as={Link}
      to={`/maintenance/${m.id}`}
      icon={<Icon />}
      iconClassName={iconClassName}
      label={m.description}
      description={
        <MetaLine
          parts={[
            m.category === 'inspection' ? null : t(`maintenance.type_options.${m.type}`),
            fmt.date(m.performedAt),
            fmt.km(m.km),
            m.workshop,
          ]}
        />
      }
      value={<span className="font-medium text-foreground">{fmt.currency(m.cost)}</span>}
      chevron
    />
  );
}
