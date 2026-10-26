import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MapPin, Search } from 'lucide-react';
import {
  Input,
  ListRow,
  ListSection,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Skeleton,
  Text,
} from '@/components/ui';
import type { FuelServiceMode, FuelStation } from '@/api/types/fuelPrice';
import type { FuelType } from '@/api/types/vehicle';
import { useFuelStationSearch, useNearbyFuelStations } from '@/hooks/useFuelPrices';
import { useFormat } from '@/hooks/useFormat';
import { requestPosition, type GeoError, type GeoPosition } from '@/lib/geolocation';
import { pricedMode } from '@/lib/fuelPrice';
import { cn } from '@/lib/utils';

export interface FuelStationPickerProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSelect: (station: FuelStation) => void;
  /** Carburante di cui mostrare il prezzo; senza, la lista è solo per scegliere il distributore. */
  fuel?: FuelType;
  /** Self o servito (con `fuel`): lo decide il chiamante, che lo usa anche per proporre il prezzo. */
  mode?: FuelServiceMode;
  onModeChange?: (mode: FuelServiceMode) => void;
}

type Location = { status: 'locating' } | { status: 'found'; position: GeoPosition } | { status: 'failed'; error: GeoError };

/**
 * FuelStationPicker — foglio per scegliere un distributore dell'open data MIMIT: all'apertura chiede la posizione
 * e mostra i più vicini col prezzo; in alto la ricerca per nome, indirizzo o città, che è anche il ripiego quando la
 * posizione è negata o non c'è (inserimento da casa). La posizione va al server solo per la ricerca.
 */
export function FuelStationPicker({ open, onOpenChange, onSelect, ...rest }: FuelStationPickerProps) {
  const { t } = useTranslation();
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" className="max-h-[85vh] space-y-4 overflow-y-auto overscroll-contain bg-background px-4">
        <SheetHeader>
          <SheetTitle>{t('fuel_prices.picker_title')}</SheetTitle>
          <SheetDescription>{t('fuel_prices.picker_description')}</SheetDescription>
        </SheetHeader>
        {/* Montato solo a foglio aperto: ricerca e posizione ripartono da zero a ogni apertura */}
        <PickerBody
          {...rest}
          onChoose={(station) => {
            onSelect(station);
            onOpenChange(false);
          }}
        />
      </SheetContent>
    </Sheet>
  );
}

function PickerBody({
  fuel,
  mode = 'self',
  onModeChange,
  onChoose,
}: Pick<FuelStationPickerProps, 'fuel' | 'mode' | 'onModeChange'> & { onChoose: (station: FuelStation) => void }) {
  const { t } = useTranslation();
  const [query, setQuery] = useState('');
  const [location, setLocation] = useState<Location>({ status: 'locating' });

  // Il foglio si apre dopo il tocco dell'utente sul pulsante: è il momento giusto per chiedere la posizione
  useEffect(() => {
    let cancelled = false;
    requestPosition().then(
      (position) => !cancelled && setLocation({ status: 'found', position }),
      (error: GeoError) => !cancelled && setLocation({ status: 'failed', error }),
    );
    return () => {
      cancelled = true;
    };
  }, []);

  const searching = query.trim().length >= 2;
  const nearby = useNearbyFuelStations(location.status === 'found' ? location.position : null, fuel);
  const search = useFuelStationSearch(query, fuel);

  return (
    <>
      <div className="relative">
        <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={t('fuel_prices.search_placeholder')}
          aria-label={t('fuel_prices.search_placeholder')}
          className="pl-9"
          maxLength={100}
        />
      </div>

      {fuel && onModeChange && <FuelModeToggle mode={mode} onChange={onModeChange} />}

      {searching ? (
        <StationList
          stations={search.data}
          loading={search.isLoading}
          empty={t('fuel_prices.search_empty')}
          mode={mode}
          showPrice={!!fuel}
          onChoose={onChoose}
        />
      ) : location.status === 'failed' ? (
        <Text variant="muted" className="px-1">
          {t(`fuel_prices.location_${location.error}`)}
        </Text>
      ) : (
        <StationList
          title={t('fuel_prices.nearby')}
          stations={nearby.data}
          loading={location.status === 'locating' || nearby.isLoading}
          empty={t('fuel_prices.nearby_empty')}
          mode={mode}
          showPrice={!!fuel}
          onChoose={onChoose}
        />
      )}
    </>
  );
}

/** Self o servito: due pulsanti affiancati (`compact` per la riga sotto il prezzo nel modulo). */
export function FuelModeToggle({
  mode,
  onChange,
  compact = false,
}: {
  mode: FuelServiceMode;
  onChange: (mode: FuelServiceMode) => void;
  compact?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <div
      role="group"
      aria-label={t('fuel_prices.mode')}
      className={cn('grid grid-cols-2 gap-1 rounded-lg bg-muted p-1', compact && 'w-44 shrink-0 text-xs')}
    >
      {(['self', 'served'] as const).map((m) => (
        <button
          key={m}
          type="button"
          aria-pressed={mode === m}
          onClick={() => onChange(m)}
          className={cn(
            'rounded-md font-medium text-muted-foreground transition-colors',
            compact ? 'min-h-8' : 'min-h-9 text-sm',
            mode === m && 'bg-background text-foreground shadow-sm',
          )}
        >
          {t(`fuel_prices.mode_${m}`)}
        </button>
      ))}
    </div>
  );
}

interface StationListProps {
  title?: string;
  stations: FuelStation[] | undefined;
  loading: boolean;
  empty: string;
  mode: FuelServiceMode;
  showPrice: boolean;
  onChoose: (station: FuelStation) => void;
}

function StationList({ title, stations, loading, empty, mode, showPrice, onChoose }: StationListProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  if (loading) return <Skeleton className="h-44 w-full rounded-xl" />;
  if (!stations?.length) {
    return (
      <Text variant="muted" className="px-1">
        {empty}
      </Text>
    );
  }
  return (
    <ListSection title={title}>
      {stations.map((station) => {
        const shownMode = pricedMode(station.prices, mode);
        const price = station.prices[shownMode];
        const details = [
          station.distanceKm !== null ? t('fuel_prices.distance', { km: fmt.decimal(station.distanceKm, 1) }) : null,
          showPrice && price ? t('fuel_prices.communicated_on', { date: fmt.date(price.communicatedAt) }) : null,
        ].filter(Boolean);
        return (
          <ListRow
            key={station.id}
            as="button"
            type="button"
            onClick={() => onChoose(station)}
            icon={<MapPin />}
            iconClassName="bg-[#ff9500]"
            label={station.label}
            description={details.join(' · ') || undefined}
            value={showPrice ? <StationPrices prices={station.prices} highlight={shownMode} /> : undefined}
          />
        );
      })}
    </ListSection>
  );
}

/** Self e servito uno sopra l'altro, in evidenza quello che va nel modulo; GPL e metano hanno quasi solo il servito. */
function StationPrices({ prices, highlight }: { prices: FuelStation['prices']; highlight: FuelServiceMode }) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const available = (['self', 'served'] as const).filter((m) => prices[m]);
  if (available.length === 0) return <>{t('fuel_prices.no_price')}</>;
  return (
    <span className="flex flex-col items-end text-sm leading-tight">
      {available.map((m) => (
        <span key={m} className={cn(m === highlight && 'font-semibold text-foreground')}>
          {t(`fuel_prices.mode_${m}`)} {fmt.fuelPrice(prices[m]?.price)}
        </span>
      ))}
    </span>
  );
}
