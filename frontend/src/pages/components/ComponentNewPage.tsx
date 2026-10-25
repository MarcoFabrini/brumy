import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/components/layout';
import { ComponentForm, SelectVehiclePrompt, VehicleUsableGate } from '@/components/features';
import { useCreateComponent } from '@/hooks/useComponents';
import { useToast } from '@/hooks/useToast';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';

export function ComponentNewPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { toast } = useToast();
  const { vehicleId } = useVehicleIdParam();
  const mutation = useCreateComponent();

  if (vehicleId === 0) {
    return <SelectVehiclePrompt i18nPrefix="component" listPath="/components" />;
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('sections.component.new')} onBack={() => navigate(`/components?vehicleId=${vehicleId}`)} />

      <VehicleUsableGate vehicleId={vehicleId}>
        <ComponentForm
          vehicleId={vehicleId}
          isPending={mutation.isPending}
          error={mutation.error}
          onCancel={() => navigate(`/components?vehicleId=${vehicleId}`)}
          onSubmit={(data) =>
            mutation.mutate(data, {
              onSuccess: (created) => {
                toast({ title: t('component.created'), variant: 'success' });
                navigate(`/components/${created.id}`, { replace: true });
              },
            })
          }
        />
      </VehicleUsableGate>
    </div>
  );
}
