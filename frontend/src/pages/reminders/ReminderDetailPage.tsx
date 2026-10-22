import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Check, Pencil, Trash2 } from 'lucide-react';
import {
  Alert,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Skeleton,
} from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  CompleteReminderDialog,
  ConfirmDialog,
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
        title={t(`reminder.type_options.${data.type}`)}
        description={data.description}
        onBack={() => navigate(`/reminders?vehicleId=${data.vehicleId}`)}
        action={
          canEdit ? (
            <>
              <Button variant="outline" size="icon" asChild aria-label={t('actions.edit')}>
                <Link to={`/reminders/${data.id}/edit`}>
                  <Pencil />
                </Link>
              </Button>
              <Button
                variant="destructive"
                size="icon"
                onClick={() => setConfirmOpen(true)}
                aria-label={t('actions.delete')}
              >
                <Trash2 />
              </Button>
            </>
          ) : undefined
        }
      />

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            {t('reminder.info')}
            <ReminderBadge reminder={data} />
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <dl className="grid grid-cols-2 gap-3 text-sm">
            {data.dueDate && (
              <div>
                <dt className="text-muted-foreground">{t('reminder.due_date')}</dt>
                <dd className="font-medium">{fmt.date(data.dueDate)}</dd>
              </div>
            )}
            {data.dueKm != null && (
              <div>
                <dt className="text-muted-foreground">{t('reminder.due_km')}</dt>
                <dd className="font-medium">{fmt.km(data.dueKm)}</dd>
              </div>
            )}
            <div>
              <dt className="text-muted-foreground">{t('reminder.notify_days_before')}</dt>
              <dd className="font-medium">{data.notifyDaysBefore} {t('reminder.days')}</dd>
            </div>
            {data.dueKm != null && (
              <div>
                <dt className="text-muted-foreground">{t('reminder.notify_km_before')}</dt>
                <dd className="font-medium">{fmt.km(data.notifyKmBefore)}</dd>
              </div>
            )}
            <div>
              <dt className="text-muted-foreground">{t('reminder.recurrence')}</dt>
              <dd className="font-medium">
                {isRecurringReminder(data) ? <ReminderRecurrenceText reminder={data} /> : t('reminder.one_off')}
              </dd>
            </div>
            {data.completedAt && (
              <div>
                <dt className="text-muted-foreground">{t('reminder.completed_at')}</dt>
                {/* Data effettiva del lavoro; i completamenti precedenti hanno solo l'istante del click. */}
                <dd className="font-medium">{fmt.date(data.completedOn ?? data.completedAt)}</dd>
              </div>
            )}
            {data.completedKm != null && (
              <div>
                <dt className="text-muted-foreground">{t('reminder.completed_km')}</dt>
                <dd className="font-medium">{fmt.km(data.completedKm)}</dd>
              </div>
            )}
          </dl>

          {!isDone && canEdit && (
            <Button variant="primary" fullWidth onClick={() => setCompleteOpen(true)}>
              <Check />
              {t('reminder.mark_complete')}
            </Button>
          )}
        </CardContent>
      </Card>

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
