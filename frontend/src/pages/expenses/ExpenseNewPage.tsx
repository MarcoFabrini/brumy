import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/components/layout';
import { ExpenseForm, SelectVehiclePrompt, VehicleUsableGate } from '@/components/features';
import { expenseKind, sectionOf, withVehicle } from '@/components/features/vehicleRecordActions';
import { useCreateExpense } from '@/hooks/useExpenses';
import { useExpenseSectionParam } from '@/hooks/useSectionParam';
import { useToast } from '@/hooks/useToast';
import { useVehicleIdParam } from '@/hooks/useVehicleIdParam';

export function ExpenseNewPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { toast } = useToast();
  const sectionParam = useExpenseSectionParam();
  const section = sectionOf(expenseKind(sectionParam));
  const { vehicleId } = useVehicleIdParam();
  const mutation = useCreateExpense();
  const listPath = withVehicle(section.listPath, vehicleId);

  if (vehicleId === 0) {
    return <SelectVehiclePrompt i18nPrefix="expense" listPath={section.listPath} />;
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t(section.labelKey)}
        onBack={() => navigate(listPath)}
      />

      <VehicleUsableGate vehicleId={vehicleId}>
        <ExpenseForm
          vehicleId={vehicleId}
          section={sectionParam}
          isPending={mutation.isPending}
          error={mutation.error}
          onCancel={() => navigate(listPath)}
          onSubmit={(data) =>
            mutation.mutate(data, {
              onSuccess: (created) => {
                toast({ title: t('expense.created'), variant: 'success' });
                navigate(`/expenses/${created.id}`, { replace: true });
              },
            })
          }
        />
      </VehicleUsableGate>
    </div>
  );
}
