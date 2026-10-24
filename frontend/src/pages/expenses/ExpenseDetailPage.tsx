import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  ConfirmDialog,
  DeleteRow,
  EditLink,
  InfoList,
  InfoRow,
} from '@/components/features';
import { useDeleteExpense, useExpense } from '@/hooks/useExpenses';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';
import { useFormat } from '@/hooks/useFormat';

export function ExpenseDetailPage() {
  const { t } = useTranslation();
  const fmt = useFormat();
  const { id } = useParams<{ id: string }>();
  const expenseId = Number(id);
  const navigate = useNavigate();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useExpense(expenseId);
  // Veicolo condiviso in sola lettura: niente modifica, eliminazione, allegati.
  const canEdit = useCanEditVehicle(data?.vehicleId ?? 0);
  const deleteMutation = useDeleteExpense(data?.vehicleId ?? 0);
  const [confirmOpen, setConfirmOpen] = useState(false);

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-48 w-full rounded-lg" />
      </div>
    );
  }
  if (error) return <Alert variant="error">{errorMessage(error)}</Alert>;
  if (!data) return null;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t(`expense.category_options.${data.category}`)}
        description={data.description || undefined}
        onBack={() => navigate(`/expenses?vehicleId=${data.vehicleId}`)}
        action={canEdit ? <EditLink to={`/expenses/${data.id}/edit`} /> : undefined}
      />

      <InfoList footer={data.recurring ? t('expense.recurring_counted_note') : undefined}>
        <InfoRow label={t('expense.amount')} value={<span className="font-semibold text-foreground">{fmt.currency(data.amount)}</span>} />
        <InfoRow label={t('expense.occurred_at')} value={fmt.date(data.occurredAt)} />
        {data.recurring && data.recurringPeriod && (
          <InfoRow label={t('expense.recurring')} value={t(`expense.recurring_options.${data.recurringPeriod}`)} />
        )}
        {data.recurring && data.recurringUntil && (
          <InfoRow label={t('expense.recurring_until_short')} value={fmt.date(data.recurringUntil)} />
        )}
        {data.notes && (
          <div className="px-4 py-3">
            <dt className="sr-only">{t('common.notes')}</dt>
            <dd className="whitespace-pre-wrap text-[0.9375rem] text-muted-foreground">{data.notes}</dd>
          </div>
        )}
      </InfoList>

      {canEdit && <DeleteRow label={t('expense.delete.action')} onDelete={() => setConfirmOpen(true)} />}

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('expense.delete.title')}
        description={t('expense.delete.description')}
        isPending={deleteMutation.isPending}
        confirmLabel={t('actions.delete')}
        onConfirm={() =>
          deleteMutation.mutate(expenseId, {
            onSuccess: () => {
              toast({ title: t('expense.deleted'), variant: 'success' });
              navigate(`/expenses?vehicleId=${data.vehicleId}`, { replace: true });
            },
            onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
          })
        }
      />
    </div>
  );
}
