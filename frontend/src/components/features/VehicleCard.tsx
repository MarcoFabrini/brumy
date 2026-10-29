import { Link } from 'react-router-dom';
import { useListReturnState } from '@/hooks/useListReturnState';
import { useTranslation } from 'react-i18next';
import { Car } from 'lucide-react';
import { Badge, ListRow } from '@/components/ui';
import type { Vehicle } from '@/api/types/vehicle';

export interface VehicleCardProps {
  vehicle: Vehicle;
}

/** Un veicolo come riga di lista iOS: nome, poi marca, modello, anno e targa; stato (archiviato, condiviso) a destra. */
export function VehicleCard({ vehicle }: VehicleCardProps) {
  const returnState = useListReturnState();
  const { t } = useTranslation();
  // archivedAt è null per i veicoli attivi; Boolean() gestisce anche un eventuale undefined.
  const archived = Boolean(vehicle.archivedAt);

  return (
    <ListRow
      as={Link}
      to={`/vehicles/${vehicle.id}`}
      state={returnState}
      icon={<Car />}
      iconClassName={archived ? 'bg-[#8e8e93]' : 'bg-[#007aff]'}
      label={vehicle.name}
      description={[`${vehicle.brand} ${vehicle.model}`, vehicle.year, vehicle.licensePlate].filter(Boolean).join(' · ')}
      value={
        archived ? (
          <Badge variant="outline">{t('vehicle.archived')}</Badge>
        ) : vehicle.ownership === 'shared' ? (
          <Badge variant="secondary">{t('vehicle.shared_read_only')}</Badge>
        ) : undefined
      }
      chevron
    />
  );
}
