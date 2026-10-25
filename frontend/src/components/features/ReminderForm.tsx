import { useId, useState, type Ref } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import {
  Alert,
  Button,
  FormField,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Text,
  Textarea,
} from '@/components/ui';
import {
  DEFAULT_NOTIFY_KM_BEFORE,
  MONTHS_PER_UNIT,
  RECURRENCE_UNITS,
  REMINDER_TYPES,
  recurrenceInterval,
  type RecurrenceUnit,
  type Reminder,
} from '@/api/types/reminder';
import { reminderSchema, type ReminderFormData } from '@/schemas/reminder.schema';
import { ApiError } from '@/api/client';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { useResyncPristineForm } from '@/hooks/useResyncPristineForm';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';

export interface ReminderFormProps {
  vehicleId: number;
  defaultValues?: Partial<Reminder>;
  isPending?: boolean;
  error?: unknown;
  onSubmit: (data: ReminderFormData) => void;
  submitLabel?: string;
  onCancel?: () => void;
}

/** Input numerico: vuoto → null (il campo è facoltativo). */
const optionalNumber = (v: unknown) => (v === '' || v == null ? null : Number(v));

const isRecurrenceUnit = (v: string): v is RecurrenceUnit => (RECURRENCE_UNITS as readonly string[]).includes(v);

