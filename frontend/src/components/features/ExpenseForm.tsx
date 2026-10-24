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
  SwitchCell,
  Textarea,
} from '@/components/ui';
import {
  EXPENSE_CATEGORIES,
  RECURRING_PERIODS,
  type Expense,
} from '@/api/types/expense';
import { expenseSchema, type ExpenseFormData } from '@/schemas/expense.schema';
import { ApiError } from '@/api/client';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { useResyncPristineForm } from '@/hooks/useResyncPristineForm';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { todayIso } from '@/lib/format';

export interface ExpenseFormProps {
  vehicleId: number;
  defaultValues?: Partial<Expense>;
  isPending?: boolean;
  error?: unknown;
  onSubmit: (data: ExpenseFormData) => void;
  submitLabel?: string;
  onCancel?: () => void;
}

export function ExpenseForm({
  vehicleId,
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
    category: defaultValues?.category ?? 'other',
    description: defaultValues?.description ?? '',
    amount: defaultValues?.amount ?? '',
    recurring: defaultValues?.recurring ?? false,
    recurringPeriod: defaultValues?.recurringPeriod ?? null,
    recurringUntil: defaultValues?.recurringUntil ?? null,
    notes: defaultValues?.notes ?? null,
  };

  const form = useForm<ExpenseFormData>({
    resolver: zodResolver(expenseSchema),
    defaultValues: initialValues,
  });
  useResyncPristineForm(form, defaultValues, initialValues);

  const { hasUnmapped } = useServerFieldErrors(form, error);

  const recurring = useWatch({ control: form.control, name: 'recurring' });
  const errors = form.formState.errors;
  const tErr = useFieldErrorMessage();

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4" noValidate>
      <input type="hidden" {...form.register('vehicleId', { valueAsNumber: true })} />

      <FormField label={t('expense.category')} error={tErr(errors.category?.message)} required>
        {(id) => (
          <Controller
            control={form.control}
            name="category"
            render={({ field }) => (
              <Select value={field.value} onValueChange={field.onChange}>
                <SelectTrigger id={id}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {EXPENSE_CATEGORIES.map((c) => (
                    <SelectItem key={c} value={c}>
                      {t(`expense.category_options.${c}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
        )}
      </FormField>

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
            hint={t('expense.recurring_first_charge_hint')}
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
            hint={t('expense.recurring_until_hint')}
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

      <FormField label={t('expense.notes')} error={tErr(errors.notes?.message)} hint={t('common.optional')}>
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
