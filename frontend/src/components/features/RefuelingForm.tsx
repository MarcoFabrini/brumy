import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { MapPin } from 'lucide-react';
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
  Text,
  Textarea,
} from '@/components/ui';
import { FUEL_TYPES, type FuelType } from '@/api/types/vehicle';
import type { Refueling } from '@/api/types/refueling';
import { refuelingSchema, type RefuelingFormData } from '@/schemas/refueling.schema';
import { ApiError } from '@/api/client';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { useResyncPristineForm } from '@/hooks/useResyncPristineForm';
import { useValueSuggestions } from '@/hooks/useUserValues';
import { useVehicle } from '@/hooks/useVehicles';
import { useFormat } from '@/hooks/useFormat';
import { todayIso } from '@/lib/format';
import { isRecentForFuelPrice, sameValueName } from '@/lib/fuelPrice';
import type { FuelServiceMode, FuelStation } from '@/api/types/fuelPrice';
import { fuelPriceKeys, useFuelPricesStatus, useFuelStation } from '@/hooks/useFuelPrices';
import { FuelModeToggle, FuelStationPicker } from './FuelStationPicker';
import { parseDecimal } from '@/lib/decimal';

export interface RefuelingFormProps {
  vehicleId: number;
  /** Default fuelType da Vehicle, usato per pre-compilare */
  defaultFuelType?: RefuelingFormData['fuelType'];
  /** Ultimo km noto del veicolo (da stats), usato per pre-compilare il campo km su un nuovo rifornimento */
  defaultKm?: number;
  defaultValues?: Partial<Refueling>;
  isPending?: boolean;
  error?: unknown;
  onSubmit: (data: RefuelingFormData) => void;
  submitLabel?: string;
  onCancel?: () => void;
}

