import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { Button } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { EmptyState, MaintenanceCard, VehicleScopedList } from '@/components/features';
import { maintenanceKind, sectionOf, withVehicle } from '@/components/features/vehicleRecordActions';
import { useMaintenances } from '@/hooks/useMaintenances';
import { useMaintenanceCategoryParam } from '@/hooks/useSectionParam';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';
import { useVehicleName } from '@/hooks/useVehicleName';
import { useCanEditVehicle } from '@/hooks/useVehicles';

/**
 * MaintenanceListPage — una sezione dei lavori (`?category=scheduled|unscheduled|inspection`): scelta veicolo via
 * griglia di card (VehiclePicker); il click apre la lista di quella sezione per il veicolo (`?vehicleId=N`).
 */
export function MaintenanceListPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const category = useMaintenanceCategoryParam();
  const section = sectionOf(maintenanceKind(category));
  const { vehicleId, setParams } = useVehicleIdParam();
  const vehicleName = useVehicleName(vehicleId);
  // Veicolo condiviso in sola lettura: nessun "nuovo".
  const canEdit = useCanEditVehicle(vehicleId);
  const { data, isLoading, error } = useMaintenances(vehicleId, category);

  // Link condiviso: nell'header è nascosto su mobile (c'è il "+" della barra), nello stato vuoto no.
  const newLink = (
    <Link to={withVehicle(section.path, vehicleId)}>
      <Plus />
      {t(section.labelKey)}
    </Link>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={vehicleId > 0 ? t(section.titleKey) : undefined}
        srTitle={t(section.titleKey)}
        description={vehicleId > 0 ? vehicleName : undefined}
        onBack={vehicleId > 0 ? () => navigate(`/vehicles/${vehicleId}`) : undefined}
        action={
          vehicleId > 0 && canEdit && (
            <Button asChild className="hidden md:inline-flex">
              {newLink}
            </Button>
          )
        }
      />

      <VehicleScopedList
        vehicleId={vehicleId}
        onSelectVehicle={(id) => setParams({ category, vehicleId: String(id) })}
        items={data}
        isLoading={isLoading}
        error={error}
        empty={
          <EmptyState
            Icon={section.Icon}
            title={t(`sections.${section.kind}.empty`)}
            description={t('maintenance.empty.description')}
            action={canEdit ? <Button asChild>{newLink}</Button> : undefined}
          />
        }
        renderItem={(m) => <MaintenanceCard key={m.id} maintenance={m} />}
      />
    </div>
  );
}