export function ReminderForm({
  vehicleId,
  defaultValues,
  isPending,
  error,
  onSubmit,
  submitLabel,
  onCancel,
}: ReminderFormProps) {
  const { t } = useTranslation();

  const initialValues: ReminderFormData = {
    vehicleId,
    type: defaultValues?.type ?? 'custom',
    description: defaultValues?.description ?? '',
    notes: defaultValues?.notes ?? null,
    dueDate: defaultValues?.dueDate ?? null,
    dueKm: defaultValues?.dueKm ?? null,
    notifyDaysBefore: defaultValues?.notifyDaysBefore ?? 30,
    notifyKmBefore: defaultValues?.notifyKmBefore ?? DEFAULT_NOTIFY_KM_BEFORE,
    recurrenceMonths: defaultValues?.recurrenceMonths ?? null,
    recurrenceKm: defaultValues?.recurrenceKm ?? null,
  };

  const form = useForm<ReminderFormData>({
    resolver: zodResolver(reminderSchema),
    defaultValues: initialValues,
  });
  useResyncPristineForm(form, defaultValues, initialValues);

  const { hasUnmapped } = useServerFieldErrors(form, error);

  const errors = form.formState.errors;
  const tErr = useFieldErrorMessage();

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4" noValidate>
      <input type="hidden" {...form.register('vehicleId', { valueAsNumber: true })} />

      <FormField label={t('reminder.type')} error={tErr(errors.type?.message)} required>
        {(id) => (
          <Controller
            control={form.control}
            name="type"
            render={({ field }) => (
              <Select value={field.value} onValueChange={field.onChange}>
                <SelectTrigger id={id}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {REMINDER_TYPES.map((v) => (
                    <SelectItem key={v} value={v}>
                      {t(`reminder.type_options.${v}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
        )}
      </FormField>

      <FormField label={t('reminder.description')} error={tErr(errors.description?.message)} required>
        {(id) => <Input id={id} invalid={!!errors.description} {...form.register('description')} />}
      </FormField>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField
          label={t('reminder.due_date')}
          error={tErr(errors.dueDate?.message)}
        >
          {(id) => (
            <Input
              id={id}
              type="date"
              invalid={!!errors.dueDate}
              {...form.register('dueDate', { setValueAs: (v) => (v === '' ? null : v) })}
            />
          )}
        </FormField>
        <FormField label={t('reminder.due_km')} error={tErr(errors.dueKm?.message)}>
          {(id) => (
            <Input
              id={id}
              type="number"
              inputMode="numeric"
              invalid={!!errors.dueKm}
              {...form.register('dueKm', { setValueAs: optionalNumber })}
            />
          )}
        </FormField>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField
          label={t('reminder.notify_days_before')}
          error={tErr(errors.notifyDaysBefore?.message)}
          required
        >
          {(id) => (
            <Input
              id={id}
              type="number"
              inputMode="numeric"
              invalid={!!errors.notifyDaysBefore}
              {...form.register('notifyDaysBefore', { valueAsNumber: true })}
            />
          )}
        </FormField>
        <FormField
          label={t('reminder.notify_km_before')}
          error={tErr(errors.notifyKmBefore?.message)}
          required
        >
          {(id) => (
            <Input
              id={id}
              type="number"
              inputMode="numeric"
              invalid={!!errors.notifyKmBefore}
              {...form.register('notifyKmBefore', { valueAsNumber: true })}
            />
          )}
        </FormField>
      </div>

      <fieldset className="space-y-3 rounded-lg border p-3">
        <legend className="px-1 text-sm font-semibold">{t('reminder.recurrence')}</legend>
        <Text variant="muted">{t('reminder.recurrence_hint')}</Text>

        <Controller
          control={form.control}
          name="recurrenceMonths"
          render={({ field }) => (
            <RecurrenceMonthsFields
              months={field.value ?? null}
              onChange={field.onChange}
              onBlur={field.onBlur}
              inputRef={field.ref}
              error={tErr(errors.recurrenceMonths?.message)}
            />
          )}
        />

        <FormField
          label={t('reminder.recurrence_km')}
          error={tErr(errors.recurrenceKm?.message)}
        >
          {(id) => (
            <Input
              id={id}
              type="number"
              inputMode="numeric"
              invalid={!!errors.recurrenceKm}
              {...form.register('recurrenceKm', { setValueAs: optionalNumber })}
            />
          )}
        </FormField>
      </fieldset>

      <FormField label={t('reminder.notes')} error={tErr(errors.notes?.message)}>
        {(id) => (
          <Textarea
            id={id}
            maxLength={2000}
            invalid={!!errors.notes}
            {...form.register('notes', { setValueAs: (v) => (v === '' ? null : v) })}
          />
        )}
      </FormField>

      {error instanceof ApiError && (error.title !== 'validation_failed' || hasUnmapped) && (
        <Alert variant="error">
          {t(`errors.${error.title}`, { defaultValue: error.detail ?? error.title })}
        </Alert>
      )}

      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        {onCancel && (
          <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
            {t('actions.cancel')}
          </Button>
        )}
        <Button type="submit" disabled={isPending}>
          {isPending ? <Spinner size="sm" /> : (submitLabel ?? t('actions.save'))}
        </Button>
      </div>
    </form>
  );
}

interface RecurrenceMonthsFieldsProps {
  /** Valore del form, sempre in mesi (come il DTO); null = nessuna ricorrenza a tempo. */
  months: number | null;
  onChange: (months: number | null) => void;
  onBlur: () => void;
  inputRef: Ref<HTMLInputElement>;
  error?: string;
}

/**
 * "Ripeti ogni" N mesi/anni: si ragiona in anni ("ogni 2 anni") ma si salva in mesi. L'unità scelta è
 * solo presentazione (stato locale); il valore del form resta in mesi, così il limite 1–120 e gli errori
 * del backend su `recurrenceMonths` valgono sul valore convertito e compaiono sotto questo campo.
 */
function RecurrenceMonthsFields({ months, onChange, onBlur, inputRef, error }: RecurrenceMonthsFieldsProps) {
  const { t } = useTranslation();
  const hintId = useId();
  const [preferredUnit, setPreferredUnit] = useState<RecurrenceUnit>(() =>
    months != null ? recurrenceInterval(months).unit : 'years',
  );
  // Un valore che non è un numero intero di anni (es. dati riallineati dal server) si mostra in mesi.
  const unit: RecurrenceUnit = months != null && months % MONTHS_PER_UNIT.years !== 0 ? 'months' : preferredUnit;
  const count = months == null ? '' : months / MONTHS_PER_UNIT[unit];

  const changeUnit = (next: string) => {
    if (!isRecurrenceUnit(next)) return;
    setPreferredUnit(next);
    // Il numero scritto resta, cambia ciò che significa: "2" da mesi ad anni diventa 24 mesi.
    if (count !== '') onChange(count * MONTHS_PER_UNIT[next]);
  };

  return (
    <div className="grid grid-cols-2 gap-x-3 gap-y-2">
      <FormField label={t('reminder.recurrence_every')} error={error}>
        {(id) => (
          <Input
            id={id}
            ref={inputRef}
            type="number"
            inputMode="numeric"
            min={1}
            step={1}
            invalid={!!error}
            value={count}
            // Con un errore il campo è descritto dal messaggio di errore (FormField), altrimenti dal suggerimento sotto.
            aria-describedby={error ? undefined : hintId}
            onBlur={onBlur}
            onChange={(e) => {
              const n = optionalNumber(e.target.value);
              // Solo interi: 1,5 anni diventerebbe 18 mesi e l'unità cambierebbe mentre si scrive.
              onChange(n == null ? null : Math.trunc(n) * MONTHS_PER_UNIT[unit]);
            }}
          />
        )}
      </FormField>
      <FormField label={t('reminder.recurrence_unit')}>
        {(id) => (
          <Select value={unit} onValueChange={changeUnit}>
            <SelectTrigger id={id}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {RECURRENCE_UNITS.map((u) => (
                <SelectItem key={u} value={u}>
                  {t(`reminder.recurrence_units.${u}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </FormField>
      {/* Il suggerimento occupa tutta la riga: in mezza colonna, su 320px, andava a capo su quattro righe. */}
      <p id={hintId} className="col-span-2 text-sm text-muted-foreground">
        {t('reminder.recurrence_every_hint')}
      </p>
    </div>
  );
}
