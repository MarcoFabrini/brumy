import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Plus } from 'lucide-react';
import {
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui';
import { VEHICLE_RECORD_ACTIONS, withVehicle } from './vehicleRecordActions';

export interface VehicleAddRecordMenuProps {
  vehicleId: number;
  className?: string;
}

/**
 * Pulsante "Aggiungi" del dettaglio veicolo: apre la scelta di cosa inserire (una voce per sezione)
 * per quel veicolo. Su mobile lo stesso ruolo lo ha il "+"
 * della barra in basso.
 */
export function VehicleAddRecordMenu({ vehicleId, className }: VehicleAddRecordMenuProps) {
  const { t } = useTranslation();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button className={className}>
          <Plus />
          {t('vehicle.add_record')}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        {VEHICLE_RECORD_ACTIONS.map(({ path, labelKey, Icon }) => (
          <DropdownMenuItem key={path} asChild>
            <Link to={withVehicle(path, vehicleId)}>
              <Icon className="size-4 text-primary" />
              {t(labelKey)}
            </Link>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