export function RefuelingForm({
  vehicleId,
  defaultFuelType = 'gasoline',
  defaultKm,
  defaultValues,
  isPending,
  error,
  onSubmit,
  submitLabel,
  onCancel,
}: RefuelingFormProps) {
  const { t } = useTranslation();
  const fmt = useFormat();

  const initialValues: RefuelingFormData = {
    vehicleId,
    refueledAt: defaultValues?.refueledAt ?? todayIso(),
    km: defaultValues?.km ?? defaultKm ?? 0,
    liters: defaultValues?.liters ?? '',
    pricePerLiter: defaultValues?.pricePerLiter ?? '',
    fuelType: defaultValues?.fuelType ?? defaultFuelType,
    fullTank: defaultValues?.fullTank ?? true,
    station: defaultValues?.station ?? null,
    fuelStationId: null,
    description: defaultValues?.description ?? null,
    notes: defaultValues?.notes ?? null,
  };

  const form = useForm<RefuelingFormData>({
    resolver: zodResolver(refuelingSchema),
    defaultValues: initialValues,
  });
  useResyncPristineForm(form, defaultValues, initialValues);

  const { hasUnmapped } = useServerFieldErrors(form, error);

  // dirtyFields è dietro un Proxy: va letto in fase di render per attivare
  // la subscription, altrimenti nell'effect sotto risulta sempre stale.
  const { dirtyFields } = form.formState;

  // defaultKm arriva async (query stats separata dal mount del form): se il
  // campo km non è ancora stato toccato dall'utente e non c'è un defaultValues
  // esplicito (edit), lo precompiliamo appena disponibile.
  useEffect(() => {
    if (defaultKm !== undefined && defaultValues?.km === undefined && !dirtyFields.km) {
      form.setValue('km', defaultKm);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [defaultKm, dirtyFields.km]);

  // Computed total cost (client-side preview; backend ricalcola)
  // Solo i carburanti del veicolo (uno o due); finché il veicolo non è caricato, tutti
  const vehicle = useVehicle(vehicleId).data;
  const vehicleFuels: readonly FuelType[] = vehicle
    ? [vehicle.fuelType, ...(vehicle.secondaryFuelType ? [vehicle.secondaryFuelType] : [])]
    : FUEL_TYPES;
  const fuelType = useWatch({ control: form.control, name: 'fuelType' });
  // Un rifornimento già salvato con un carburante che il veicolo non ha più resta visibile (il server lo rifiuterà)
  const fuelOptions = vehicleFuels.includes(fuelType) ? vehicleFuels : [...vehicleFuels, fuelType];
  useEffect(() => {
    // Nuovo rifornimento con un carburante proposto che il veicolo non ha (es. benzina su un diesel): il primo del veicolo
    const first = vehicleFuels[0];
    if (vehicle && !defaultValues?.fuelType && first && !vehicleFuels.includes(form.getValues('fuelType'))) {
      form.setValue('fuelType', first);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vehicle]);

  const liters = useWatch({ control: form.control, name: 'liters' });
  const pricePerLiter = useWatch({ control: form.control, name: 'pricePerLiter' });
  const litersNum = parseDecimal(liters ?? '');
  const priceNum = parseDecimal(pricePerLiter ?? '');
  const computedTotal = Number.isNaN(litersNum) || Number.isNaN(priceNum) ? null : litersNum * priceNum;

  // Reset vehicleId if changes (rare edge — controlled prop drift)
  useEffect(() => {
    form.setValue('vehicleId', vehicleId);
  }, [vehicleId, form]);

  const stations = useValueSuggestions(vehicleId, 'station');

  // Prezzo proposto dal distributore: scelto dai vicini/dalla ricerca (fuelStationId) o voce dell'elenco collegata.
  // Solo per un rifornimento di oggi o di ieri, e mai sopra un prezzo scritto dall'utente.
  const queryClient = useQueryClient();
  const fuelPrices = useFuelPricesStatus();
  const pricesEnabled = fuelPrices.data?.enabled === true;
  const [pickerOpen, setPickerOpen] = useState(false);
  const [mode, setMode] = useState<FuelServiceMode>('self');
  /** L'ultimo prezzo messo da qui: finché il campo vale questo, l'utente non l'ha toccato e si può aggiornare. */
  const proposedPrice = useRef<string | null>(null);
  const stationName = useWatch({ control: form.control, name: 'station' });
  const pickedStationId = useWatch({ control: form.control, name: 'fuelStationId' });
  const refueledAt = useWatch({ control: form.control, name: 'refueledAt' });
  const linkedStationId =
    pickedStationId ?? stations.data?.find((s) => s.fuelStationId && sameValueName(s.name, stationName ?? ''))?.fuelStationId ?? null;
  const priceStation = useFuelStation(pricesEnabled && isRecentForFuelPrice(refueledAt) ? linkedStationId : null, fuelType);
  const proposal = priceStation.data?.prices[mode] ?? null;

  useEffect(() => {
    if (!proposal) return;
    const current = form.getValues('pricePerLiter');
    if (current === '' || current === proposedPrice.current) {
      form.setValue('pricePerLiter', proposal.price, { shouldValidate: !!form.formState.errors.pricePerLiter });
      proposedPrice.current = proposal.price;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [proposal?.price]);

  function pickStation(station: FuelStation) {
    // Il foglio ha già i prezzi: niente seconda richiesta per lo stesso distributore
    queryClient.setQueryData(fuelPriceKeys.station(station.id, fuelType), station);
    form.setValue('station', station.label, { shouldDirty: true });
    form.setValue('fuelStationId', station.id);
  }

  const errors = form.formState.errors;
  const tErr = (key: string | undefined) =>
    key ? t(`errors.${key}`, { defaultValue: key }) : undefined;

  return (
    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4" noValidate>
      <input type="hidden" {...form.register('vehicleId', { valueAsNumber: true })} />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('refueling.refueled_at')} error={tErr(errors.refueledAt?.message)} required>
          {(id) => <Input id={id} type="date" invalid={!!errors.refueledAt} {...form.register('refueledAt')} />}
        </FormField>
        <FormField label={t('refueling.km')} error={tErr(errors.km?.message)} required>
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

      <FormField label={t('refueling.fuel_type')} error={tErr(errors.fuelType?.message)} required>
        {(id) => (
          <Controller
            control={form.control}
            name="fuelType"
            render={({ field }) =>
              fuelOptions.length === 1 ? (
                // Veicolo a un solo carburante: già impostato, niente da scegliere
                <Input id={id} readOnly value={t(`vehicle.fuel.${field.value}`)} />
              ) : (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id={id}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {fuelOptions.map((f) => (
                      <SelectItem key={f} value={f}>
                        {t(`vehicle.fuel.${f}`)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )
            }
          />
        )}
      </FormField>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t('refueling.liters')} error={tErr(errors.liters?.message)} required>
          {(id) => (
            <Input
              id={id}
              type="text"
              inputMode="decimal"
              placeholder="0.00"
              invalid={!!errors.liters}
              {...form.register('liters')}
            />
          )}
        </FormField>
        <FormField
          label={t('refueling.price_per_liter')}
          error={tErr(errors.pricePerLiter?.message)}
          required
        >
          {(id) => (
            <Input
              id={id}
              type="text"
              inputMode="decimal"
              placeholder="0.000"
              invalid={!!errors.pricePerLiter}
              {...form.register('pricePerLiter')}
            />
          )}
        </FormField>
      </div>

      {priceStation.data && (
        <div className="flex items-center justify-between gap-3">
          <Text variant="muted" className="text-xs">
            {proposal
              ? t('fuel_prices.proposed', { date: fmt.date(proposal.communicatedAt) })
              : t('fuel_prices.no_price_for_fuel')}
          </Text>
          <FuelModeToggle mode={mode} onChange={setMode} compact />
        </div>
      )}

      {computedTotal !== null && (
        <Text variant="muted" className="text-right">
          {t('refueling.total_cost')}: <span className="font-semibold text-foreground">{fmt.currency(computedTotal)}</span>
        </Text>
      )}

      <FormField label={t('refueling.station')} error={tErr(errors.station?.message)}>
        {(id) => (
          <Controller
            control={form.control}
            name="station"
            render={({ field }) => (
              <SuggestInput
                ref={field.ref}
                id={id}
                value={field.value ?? ''}
                onChange={(v) => {
                  field.onChange(v === '' ? null : v);
                  // Scritto a mano: non è più il distributore scelto dalla lista dei prezzi
                  form.setValue('fuelStationId', null);
                }}
                onBlur={field.onBlur}
                suggestions={(stations.data ?? []).map((s) => s.name)}
                invalid={!!errors.station}
                maxLength={200}
              />
            )}
          />
        )}
      </FormField>

      {pricesEnabled && (
        <>
          <Button type="button" variant="outline" size="sm" className="w-full" onClick={() => setPickerOpen(true)}>
            <MapPin aria-hidden="true" className="size-4" />
            {t('fuel_prices.open_picker')}
          </Button>
          <FuelStationPicker
            open={pickerOpen}
            onOpenChange={setPickerOpen}
            onSelect={pickStation}
            fuel={fuelType}
            mode={mode}
            onModeChange={setMode}
          />
        </>
      )}

      {/* Interruttore iOS in una cella, come i campi: tocca la riga o l'interruttore. */}
      <Controller
        control={form.control}
        name="fullTank"
        render={({ field }) => (
          <SwitchCell label={t('refueling.full_tank')} checked={field.value} onCheckedChange={field.onChange} />
        )}
      />

      <FormField label={t('refueling.description')} error={tErr(errors.description?.message)}>
        {(id) => (
          <Input
            id={id}
            maxLength={500}
            invalid={!!errors.description}
            {...form.register('description', { setValueAs: (v) => (v === '' ? null : v) })}
          />
        )}
      </FormField>

      <FormField label={t('refueling.notes')} error={tErr(errors.notes?.message)}>
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
