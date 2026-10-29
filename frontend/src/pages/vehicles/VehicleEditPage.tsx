import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { VehicleForm } from '@/components/features';
import { useUpdateVehicle, useVehicle } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';

/**
 * VehicleEditPage — pre-fill VehicleForm con dati esistenti.
 */
export function VehicleEditPage() {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const vehicleId = Number(id);
  const navigate = useNavigate();
  // Lo state del dettaglio (elenco di provenienza con la ricerca) torna al dettaglio, per il suo "‹ Indietro"
  const { state: returnState } = useLocation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useVehicle(vehicleId);
  const updateMutation = useUpdateVehicle(vehicleId);

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
  if (!data.permissions.canEdit) return <Alert variant="warning">{t('vehicle.read_only_no_changes')}</Alert>;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('vehicle.edit')}
        description={data.name}
        onBack={() => navigate(`/vehicles/${vehicleId}`, { state: returnState })}
      />

      <VehicleForm
        defaultValues={data}
        isPending={updateMutation.isPending}
        error={updateMutation.error}
        onCancel={() => navigate(`/vehicles/${vehicleId}`, { state: returnState })}
        onSubmit={(form) =>
          updateMutation.mutate(form, {
            onSuccess: () => {
              toast({ title: t('vehicle.updated'), variant: 'success' });
              navigate(`/vehicles/${vehicleId}`, { replace: true, state: returnState });
            },
          })
        }
      />
    </div>
  );
}
