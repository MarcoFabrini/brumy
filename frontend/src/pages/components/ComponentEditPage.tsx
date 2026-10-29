import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { ComponentForm, VehicleUsableGate } from '@/components/features';
import { useComponent, useUpdateComponent } from '@/hooks/useComponents';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';
import { componentFollowUpPath } from '@/lib/componentNames';

/**
 * Modifica di un componente. Aperta anche dopo il salvataggio di un lavoro che ha creato componenti nuovi
 * (`?back=/maintenance/5&next=12,13`): salvando o annullando si passa al prossimo da completare, poi a `back`.
 */
export function ComponentEditPage() {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const componentId = Number(id);
  const [params] = useSearchParams();
  const navigate = useNavigate();
  // Lo state del dettaglio (elenco di provenienza con la ricerca) torna al dettaglio, per il suo "‹ Indietro"
  const { state: returnState } = useLocation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useComponent(componentId);
  const mutation = useUpdateComponent(componentId);

  // Solo percorsi interni: `back` arriva dalla query string
  const backParam = params.get('back');
  const back = backParam?.startsWith('/') && !backParam.startsWith('//') ? backParam : null;
  const queue = (params.get('next') ?? '').split(',').map(Number).filter((n) => Number.isInteger(n) && n > 0);
  const leave = () =>
    navigate((back && componentFollowUpPath(queue, back)) ?? back ?? `/components/${componentId}`, {
      replace: true,
      state: back ? undefined : returnState,
    });

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
        title={back ? t('component.complete_title') : t('component.edit')}
        description={back ? t('component.complete_description', { name: data.name }) : data.name}
        onBack={leave}
      />

      <VehicleUsableGate vehicleId={data.vehicleId}>
        <ComponentForm
          vehicleId={data.vehicleId}
          defaultValues={data}
          isPending={mutation.isPending}
          error={mutation.error}
          onCancel={leave}
          onSubmit={(form) =>
            mutation.mutate(form, {
              onSuccess: () => {
                toast({ title: t('component.updated'), variant: 'success' });
                leave();
              },
            })
          }
        />
      </VehicleUsableGate>
    </div>
  );
}
