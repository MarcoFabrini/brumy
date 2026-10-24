import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  ListRow,
  ListSection,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui';
import { RefuelingForm } from './RefuelingForm';
import { MaintenanceForm } from './MaintenanceForm';
import { ExpenseForm } from './ExpenseForm';
import { ReminderForm } from './ReminderForm';
import { VehicleUsableGate } from './VehicleUsableGate';
import { VEHICLE_RECORD_ACTIONS, type VehicleRecordKind } from './vehicleRecordActions';
import { useCreateRefueling, useRefuelings } from '@/hooks/useRefuelings';
import { useCreateMaintenance } from '@/hooks/useMaintenances';
import { useCreateExpense } from '@/hooks/useExpenses';
import { useCreateReminder } from '@/hooks/useReminders';
import { useVehicleStats } from '@/hooks/useVehicles';
import { useToast } from '@/hooks/useToast';
import { useQuickAddStore } from '@/stores/useQuickAddStore';
import type { Vehicle } from '@/api/types/vehicle';

type QuickAddVehicle = Pick<Vehicle, 'id' | 'name' | 'fuelType'>;

export interface QuickAddDialogProps {
  /** Cosa si sta registrando; null = foglio chiuso. */
  kind: VehicleRecordKind | null;
  onClose: () => void;
  /** Veicoli fra cui scegliere (propri e attivi), già nell'ordine da mostrare. */
  vehicles: QuickAddVehicle[];
  /** Veicolo già scelto all'apertura (vedi `pickQuickAddVehicle`). */
  defaultVehicleId: number;
}

/**
 * Il modulo del "+" in un foglio iOS: si apre già sul veicolo giusto (cambiabile in cima se ce n'è più d'uno),
 * salva e si chiude lasciandoti dove eri, con un toast. Niente pagina di scelta del veicolo, niente pagina di
 * dettaglio dopo il salvataggio: rifornimento = "+" → "Rifornimento" → numeri → Salva.
 */
