import { Controller, useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import {
  Alert,
  Button,
  Checkbox,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  FormField,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Text,
} from '@/components/ui';
import { ApiError } from '@/api/client';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import { useTransferCandidates, useTransferVehicle } from '@/hooks/useVehicleShares';
import type { MemberRole } from '@/hooks/useOrganizationMembers';
import { vehicleTransferSchema, type VehicleTransferFormData } from '@/schemas/vehicleTransfer.schema';

export interface TransferVehicleDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  vehicleId: number;
  /** Ruolo dell'utente corrente nell'organizzazione: decide se dopo il trasferimento vede ancora il veicolo. */
  orgRole: MemberRole | undefined;
  /** Chiamata dopo un trasferimento riuscito se l'utente non può più vedere il veicolo: la pagina naviga altrove. */
  onAccessLost: () => void;
}

/**
 * Trasferimento di proprietà del veicolo. Il dialog è la conferma (submit distruttivo): niente
 * ulteriore ConfirmDialog. Il corpo si monta a ogni apertura, così form ed errore ripartono puliti.
 */
export function TransferVehicleDialog({ open, onOpenChange, vehicleId, orgRole, onAccessLost }: TransferVehicleDialogProps) {
  const { t } = useTranslation();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent mobileFullScreen>
        <DialogHeader>
          <DialogTitle>{t('vehicle.transfer.title')}</DialogTitle>
          <DialogDescription className="sr-only">{t('vehicle.transfer.description')}</DialogDescription>
        </DialogHeader>
        <TransferForm
          vehicleId={vehicleId}
          orgRole={orgRole}
          onClose={() => onOpenChange(false)}
          onAccessLost={onAccessLost}
        />
      </DialogContent>
    </Dialog>
  );
}

interface TransferFormProps {
  vehicleId: number;
  orgRole: MemberRole | undefined;
  onClose: () => void;
  onAccessLost: () => void;
}

function TransferForm({ vehicleId, orgRole, onClose, onAccessLost }: TransferFormProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const tErr = useFieldErrorMessage();

  const candidatesQuery = useTransferCandidates(vehicleId);
  const transfer = useTransferVehicle(vehicleId);

  const form = useForm<VehicleTransferFormData>({
    resolver: zodResolver(vehicleTransferSchema),
    defaultValues: { userId: 0, keepAccess: true },
  });
  const errors = form.formState.errors;
  const selectedUserId = useWatch({ control: form.control, name: 'userId' });
  const { hasUnmapped } = useServerFieldErrors(form, transfer.error);

  const candidates = candidatesQuery.data ?? [];
  const showGenericError =
    transfer.error !== null && (!(transfer.error instanceof ApiError) || transfer.error.title !== 'validation_failed' || hasUnmapped);

  // mutateAsync, non i callback di mutate(): alla chiusura il corpo si smonta e i callback non partirebbero.
  async function submit(data: VehicleTransferFormData) {
    try {
      await transfer.mutateAsync(data);
    } catch {
      return; // l'errore resta in `transfer.error` e viene mostrato nel dialog
    }
    toast({ title: t('vehicle.transfer.success'), variant: 'success' });
    onClose();
    // Senza accesso in sola lettura un semplice member non vede più il veicolo: la pagina darebbe 403 (la navigazione spetta alla pagina).
    if (!data.keepAccess && orgRole === 'member') onAccessLost();
  }

  return (
    <form onSubmit={(e) => void form.handleSubmit(submit)(e)} className="space-y-4" noValidate>
      {candidatesQuery.isLoading && <Spinner size="sm" />}
      {candidatesQuery.error && <Alert variant="error">{errorMessage(candidatesQuery.error)}</Alert>}

      {candidatesQuery.data && candidates.length === 0 && (
        <Text variant="muted" className="text-sm">
          {t('vehicle.transfer.no_candidates')}
        </Text>
      )}

      {candidates.length > 0 && (
        <>
          <FormField label={t('vehicle.transfer.recipient')} error={tErr(errors.userId?.message)} required>
            {(id) => (
              <Controller
                control={form.control}
                name="userId"
                render={({ field }) => (
                  <Select
                    value={field.value > 0 ? String(field.value) : ''}
                    onValueChange={(v) => field.onChange(Number(v))}
                  >
                    <SelectTrigger id={id} aria-invalid={!!errors.userId}>
                      <SelectValue placeholder={t('vehicle.transfer.recipient_placeholder')} />
                    </SelectTrigger>
                    <SelectContent>
                      {candidates.map((c) => (
                        <SelectItem key={c.id} value={String(c.id)}>
                          {c.firstName} {c.lastName}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            )}
          </FormField>

          <Controller
            control={form.control}
            name="keepAccess"
            render={({ field }) => (
              <label className="flex min-h-touch cursor-pointer items-start gap-3 py-1">
                <span className="mt-0.5">
                  <Checkbox checked={field.value} onChange={(e) => field.onChange(e.target.checked)} />
                </span>
                <span className="min-w-0 space-y-1">
                  <span className="block text-sm font-medium">{t('vehicle.transfer.keep_access')}</span>
                  <span className="block text-sm text-muted-foreground">{t('vehicle.transfer.keep_access_hint')}</span>
                </span>
              </label>
            )}
          />

          <Alert variant="warning" role="note">{t('vehicle.transfer.warning')}</Alert>
        </>
      )}

      {showGenericError && <Alert variant="error">{errorMessage(transfer.error)}</Alert>}

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onClose} disabled={transfer.isPending}>
          {t('actions.cancel')}
        </Button>
        {candidates.length > 0 && (
          <Button type="submit" variant="destructive" disabled={transfer.isPending || selectedUserId <= 0}>
            {transfer.isPending ? <Spinner size="sm" /> : t('vehicle.transfer.submit')}
          </Button>
        )}
      </DialogFooter>
    </form>
  );
}
