import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import { ZodError } from 'zod';
import {
  Alert,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  FormField,
  Input,
  Text,
} from '@/components/ui';
import { ApiError } from '@/api/client';
import { isRecurringReminder, type CompleteReminderResult, type Reminder } from '@/api/types/reminder';
import { DialogFormActions } from './DialogFormActions';
import { useCompleteReminder } from '@/hooks/useReminders';
import { useVehicleStats } from '@/hooks/useVehicles';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { completeReminderSchema, type CompleteReminderFormData } from '@/schemas/reminder.schema';
import { todayIso } from '@/lib/format';

type CompletableReminder = Pick<Reminder, 'id' | 'vehicleId' | 'recurrenceMonths' | 'recurrenceKm'>;

export interface CompleteReminderDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  reminder: CompletableReminder;
  /** Dopo il completamento (dialog già chiuso, toast già mostrato): la pagina decide dove andare. */
  onCompleted: (result: CompleteReminderResult) => void;
}

/**
 * Segna un promemoria come completato registrando data e km EFFETTIVI del lavoro: per un ricorrente
 * sono la base della scadenza successiva, che il backend crea nella stessa richiesta.
 */
export function CompleteReminderDialog({ open, onOpenChange, reminder, onCompleted }: CompleteReminderDialogProps) {
  const { t } = useTranslation();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent mobileFullScreen>
        <DialogHeader>
          <DialogTitle>{t('reminder.complete_dialog.title')}</DialogTitle>
          <DialogDescription>{t('reminder.complete_dialog.description')}</DialogDescription>
        </DialogHeader>
        {/* Il contenuto si monta a ogni apertura: data di oggi, km attuali ed errori sempre freschi. */}
        <CompleteReminderForm
          reminder={reminder}
          onCancel={() => onOpenChange(false)}
          onCompleted={(result) => {
            onOpenChange(false);
            onCompleted(result);
          }}
        />
      </DialogContent>
    </Dialog>
  );
}

interface CompleteReminderFormProps {
  reminder: CompletableReminder;
  onCancel: () => void;
  onCompleted: (result: CompleteReminderResult) => void;
}

function CompleteReminderForm({ reminder, onCancel, onCompleted }: CompleteReminderFormProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const tErr = useFieldErrorMessage();
  const mutation = useCompleteReminder();
  const currentKm = useVehicleStats(reminder.vehicleId).data?.currentKm;

  const form = useForm<CompleteReminderFormData>({
    // Con la ricorrenza a km i km sono la base della prossima scadenza: obbligatori.
    resolver: zodResolver(completeReminderSchema(reminder.recurrenceKm != null)),
    defaultValues: { completedOn: todayIso(), completedKm: currentKm ?? null },
  });
  const { hasUnmapped } = useServerFieldErrors(form, mutation.error);

  // dirtyFields è dietro un Proxy: letto in render per restare aggiornato nell'effect.
  const { errors, dirtyFields } = form.formState;

  // I km attuali (stats) possono arrivare dopo l'apertura: precompilano il campo solo se l'utente non l'ha toccato.
  useEffect(() => {
    if (currentKm !== undefined && !dirtyFields.completedKm) form.setValue('completedKm', currentKm);
  }, [currentKm, dirtyFields.completedKm, form]);

  function submit(data: CompleteReminderFormData) {
    mutation.mutate(
      { id: reminder.id, ...data },
      {
        onSuccess: (result) => {
          toast({
            title: result.next ? t('reminder.completed_next') : t('reminder.completed'),
            variant: 'success',
          });
          onCompleted(result);
        },
        onError: (e) => {
          // Gli errori sui campi li mostra il form; il resto è un toast.
          if (e instanceof ApiError && e.title === 'validation_failed') return;
          toast({ title: e instanceof ZodError ? t('errors.unknown') : errorMessage(e), variant: 'error' });
          // Già completato altrove: riprovare non serve, il dialog si chiude e la pagina si ricarica (vedi l'hook).
          if (e instanceof ApiError && e.status === 409) onCancel();
        },
      },
    );
  }

  return (
    <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
      <FormField
        label={t('reminder.complete_dialog.date')}
        error={tErr(errors.completedOn?.message)}
        required
      >
        {(id) => (
          <Input id={id} type="date" max={todayIso()} invalid={!!errors.completedOn} {...form.register('completedOn')} />
        )}
      </FormField>

      <FormField
        label={t('reminder.complete_dialog.km')}
        error={tErr(errors.completedKm?.message)}
        required={reminder.recurrenceKm != null}
      >
        {(id) => (
          <Input
            id={id}
            type="number"
            inputMode="numeric"
            invalid={!!errors.completedKm}
            {...form.register('completedKm', {
              setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
            })}
          />
        )}
      </FormField>

      {isRecurringReminder(reminder) && <Text variant="muted">{t('reminder.complete_dialog.recurring_note')}</Text>}

      {mutation.error instanceof ApiError && mutation.error.title === 'validation_failed' && hasUnmapped && (
        <Alert variant="error">{errorMessage(mutation.error)}</Alert>
      )}

      <DialogFormActions
        isPending={mutation.isPending}
        onCancel={onCancel}
        submitLabel={t('reminder.mark_complete')}
      />
    </form>
  );
}
