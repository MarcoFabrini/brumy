import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { MaintenanceForm, VehicleUsableGate } from '@/components/features';
import { useMaintenance, useUpdateMaintenance } from '@/hooks/useMaintenances';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useComponentFollowUp } from '@/hooks/useComponentFollowUp';
import { useToast } from '@/hooks/useToast';

export function MaintenanceEditPage() {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const maintenanceId = Number(id);
  const navigate = useNavigate();
  // Lo state del dettaglio (elenco di provenienza con la ricerca) torna al dettaglio, per il suo "‹ Indietro"
  const { state: returnState } = useLocation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useMaintenance(maintenanceId);
  const updateMutation = useUpdateMaintenance(maintenanceId);
  const followUp = useComponentFollowUp();

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-96 w-full rounded-lg" />
      </div>
    );
  }
  if (error) return <Alert variant="error">{errorMessage(error)}</Alert>;
  if (!data) return null;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('maintenance.edit')}
        description={data.category === 'inspection' ? t('sections.inspection.title') : t(`maintenance.type_options.${data.type}`)}
        onBack={() => navigate(`/maintenance/${maintenanceId}`, { state: returnState })}
      />

      <VehicleUsableGate vehicleId={data.vehicleId}>
        <MaintenanceForm
          vehicleId={data.vehicleId}
          category={data.category}
          defaultValues={data}
          isPending={updateMutation.isPending}
          error={updateMutation.error}
          onCancel={() => navigate(`/maintenance/${maintenanceId}`, { state: returnState })}
          onSubmit={(form, { newComponents }) =>
            updateMutation.mutate(form, {
              onSuccess: (saved) => {
                toast({ title: t('maintenance.updated'), variant: 'success' });
                followUp(saved, newComponents, `/maintenance/${maintenanceId}`, returnState);
              },
            })
          }
        />
      </VehicleUsableGate>
    </div>
  );
}
