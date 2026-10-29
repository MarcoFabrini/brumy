import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { ExpenseForm, VehicleUsableGate } from '@/components/features';
import { useExpense, useUpdateExpense } from '@/hooks/useExpenses';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';

export function ExpenseEditPage() {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const expenseId = Number(id);
  const navigate = useNavigate();
  // Lo state del dettaglio (elenco di provenienza con la ricerca) torna al dettaglio, per il suo "‹ Indietro"
  const { state: returnState } = useLocation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useExpense(expenseId);
  const updateMutation = useUpdateExpense(expenseId);

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
        title={t('expense.edit')}
        onBack={() => navigate(`/expenses/${expenseId}`, { state: returnState })}
      />

      <VehicleUsableGate vehicleId={data.vehicleId}>
        <ExpenseForm
          section={data.section}
          vehicleId={data.vehicleId}
          defaultValues={data}
          isPending={updateMutation.isPending}
          error={updateMutation.error}
          onCancel={() => navigate(`/expenses/${expenseId}`, { state: returnState })}
          onSubmit={(form) =>
            updateMutation.mutate(form, {
              onSuccess: () => {
                toast({ title: t('expense.updated'), variant: 'success' });
                navigate(`/expenses/${expenseId}`, { replace: true, state: returnState });
              },
            })
          }
        />
      </VehicleUsableGate>
    </div>
  );
}
