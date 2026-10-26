import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MapPin } from 'lucide-react';
import { Button, Label, Switch, Text } from '@/components/ui';
import type { ManagedUserValue } from '@/api/types/userValue';
import { FollowedFuelPrices } from './FollowedFuelPrices';
import { FuelStationPicker } from './FuelStationPicker';
import { useFuelPricesStatus, useFuelStation } from '@/hooks/useFuelPrices';
import { useFollowFuelPrice, useLinkFuelStation } from '@/hooks/useUserValues';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';

/**
 * Collegamento di una voce distributore dell'elenco al distributore ufficiale, da cui il modulo del rifornimento
 * propone il prezzo anche quando lo si inserisce da casa. Collegata, la voce può seguire il prezzo: si salva ogni
 * giorno e qui (e sulla Home) se ne vede l'andamento. Niente se l'istanza ha spento i prezzi.
 */
export function FuelStationLink({ value }: { value: ManagedUserValue }) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const status = useFuelPricesStatus();
  const linked = useFuelStation(value.fuelStationId ?? null, undefined);
  const link = useLinkFuelStation();
  const follow = useFollowFuelPrice();
  const [pickerOpen, setPickerOpen] = useState(false);
  const followLabelId = useId();

  if (status.data?.enabled !== true) return null;

  const onError = (e: unknown) => toast({ title: errorMessage(e), variant: 'error' });
  const save = (fuelStationId: number | null) => link.mutate({ id: value.id, fuelStationId }, { onError });

  return (
    <div className="space-y-2">
      <Label>{t('fuel_prices.linked_station')}</Label>
      <Text variant={value.fuelStationId ? undefined : 'muted'} className="text-sm">
        {value.fuelStationId ? (linked.data?.label ?? (linked.isError ? t('fuel_prices.station_gone') : '…')) : t('fuel_prices.not_linked')}
      </Text>
      <div className="flex gap-2">
        <Button type="button" variant="outline" className="flex-1" disabled={link.isPending} onClick={() => setPickerOpen(true)}>
          <MapPin aria-hidden="true" className="size-4" />
          {value.fuelStationId ? t('fuel_prices.change_link') : t('fuel_prices.link')}
        </Button>
        {value.fuelStationId && (
          <Button type="button" variant="ghost" disabled={link.isPending} onClick={() => save(null)}>
            {t('fuel_prices.unlink')}
          </Button>
        )}
      </div>
      {value.fuelStationId && (
        <div className="flex items-center justify-between gap-3 pt-2">
          <Label id={followLabelId}>{t('fuel_prices.follow')}</Label>
          <Switch
            checked={value.followsPrice}
            disabled={follow.isPending || link.isPending}
            onCheckedChange={(checked) => follow.mutate({ id: value.id, follow: checked }, { onError })}
            aria-labelledby={followLabelId}
          />
        </div>
      )}
      {value.fuelStationId && value.followsPrice && <FollowedFuelPrices valueId={value.id} />}
      <FuelStationPicker open={pickerOpen} onOpenChange={setPickerOpen} onSelect={(station) => save(station.id)} />
    </div>
  );
}
