import { Controller, useForm, useWatch } from 'react-hook-form';
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
  SuggestInput,
  SwitchCell,
  Textarea,
} from '@/components/ui';
import {
  RECURRING_PERIODS,
  type Expense,
  type RecurringPeriod,
  type ExpenseSection,
} from '@/api/types/expense';
import { NextDueReminder } from './NextDueReminder';
import { expenseSchema, type ExpenseFormData } from '@/schemas/expense.schema';
import { ApiError } from '@/api/client';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { useResyncPristineForm } from '@/hooks/useResyncPristineForm';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { useValueSuggestions } from '@/hooks/useUserValues';
import { addMonthsIso, todayIso } from '@/lib/format';

/** Mesi di ogni periodo di ripetizione: la scadenza proposta per il promemoria (settimanale → un anno). */
const PERIOD_MONTHS: Record<RecurringPeriod, number> = { weekly: 12, monthly: 1, quarterly: 3, semiannual: 6, yearly: 12, biennial: 24 };
/** Tipi di tassa periodici (voci di partenza bollo e superbollo, anche se rinominate). */
const PERIODIC_TAX_TYPES = ['road_tax', 'super_tax'];

export interface ExpenseFormProps {
  vehicleId: number;
  /** Tasse (tipo di tassa), assicurazione (compagnia facoltativa) o altre spese (categoria). */
  section: ExpenseSection;
  defaultValues?: Partial<Expense>;
  isPending?: boolean;
  error?: unknown;
  onSubmit: (data: ExpenseFormData) => void;
  submitLabel?: string;
  onCancel?: () => void;
}

