import { useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  ConfirmDialog,
  DeleteRow,
  EditLink,
  InfoList,
  InfoRow,
  NotesRow,
} from '@/components/features';
import { expenseKind, sectionOf, withVehicle } from '@/components/features/vehicleRecordActions';
import { useDeleteExpense, useExpense } from '@/hooks/useExpenses';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';
import { useFormat } from '@/hooks/useFormat';
import { listReturnPath } from '@/lib/listReturn';

export function ExpenseDetailPage() {
  const { t } = useTranslation();
  const fmt = useFormat();
  const { id } = useParams<{ id: string }>();
  const expenseId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
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
  const section = sectionOf(expenseKind(data.section));
  // All'elenco da cui si è arrivati (con la sua ricerca), altrimenti alla sezione del veicolo
  const listPath = listReturnPath(location.state, '/expenses', withVehicle(section.listPath, data.vehicleId));

  return (
    <div className="space-y-6">
      <PageHeader
        title={data.section === 'insurance' ? t(section.titleKey) : data.category}
        description={data.description || undefined}
        onBack={() => navigate(listPath)}
        action={canEdit ? <EditLink to={`/expenses/${data.id}/edit`} /> : undefined}
      />

      <InfoList footer={data.recurring ? t('expense.recurring_counted_note') : undefined}>
        <InfoRow label={t('expense.amount')} value={<span className="font-semibold text-foreground">{fmt.currency(data.amount)}</span>} />
        <InfoRow label={t('expense.occurred_at')} value={fmt.date(data.occurredAt)} />
        {data.insurer && <InfoRow label={t('expense.insurer')} value={<span className="truncate">{data.insurer}</span>} />}
        {data.recurring && data.recurringPeriod && (
          <InfoRow label={t('expense.recurring')} value={t(`expense.recurring_options.${data.recurringPeriod}`)} />
        )}
        {data.recurring && data.recurringUntil && (
          <InfoRow label={t('expense.recurring_until_short')} value={fmt.date(data.recurringUntil)} />
        )}
        <NotesRow label={t('common.notes')} notes={data.notes} />
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
              navigate(listPath, { replace: true });
            },
            onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
          })
        }
      />
    </div>
  );
}
