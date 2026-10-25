import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Package, Plus } from 'lucide-react';
import { Button } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { ComponentCard, EmptyState, VehicleScopedList } from '@/components/features';
import { useComponents } from '@/hooks/useComponents';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';
import { useVehicleName } from '@/hooks/useVehicleName';
import { useCanEditVehicle } from '@/hooks/useVehicles';

/** Componenti (pezzi comprati) del veicolo scelto, per nome. */
export function ComponentListPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { vehicleId, setParams } = useVehicleIdParam();
  const vehicleName = useVehicleName(vehicleId);
  const canEdit = useCanEditVehicle(vehicleId);
  const { data, isLoading, error } = useComponents(vehicleId);

  const newLink = (
    <Link to={`/components/new?vehicleId=${vehicleId}`}>
      <Plus />
      {t('sections.component.new')}
    </Link>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={vehicleId > 0 ? t('sections.component.title') : undefined}
        srTitle={t('sections.component.title')}
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
        onSelectVehicle={(id) => setParams({ vehicleId: String(id) })}
        items={data}
        isLoading={isLoading}
        error={error}
        empty={
          <EmptyState
            Icon={Package}
            title={t('sections.component.empty')}
            description={t('component.empty_description')}
            action={canEdit ? <Button asChild>{newLink}</Button> : undefined}
          />
        }
        renderItem={(c) => <ComponentCard key={c.id} component={c} />}
      />
    </div>
  );
}
