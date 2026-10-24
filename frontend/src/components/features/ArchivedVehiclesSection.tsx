import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown } from 'lucide-react';
import { Button, ListSection } from '@/components/ui';
import { VehicleCard } from './VehicleCard';
import { cn } from '@/lib/utils';
import type { Vehicle } from '@/api/types/vehicle';

/**
 * ArchivedVehiclesSection feature — veicoli archiviati, chiusi di default.
 *
 * Non mostra nulla se non ce ne sono. Ogni scheda porta al dettaglio, dove c'è "Ripristina".
 *
 * @example
 * <ArchivedVehiclesSection vehicles={archived.data ?? []} />
 */
export interface ArchivedVehiclesSectionProps {
  vehicles: Vehicle[];
}

export function ArchivedVehiclesSection({ vehicles }: ArchivedVehiclesSectionProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  if (vehicles.length === 0) return null;

  return (
    <section className="space-y-3">
      <Button
        type="button"
        variant="ghost"
        className="w-full justify-between"
        aria-expanded={open}
        aria-controls="archived-vehicles"
        onClick={() => setOpen((v) => !v)}
      >
        <span>
          {t('vehicle.archived_section')} ({vehicles.length})
        </span>
        <ChevronDown className={cn('transition-transform', open && 'rotate-180')} />
      </Button>
      {open && (
        <ListSection id="archived-vehicles">
          {vehicles.map((vehicle) => (
            <VehicleCard key={vehicle.id} vehicle={vehicle} />
          ))}
        </ListSection>
      )}
    </section>
  );
}
