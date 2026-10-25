import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Check } from 'lucide-react';
import { Alert, Button, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  CompleteReminderDialog,
  ConfirmDialog,
  DeleteRow,
  EditLink,
  InfoList,
  InfoRow,
  NotesRow,
  ReminderBadge,
  ReminderRecurrenceText,
} from '@/components/features';
import { isRecurringReminder } from '@/api/types/reminder';
import { useDeleteReminder, useReminder } from '@/hooks/useReminders';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';
import { useFormat } from '@/hooks/useFormat';

export function ReminderDetailPage() {
  const { t } = useTranslation();
  const fmt = useFormat();
  const { id } = useParams<{ id: string }>();
  const reminderId = Number(id);
  const navigate = useNavigate();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useReminder(reminderId);
  // Veicolo condiviso in sola lettura: niente modifica, eliminazione, allegati.
  const canEdit = useCanEditVehicle(data?.vehicleId ?? 0);
  const deleteMutation = useDeleteReminder();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [completeOpen, setCompleteOpen] = useState(false);

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

  const isDone = data.completedAt !== null;

  return (
    <div className="space-y-6">
      <PageHeader
        title={data.description || t(`reminder.type_options.${data.type}`)}
        // Il tipo come sottotitolo solo se dice qualcosa in più del titolo (es. "Cambio olio" → "Tagliando").
        description={
          data.description && data.description !== t(`reminder.type_options.${data.type}`)
            ? t(`reminder.type_options.${data.type}`)
            : undefined
        }
        onBack={() => navigate(`/reminders?vehicleId=${data.vehicleId}`)}
        action={canEdit ? <EditLink to={`/reminders/${data.id}/edit`} /> : undefined}
      />

      {/* L'azione principale di un promemoria aperto, grande e in cima: come "Completa" nelle app iOS. */}
      {!isDone && canEdit && (
        <Button size="lg" fullWidth onClick={() => setCompleteOpen(true)}>
          <Check />
          {t('reminder.mark_complete')}
        </Button>
      )}

      <InfoList>
        <InfoRow label={t('reminder.status')} value={<ReminderBadge reminder={data} />} />
        {data.dueDate && <InfoRow label={t('reminder.due_date')} value={fmt.date(data.dueDate)} />}
        {data.dueKm != null && <InfoRow label={t('reminder.due_km')} value={fmt.km(data.dueKm)} />}
        <InfoRow
          label={t('reminder.recurrence')}
          value={isRecurringReminder(data) ? <ReminderRecurrenceText reminder={data} /> : t('reminder.one_off')}
        />
        <InfoRow label={t('reminder.notify_days_before')} value={`${data.notifyDaysBefore} ${t('reminder.days')}`} />
        {data.dueKm != null && <InfoRow label={t('reminder.notify_km_before')} value={fmt.km(data.notifyKmBefore)} />}
        {data.completedAt && (
          // Data effettiva del lavoro; i completamenti precedenti hanno solo l'istante del click.
          <InfoRow label={t('reminder.completed_at')} value={fmt.date(data.completedOn ?? data.completedAt)} />
        )}
        {data.completedKm != null && <InfoRow label={t('reminder.completed_km')} value={fmt.km(data.completedKm)} />}
        <NotesRow label={t('common.notes')} notes={data.notes} />
      </InfoList>

      {canEdit && <DeleteRow label={t('reminder.delete.action')} onDelete={() => setConfirmOpen(true)} />}

      <CompleteReminderDialog
        open={completeOpen}
        onOpenChange={setCompleteOpen}
        reminder={data}
        onCompleted={({ next }) => {
          // Il ricorrente continua nel successore; una tantum resta qui, ora completato.
          if (next) navigate(`/reminders/${next.id}`, { replace: true });
        }}
      />

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('reminder.delete.title')}
        description={t('reminder.delete.description')}
        isPending={deleteMutation.isPending}
        confirmLabel={t('actions.delete')}
        onConfirm={() =>
          deleteMutation.mutate(reminderId, {
            onSuccess: () => {
              toast({ title: t('reminder.deleted'), variant: 'success' });
              navigate(`/reminders?vehicleId=${data.vehicleId}`, { replace: true });
            },
            onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
          })
        }
      />
    </div>
  );
}