export function QuickAddDialog({ kind, onClose, vehicles, defaultVehicleId }: QuickAddDialogProps) {
  const { t } = useTranslation();
  const action = VEHICLE_RECORD_ACTIONS.find((a) => a.kind === kind);

  return (
    <Dialog open={kind !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent mobileFullScreen>
        <DialogHeader>
          <DialogTitle>{action ? t(action.labelKey) : ''}</DialogTitle>
          <DialogDescription className="sr-only">{t('quick_add.form_description')}</DialogDescription>
        </DialogHeader>
        {/* Rimontato a ogni apertura: veicolo di partenza e valori del modulo sempre freschi. */}
        {kind && <QuickAddBody kind={kind} vehicles={vehicles} defaultVehicleId={defaultVehicleId} onDone={onClose} />}
      </DialogContent>
    </Dialog>
  );
}

function QuickAddBody({
  kind,
  vehicles,
  defaultVehicleId,
  onDone,
}: {
  kind: VehicleRecordKind;
  vehicles: QuickAddVehicle[];
  defaultVehicleId: number;
  onDone: () => void;
}) {
  const { t } = useTranslation();
  const vehicleLabelId = useId();
  const [vehicleId, setVehicleId] = useState(defaultVehicleId);
  const setLastVehicleId = useQuickAddStore((s) => s.setLastVehicleId);
  const vehicle = vehicles.find((v) => v.id === vehicleId) ?? vehicles[0];
  if (!vehicle) return null;

  const done = () => {
    setLastVehicleId(vehicle.id);
    onDone();
  };

  return (
    <div className="space-y-5">
      {vehicles.length > 1 && (
        <ListSection>
          <ListRow
            label={<span id={vehicleLabelId}>{t('quick_add.vehicle')}</span>}
            value={
              <Select value={String(vehicle.id)} onValueChange={(v) => setVehicleId(Number(v))}>
                <SelectTrigger
                  aria-labelledby={vehicleLabelId}
                  className="h-auto min-h-touch w-auto max-w-[12rem] gap-1 border-0 bg-transparent px-0 text-[1.0625rem] text-primary shadow-none"
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent align="end">
                  {vehicles.map((v) => (
                    <SelectItem key={v.id} value={String(v.id)}>
                      {v.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            }
          />
        </ListSection>
      )}
      {/* `key`: cambiando veicolo il modulo riparte con i valori predefiniti di quel veicolo (km, carburante…). */}
      <VehicleUsableGate key={vehicle.id} vehicleId={vehicle.id}>
        {kind === 'refueling' && <QuickRefueling vehicle={vehicle} onCancel={onDone} onSaved={done} />}
        {kind === 'maintenance' && <QuickMaintenance vehicleId={vehicle.id} onCancel={onDone} onSaved={done} />}
        {kind === 'expense' && <QuickExpense vehicleId={vehicle.id} onCancel={onDone} onSaved={done} />}
        {kind === 'reminder' && <QuickReminder vehicleId={vehicle.id} onCancel={onDone} onSaved={done} />}
      </VehicleUsableGate>
    </div>
  );
}

interface QuickFormProps {
  onCancel: () => void;
  onSaved: () => void;
}

function QuickRefueling({ vehicle, onCancel, onSaved }: QuickFormProps & { vehicle: QuickAddVehicle }) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const stats = useVehicleStats(vehicle.id);
  // Il prezzo e il distributore dell'ultimo rifornimento sono quasi sempre quelli giusti: si correggono se serve.
  const last = useRefuelings(vehicle.id).data?.[0];
  const mutation = useCreateRefueling();
  return (
    <RefuelingForm
      vehicleId={vehicle.id}
      defaultFuelType={last?.fuelType ?? vehicle.fuelType}
      defaultKm={stats.data?.currentKm}
      defaultValues={last ? { pricePerLiter: last.pricePerLiter, station: last.station } : undefined}
      isPending={mutation.isPending}
      error={mutation.error}
      onCancel={onCancel}
      onSubmit={(data) =>
        mutation.mutate(data, {
          onSuccess: () => {
            toast({ title: t('refueling.created'), variant: 'success' });
            onSaved();
          },
        })
      }
    />
  );
}

function QuickMaintenance({ vehicleId, onCancel, onSaved }: QuickFormProps & { vehicleId: number }) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const stats = useVehicleStats(vehicleId);
  const mutation = useCreateMaintenance();
  return (
    <MaintenanceForm
      vehicleId={vehicleId}
      defaultKm={stats.data?.currentKm}
      isPending={mutation.isPending}
      error={mutation.error}
      onCancel={onCancel}
      onSubmit={(data) =>
        mutation.mutate(data, {
          onSuccess: () => {
            toast({ title: t('maintenance.created'), variant: 'success' });
            onSaved();
          },
        })
      }
    />
  );
}

function QuickExpense({ vehicleId, onCancel, onSaved }: QuickFormProps & { vehicleId: number }) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const mutation = useCreateExpense();
  return (
    <ExpenseForm
      vehicleId={vehicleId}
      isPending={mutation.isPending}
      error={mutation.error}
      onCancel={onCancel}
      onSubmit={(data) =>
        mutation.mutate(data, {
          onSuccess: () => {
            toast({ title: t('expense.created'), variant: 'success' });
            onSaved();
          },
        })
      }
    />
  );
}

function QuickReminder({ vehicleId, onCancel, onSaved }: QuickFormProps & { vehicleId: number }) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const mutation = useCreateReminder();
  return (
    <ReminderForm
      vehicleId={vehicleId}
      isPending={mutation.isPending}
      error={mutation.error}
      onCancel={onCancel}
      onSubmit={(data) =>
        mutation.mutate(data, {
          onSuccess: () => {
            toast({ title: t('reminder.created'), variant: 'success' });
            onSaved();
          },
        })
      }
    />
  );
}
