import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/components/layout';
import { MaintenanceForm, SelectVehiclePrompt, VehicleUsableGate } from '@/components/features';
import { maintenanceKind, sectionOf, withVehicle } from '@/components/features/vehicleRecordActions';
import { useCreateMaintenance } from '@/hooks/useMaintenances';
import { useComponentFollowUp } from '@/hooks/useComponentFollowUp';
import { useMaintenanceCategoryParam } from '@/hooks/useSectionParam';
import { useVehicleStats } from '@/hooks/useVehicles';
import { useToast } from '@/hooks/useToast';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';

export function MaintenanceNewPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { toast } = useToast();
  const category = useMaintenanceCategoryParam();
  const section = sectionOf(maintenanceKind(category));
  const { vehicleId } = useVehicleIdParam();
  const statsQuery = useVehicleStats(vehicleId);
  const mutation = useCreateMaintenance();
  const followUp = useComponentFollowUp();
  const listPath = withVehicle(section.listPath, vehicleId);

  if (vehicleId === 0) {
    return <SelectVehiclePrompt i18nPrefix="maintenance" listPath={section.listPath} />;
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t(section.labelKey)} onBack={() => navigate(listPath)} />

      <VehicleUsableGate vehicleId={vehicleId}>
        <MaintenanceForm
          vehicleId={vehicleId}
          category={category}
          defaultKm={statsQuery.data?.currentKm}
          isPending={mutation.isPending}
          error={mutation.error}
          onCancel={() => navigate(listPath)}
          onSubmit={(data, { newComponents }) =>
            mutation.mutate(data, {
              onSuccess: (created) => {
                toast({ title: t('maintenance.created'), variant: 'success' });
                followUp(created, newComponents, `/maintenance/${created.id}`);
              },
            })
          }
        />
      </VehicleUsableGate>
    </div>
  );
}