export function ExpenseForm({
  vehicleId,
  section,
  defaultValues,
  isPending,
  error,
  onSubmit,
  submitLabel,
  onCancel,
}: ExpenseFormProps) {
  const { t } = useTranslation();

  const initialValues: ExpenseFormData = {
    vehicleId,
    occurredAt: defaultValues?.occurredAt ?? todayIso(),
    section,
    category: defaultValues?.category ?? '',
    insurer: defaultValues?.insurer ?? null,
    description: defaultValues?.description ?? '',
    amount: defaultValues?.amount ?? '',
    recurring: defaultValues?.recurring ?? false,
    recurringPeriod: defaultValues?.recurringPeriod ?? null,
    recurringUntil: defaultValues?.recurringUntil ?? null,
    notes: defaultValues?.notes ?? null,
    // '' = la data proposta, che NextDueReminder imposta appena montato; null = nessun promemoria
    nextDueDate: '',
  };

  const form = useForm<ExpenseFormData>({
    resolver: zodResolver(expenseSchema),
    defaultValues: initialValues,
  });
  useResyncPristineForm(form, defaultValues, initialValues);

  const { hasUnmapped } = useServerFieldErrors(form, error);

  const recurring = useWatch({ control: form.control, name: 'recurring' });
  const [occurredAt, category, recurringPeriod] = useWatch({ control: form.control, name: ['occurredAt', 'category', 'recurringPeriod'] });
  // Per l'assicurazione la query delle categorie non serve: la sua lista è quella delle compagnie
  const categories = useValueSuggestions(section === 'insurance' ? 0 : vehicleId, section === 'tax' ? 'tax_type' : 'expense_category');
  const insurers = useValueSuggestions(section === 'insurance' ? vehicleId : 0, 'insurer');
  const errors = form.formState.errors;
  const tErr = useFieldErrorMessage();

  // Creando un'assicurazione o una tassa periodica si propone il promemoria della prossima scadenza
  const typed = category.trim().toLowerCase();
  const periodicTax = section === 'tax'
    && (categories.data ?? []).some((c) => c.name.toLowerCase() === typed && PERIODIC_TAX_TYPES.includes(c.starterKey ?? ''));
  const offersReminder = defaultValues?.id === undefined && (section === 'insurance' || periodicTax);
  const reminderMonths = section === 'insurance' && recurring && recurringPeriod ? PERIOD_MONTHS[recurringPeriod] : 12;
  const submit = (data: ExpenseFormData) =>
    onSubmit({ ...data, nextDueDate: offersReminder && data.nextDueDate ? data.nextDueDate : null });

  return (
    <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
      <input type="hidden" {...form.register('vehicleId', { valueAsNumber: true })} />

      {section === 'insurance' ? (
        <FormField label={t('expense.insurer')} error={tErr(errors.insurer?.message)}>
          {(id) => (
            <Controller
              control={form.control}
              name="insurer"
              render={({ field }) => (
                <SuggestInput
                  ref={field.ref}
                  id={id}
                  value={field.value ?? ''}
                  onChange={(v) => field.onChange(v === '' ? null : v)}
                  onBlur={field.onBlur}
                  suggestions={(insurers.data ?? []).map((c) => c.name)}
                  invalid={!!errors.insurer}
                  maxLength={200}
                />
              )}
            />
          )}
        </FormField>
      ) : (
        <FormField label={t(section === 'tax' ? 'expense.tax_type' : 'expense.category')} error={tErr(errors.category?.message)} required>
          {(id) => (
            <Controller
              control={form.control}
              name="category"
              render={({ field }) => (
                <SuggestInput
                  ref={field.ref}
                  id={id}
                  value={field.value}
                  onChange={field.onChange}
                  onBlur={field.onBlur}
                  suggestions={(categories.data ?? []).map((c) => c.name)}
                  invalid={!!errors.category}
                  maxLength={200}
                />
              )}
            />
          )}
        </FormField>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('expense.occurred_at')} error={tErr(errors.occurredAt?.message)} required>
          {(id) => <Input id={id} type="date" invalid={!!errors.occurredAt} {...form.register('occurredAt')} />}
        </FormField>
        <FormField label={t('expense.amount')} error={tErr(errors.amount?.message)} required>
          {(id) => (
            <Input
              id={id}
              type="text"
              inputMode="decimal"
              placeholder="0.00"
              invalid={!!errors.amount}
              {...form.register('amount')}
            />
          )}
        </FormField>
      </div>

      <FormField label={t('expense.description')} error={tErr(errors.description?.message)} required>
        {(id) => <Input id={id} invalid={!!errors.description} {...form.register('description')} />}
      </FormField>

      <Controller
        control={form.control}
        name="recurring"
        render={({ field }) => (
          <SwitchCell label={t('expense.recurring')} checked={field.value} onCheckedChange={field.onChange} />
        )}
      />

      {recurring && (
        <>
          <FormField
            label={t('expense.recurring_period')}
            error={tErr(errors.recurringPeriod?.message)}
            required
          >
            {(id) => (
              <Controller
                control={form.control}
                name="recurringPeriod"
                render={({ field }) => (
                  <Select value={field.value ?? ''} onValueChange={field.onChange}>
                    <SelectTrigger id={id}>
                      <SelectValue placeholder={t('expense.recurring_period')} />
                    </SelectTrigger>
                    <SelectContent>
                      {RECURRING_PERIODS.map((p) => (
                        <SelectItem key={p} value={p}>
                          {t(`expense.recurring_options.${p}`)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            )}
          </FormField>

          <FormField
            label={t('expense.recurring_until')}
            error={tErr(errors.recurringUntil?.message)}
          >
            {(id) => (
              <Input
                id={id}
                type="date"
                invalid={!!errors.recurringUntil}
                {...form.register('recurringUntil', { setValueAs: (v) => (v === '' ? null : v) })}
              />
            )}
          </FormField>
        </>
      )}

      <FormField label={t('expense.notes')} error={tErr(errors.notes?.message)}>
        {(id) => (
          <Textarea
            id={id}
            maxLength={2000}
            invalid={!!errors.notes}
            {...form.register('notes', { setValueAs: (v) => (v === '' ? null : v) })}
          />
        )}
      </FormField>

      {offersReminder && (
        <NextDueReminder control={form.control} name="nextDueDate" proposedDate={addMonthsIso(occurredAt, reminderMonths)} />
      )}

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
