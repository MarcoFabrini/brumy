import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import { Alert, Button, FormField, Input, Spinner, SuggestInput, Textarea } from '@/components/ui';
import type { ComponentDetail } from '@/api/types/component';
import { componentSchema, type ComponentFormData } from '@/schemas/component.schema';
import { ApiError } from '@/api/client';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { useResyncPristineForm } from '@/hooks/useResyncPristineForm';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { useValueSuggestions } from '@/hooks/useUserValues';

export interface ComponentFormProps {
  vehicleId: number;
  defaultValues?: Partial<ComponentDetail>;
  isPending?: boolean;
  error?: unknown;
  onSubmit: (data: ComponentFormData) => void;
  submitLabel?: string;
  onCancel?: () => void;
}

const blankToNull = (v: unknown) => (v === '' ? null : v);

/** Scheda di un componente: nome, modello/codice, numero di serie, negozio, acquisto e note. */
export function ComponentForm({ vehicleId, defaultValues, isPending, error, onSubmit, submitLabel, onCancel }: ComponentFormProps) {
  const { t } = useTranslation();

  const initialValues: ComponentFormData = {
    vehicleId,
    name: defaultValues?.name ?? '',
    code: defaultValues?.code ?? null,
    serialNumber: defaultValues?.serialNumber ?? null,
    shop: defaultValues?.shop ?? null,
    purchasedAt: defaultValues?.purchasedAt ?? null,
    price: defaultValues?.price ?? null,
    notes: defaultValues?.notes ?? null,
  };

  const form = useForm<ComponentFormData>({
    resolver: zodResolver(componentSchema),
    defaultValues: initialValues,
  });
  useResyncPristineForm(form, defaultValues, initialValues);

  const { hasUnmapped } = useServerFieldErrors(form, error);
  const shops = useValueSuggestions(vehicleId, 'shop');
  const errors = form.formState.errors;
  const tErr = useFieldErrorMessage();

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4" noValidate>
      <input type="hidden" {...form.register('vehicleId', { valueAsNumber: true })} />

      <FormField label={t('component.name')} error={tErr(errors.name?.message)} required>
        {(id) => <Input id={id} maxLength={200} invalid={!!errors.name} {...form.register('name')} />}
      </FormField>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('component.code')} error={tErr(errors.code?.message)}>
          {(id) => <Input id={id} maxLength={200} invalid={!!errors.code} {...form.register('code', { setValueAs: blankToNull })} />}
        </FormField>
        <FormField label={t('component.serial_number')} error={tErr(errors.serialNumber?.message)}>
          {(id) => (
            <Input id={id} maxLength={200} invalid={!!errors.serialNumber} {...form.register('serialNumber', { setValueAs: blankToNull })} />
          )}
        </FormField>
      </div>

      <FormField label={t('component.shop')} error={tErr(errors.shop?.message)}>
        {(id) => (
          <Controller
            control={form.control}
            name="shop"
            render={({ field }) => (
              <SuggestInput
                ref={field.ref}
                id={id}
                value={field.value ?? ''}
                onChange={(v) => field.onChange(v === '' ? null : v)}
                onBlur={field.onBlur}
                suggestions={(shops.data ?? []).map((s) => s.name)}
                invalid={!!errors.shop}
                maxLength={200}
              />
            )}
          />
        )}
      </FormField>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('component.purchased_at')} error={tErr(errors.purchasedAt?.message)}>
          {(id) => (
            <Input id={id} type="date" invalid={!!errors.purchasedAt} {...form.register('purchasedAt', { setValueAs: blankToNull })} />
          )}
        </FormField>
        <FormField label={t('component.price')} error={tErr(errors.price?.message)}>
          {(id) => (
            <Input
              id={id}
              type="text"
              inputMode="decimal"
              placeholder="0.00"
              invalid={!!errors.price}
              {...form.register('price', { setValueAs: blankToNull })}
            />
          )}
        </FormField>
      </div>

      <FormField label={t('component.notes')} error={tErr(errors.notes?.message)}>
        {(id) => (
          <Textarea id={id} maxLength={5000} invalid={!!errors.notes} {...form.register('notes', { setValueAs: blankToNull })} />
        )}
      </FormField>

      {error instanceof ApiError && (error.title !== 'validation_failed' || hasUnmapped) && (
        <Alert variant="error">{t(`errors.${error.title}`, { defaultValue: error.detail ?? error.title })}</Alert>
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
