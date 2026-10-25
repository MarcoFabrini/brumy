import { useEffect } from 'react';
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
  Textarea,
} from '@/components/ui';
import {
  MAINTENANCE_TYPES,
  type Maintenance,
  type MaintenanceCategory,
} from '@/api/types/maintenance';
import { maintenanceSchema, type MaintenanceFormData } from '@/schemas/maintenance.schema';
import { ApiError } from '@/api/client';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { useValueSuggestions } from '@/hooks/useUserValues';
import { useComponents } from '@/hooks/useComponents';
import { nameKey } from '@/lib/componentNames';
import { ComponentsInput } from './ComponentsInput';
import { NextDueReminder } from './NextDueReminder';
import { useResyncPristineForm } from '@/hooks/useResyncPristineForm';
import { addMonthsIso, todayIso } from '@/lib/format';

export interface MaintenanceFormProps {
  vehicleId: number;
  /** Sezione: lavoro ordinario, imprevisto o revisione. Decide la categoria; la revisione non ha il tipo. */
  category: MaintenanceCategory;
  /** Ultimo km noto del veicolo (da stats), usato per pre-compilare il campo km su una nuova manutenzione */
  defaultKm?: number;
  defaultValues?: Partial<Maintenance>;
  isPending?: boolean;
  error?: unknown;
  /** `newComponents`: nomi scritti che il veicolo non aveva, da aprire dopo il salvataggio per completarli. */
  onSubmit: (data: MaintenanceFormData, extra: { newComponents: string[] }) => void;
  submitLabel?: string;
  onCancel?: () => void;
}

/** Mesi tra una revisione e la successiva: la data proposta per il promemoria (come `RecordReminders` sul server). */
const INSPECTION_MONTHS = 24;

/** Tipi offerti nei lavori: la revisione è una sezione a sé. */
const WORK_TYPES = MAINTENANCE_TYPES.filter((type) => type !== 'inspection');

export function MaintenanceForm({
  vehicleId,
  category,
  defaultKm,
  defaultValues,
  isPending,
  error,
  onSubmit,
  submitLabel,
  onCancel,
}: MaintenanceFormProps) {
  const { t } = useTranslation();

  const initialValues: MaintenanceFormData = {
    vehicleId,
    performedAt: defaultValues?.performedAt ?? todayIso(),
    km: defaultValues?.km ?? defaultKm ?? 0,
    type: category === 'inspection' ? 'inspection' : (defaultValues?.type ?? 'oil_change'),
    category,
    description: defaultValues?.description ?? '',
    cost: defaultValues?.cost ?? null,
    workshop: defaultValues?.workshop ?? null,
    shop: defaultValues?.shop ?? null,
    components: defaultValues?.components?.map((c) => c.name) ?? [],
    notes: defaultValues?.notes ?? null,
    // '' = la data proposta, che NextDueReminder imposta appena montato; null = nessun promemoria
    nextDueDate: '',
  };

  const form = useForm<MaintenanceFormData>({
    resolver: zodResolver(maintenanceSchema),
    defaultValues: initialValues,
  });
  useResyncPristineForm(form, defaultValues, initialValues);

  const { hasUnmapped } = useServerFieldErrors(form, error);

  // dirtyFields è dietro un Proxy: va letto in fase di render per attivare
  // la subscription, altrimenti nell'effect sotto risulta sempre stale.
  const { errors, dirtyFields } = form.formState;
  const workshops = useValueSuggestions(vehicleId, 'workshop');
  const shops = useValueSuggestions(vehicleId, 'shop');
  const components = useComponents(vehicleId);
  const componentNames = (components.data ?? []).map((c) => c.name);

  // Solo creando una revisione si propone il promemoria della prossima
  const offersReminder = category === 'inspection' && defaultValues?.id === undefined;
  const performedAt = useWatch({ control: form.control, name: 'performedAt' });

  const submit = (values: MaintenanceFormData) => {
    const data = { ...values, nextDueDate: offersReminder && values.nextDueDate ? values.nextDueDate : null };
    // Già del lavoro (anche se eliminati dopo) o del veicolo: non sono nuovi
    const known = new Set([...componentNames, ...initialValues.components].map(nameKey));
    onSubmit(data, { newComponents: data.components.filter((name) => !known.has(nameKey(name))) });
  };

  // defaultKm arriva async (query stats separata dal mount del form): se il
  // campo km non è ancora stato toccato dall'utente e non c'è un defaultValues
  // esplicito (edit), lo precompiliamo appena disponibile.
  useEffect(() => {
    if (defaultKm !== undefined && defaultValues?.km === undefined && !dirtyFields.km) {
      form.setValue('km', defaultKm);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [defaultKm, dirtyFields.km]);
  const tErr = (key: string | undefined) =>
    key ? t(`errors.${key}`, { defaultValue: key }) : undefined;

  return (
    <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
      <input type="hidden" {...form.register('vehicleId', { valueAsNumber: true })} />

      {category !== 'inspection' && (
        <FormField label={t('maintenance.type')} error={tErr(errors.type?.message)} required>
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
                    {WORK_TYPES.map((v) => (
                      <SelectItem key={v} value={v}>
                        {t(`maintenance.type_options.${v}`)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          )}
        </FormField>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('maintenance.performed_at')} error={tErr(errors.performedAt?.message)} required>
          {(id) => <Input id={id} type="date" invalid={!!errors.performedAt} {...form.register('performedAt')} />}
        </FormField>
        <FormField label={t('maintenance.km')} error={tErr(errors.km?.message)} required>
          {(id) => (
            <Input
              id={id}
              type="number"
              inputMode="numeric"
              invalid={!!errors.km}
              {...form.register('km', { valueAsNumber: true })}
            />
          )}
        </FormField>
      </div>

      <FormField label={t('maintenance.description')} error={tErr(errors.description?.message)} required>
        {(id) => <Input id={id} invalid={!!errors.description} {...form.register('description')} />}
      </FormField>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('maintenance.cost')} error={tErr(errors.cost?.message)}>
          {(id) => (
            <Input
              id={id}
              type="text"
              inputMode="decimal"
              placeholder="0.00"
              invalid={!!errors.cost}
              {...form.register('cost', { setValueAs: (v) => (v === '' ? null : v) })}
            />
          )}
        </FormField>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('maintenance.workshop')} error={tErr(errors.workshop?.message)}>
          {(id) => (
            <Controller
              control={form.control}
              name="workshop"
              render={({ field }) => (
                <SuggestInput
                  ref={field.ref}
                  id={id}
                  value={field.value ?? ''}
                  onChange={(v) => field.onChange(v === '' ? null : v)}
                  onBlur={field.onBlur}
                  suggestions={(workshops.data ?? []).map((w) => w.name)}
                  invalid={!!errors.workshop}
                  maxLength={200}
                />
              )}
            />
          )}
        </FormField>
        <FormField label={t('maintenance.shop')} error={tErr(errors.shop?.message)}>
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
      </div>

      <FormField label={t('maintenance.components')} error={tErr(errors.components?.message)}>
        {(id) => (
          <Controller
            control={form.control}
            name="components"
            render={({ field }) => (
              <ComponentsInput
                id={id}
                value={field.value}
                onChange={field.onChange}
                suggestions={componentNames}
                invalid={!!errors.components}
              />
            )}
          />
        )}
      </FormField>

      <FormField label={t('maintenance.notes')} error={tErr(errors.notes?.message)}>
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
        <NextDueReminder control={form.control} name="nextDueDate" proposedDate={addMonthsIso(performedAt, INSPECTION_MONTHS)} />
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
