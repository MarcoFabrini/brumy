import { useForm, Controller } from 'react-hook-form';
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
  Textarea,
} from '@/components/ui';
import { FUEL_TYPES, VEHICLE_TYPES, type FuelType, type Vehicle } from '@/api/types/vehicle';
import { VEHICLE_NOTES_MAX_LENGTH, vehicleSchema, type VehicleFormData } from '@/schemas/vehicle.schema';
import { ApiError } from '@/api/client';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { useResyncPristineForm } from '@/hooks/useResyncPristineForm';

/**
 * VehicleForm feature — form create/edit veicolo riusato in NewPage e EditPage.
 *
 * Logica: RHF + Zod resolver. Sub backend validation errors mapping a setError.
 * UI: composto da FormField + Input + Select + Button.
 *
 * @example
 * <VehicleForm
 *   defaultValues={vehicle}
 *   isPending={mutation.isPending}
 *   error={mutation.error}
 *   onSubmit={(data) => mutation.mutate(data)}
 * />
 */
export interface VehicleFormProps {
  /** Valori iniziali (edit) o vuoti (new) */
  defaultValues?: Partial<Vehicle>;
  isPending?: boolean;
  error?: unknown;
  onSubmit: (data: VehicleFormData) => void;
  /** Slot bottoni footer custom (default: Salva + Annulla) */
  submitLabel?: string;
  onCancel?: () => void;
}

const EMPTY_DEFAULTS: VehicleFormData = {
  name: '',
  brand: '',
  model: '',
  year: new Date().getFullYear(),
  type: 'car',
  fuelType: 'gasoline',
  secondaryFuelType: null,
  licensePlate: null,
  vin: null,
  initialKm: 0,
  purchasePrice: null,
  notes: null,
};

export function VehicleForm({
  defaultValues,
  isPending,
  error,
  onSubmit,
  submitLabel,
  onCancel,
}: VehicleFormProps) {
  const { t } = useTranslation();

  const initialValues = { ...EMPTY_DEFAULTS, ...defaultValues } as VehicleFormData;
  const form = useForm<VehicleFormData>({
    resolver: zodResolver(vehicleSchema),
    defaultValues: initialValues,
  });
  useResyncPristineForm(form, defaultValues, initialValues);

  function handleSubmit(data: VehicleFormData) {
    onSubmit(data);
  }

  const { hasUnmapped } = useServerFieldErrors(form, error);

  const errors = form.formState.errors;
  const tErr = (key: string | undefined) => (key ? t(`errors.${key}`, { defaultValue: key }) : undefined);

  return (
    <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-4" noValidate>
      <FormField label={t('vehicle.name')} error={tErr(errors.name?.message)} required>
        {(id) => <Input id={id} invalid={!!errors.name} {...form.register('name')} />}
      </FormField>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('vehicle.brand')} error={tErr(errors.brand?.message)} required>
          {(id) => <Input id={id} invalid={!!errors.brand} {...form.register('brand')} />}
        </FormField>
        <FormField label={t('vehicle.model')} error={tErr(errors.model?.message)} required>
          {(id) => <Input id={id} invalid={!!errors.model} {...form.register('model')} />}
        </FormField>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('vehicle.year')} error={tErr(errors.year?.message)} required>
          {(id) => (
            <Input
              id={id}
              type="number"
              inputMode="numeric"
              invalid={!!errors.year}
              {...form.register('year', { valueAsNumber: true })}
            />
          )}
        </FormField>
        <FormField label={t('vehicle.initial_km')} error={tErr(errors.initialKm?.message)} required>
          {(id) => (
            <Input
              id={id}
              type="number"
              inputMode="numeric"
              invalid={!!errors.initialKm}
              {...form.register('initialKm', { valueAsNumber: true })}
            />
          )}
        </FormField>
      </div>

      <FormField label={t('vehicle.purchase_price')} error={tErr(errors.purchasePrice?.message)}>
        {(id) => (
          <Input
            id={id}
            type="text"
            inputMode="decimal"
            placeholder="0.00"
            invalid={!!errors.purchasePrice}
            {...form.register('purchasePrice', { setValueAs: (v) => (v === '' ? null : v) })}
          />
        )}
      </FormField>

      <FormField label={t('vehicle.type')} error={tErr(errors.type?.message)} required>
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
                  {VEHICLE_TYPES.map((v) => (
                    <SelectItem key={v} value={v}>
                      {t(`vehicle.type_options.${v}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
        )}
      </FormField>

      <FormField label={t('vehicle.fuel_type')} error={tErr(errors.fuelType?.message)} required>
        {(id) => (
          <Controller
            control={form.control}
            name="fuelType"
            render={({ field }) => (
              <Select value={field.value} onValueChange={field.onChange}>
                <SelectTrigger id={id}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {FUEL_TYPES.map((f) => (
                    <SelectItem key={f} value={f}>
                      {t(`vehicle.fuel.${f}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
        )}
      </FormField>

      <FormField
        label={t('vehicle.secondary_fuel_type')}
        error={tErr(errors.secondaryFuelType?.message)}
      >
        {(id) => (
          <Controller
            control={form.control}
            name="secondaryFuelType"
            render={({ field }) => (
              <Select
                value={field.value ?? 'none'}
                onValueChange={(v) => field.onChange(v === 'none' ? null : (v as FuelType))}
              >
                <SelectTrigger id={id}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">{t('common.none')}</SelectItem>
                  {FUEL_TYPES.map((f) => (
                    <SelectItem key={f} value={f}>
                      {t(`vehicle.fuel.${f}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
        )}
      </FormField>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('vehicle.license_plate')} error={tErr(errors.licensePlate?.message)}>
          {(id) => (
            <Input
              id={id}
              invalid={!!errors.licensePlate}
              {...form.register('licensePlate', { setValueAs: (v) => (v === '' ? null : v) })}
            />
          )}
        </FormField>
        <FormField label={t('vehicle.vin')} error={tErr(errors.vin?.message)}>
          {(id) => (
            <Input
              id={id}
              invalid={!!errors.vin}
              {...form.register('vin', { setValueAs: (v) => (v === '' ? null : v) })}
            />
          )}
        </FormField>
      </div>

      <FormField label={t('vehicle.notes')} error={tErr(errors.notes?.message)}>
        {(id) => (
          <Textarea
            id={id}
            maxLength={VEHICLE_NOTES_MAX_LENGTH}
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
