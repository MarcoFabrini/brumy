import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Car } from 'lucide-react';
import {
  ListRow,
  ListSection,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Skeleton,
} from '@/components/ui';
import { QuickAddDialog } from './QuickAddDialog';
import { VEHICLE_RECORD_ACTIONS, type VehicleRecordKind } from './vehicleRecordActions';
import { useVehicles } from '@/hooks/useVehicles';
import { useQuickAddStore } from '@/stores/useQuickAddStore';
import { pickQuickAddVehicle, vehicleIdFromLocation } from '@/lib/quickAddVehicle';

/**
 * QuickAddSheet — hub delle aggiunte (pulsante "+" della tab bar), come un action sheet iOS.
 * Un tocco su cosa registrare apre subito il modulo (QuickAddDialog) col veicolo già scelto: quello che si sta
 * guardando, altrimenti l'ultimo usato, altrimenti il primo. Si sceglie solo fra i veicoli propri e attivi (non
 * quelli condivisi in sola lettura né, per owner/admin, quelli degli altri membri, che si gestiscono dal loro
 * dettaglio). Senza veicoli propri resta solo "Nuovo veicolo".
 */
export interface QuickAddSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function QuickAddSheet({ open, onOpenChange }: QuickAddSheetProps) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { pathname, search } = useLocation();
  const { data: vehicles, isLoading } = useVehicles();
  const lastVehicleId = useQuickAddStore((s) => s.lastVehicleId);
  const [kind, setKind] = useState<VehicleRecordKind | null>(null);
  // Il veicolo si fissa quando si sceglie cosa aggiungere: navigare dietro al foglio non lo cambia.
  const [vehicleId, setVehicleId] = useState<number | null>(null);

  const owned = (vehicles ?? []).filter((v) => v.ownership === 'owned');

  function choose(next: VehicleRecordKind) {
    const id = pickQuickAddVehicle(
      owned.map((v) => v.id),
      vehicleIdFromLocation(pathname, search),
      lastVehicleId,
    );
    onOpenChange(false);
    if (id === null) return;
    setVehicleId(id);
    setKind(next);
  }

  return (
    <>
      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetContent side="bottom" className="max-h-[85vh] space-y-4 overflow-y-auto overscroll-contain bg-background px-4">
          <SheetHeader>
            <SheetTitle>{t('quick_add.open')}</SheetTitle>
            <SheetDescription className="sr-only">{t('quick_add.description')}</SheetDescription>
          </SheetHeader>
          {isLoading ? (
            <Skeleton className="h-44 w-full rounded-xl" />
          ) : (
            <>
              {owned.length > 0 && (
                <ListSection>
                  {VEHICLE_RECORD_ACTIONS.map(({ kind: k, labelKey, Icon, iconClassName }) => (
                    <ListRow
                      key={k}
                      as="button"
                      type="button"
                      onClick={() => choose(k)}
                      icon={<Icon />}
                      iconClassName={iconClassName}
                      label={t(labelKey)}
                      chevron
                    />
                  ))}
                </ListSection>
              )}
              <ListSection>
                <ListRow
                  as="button"
                  type="button"
                  onClick={() => {
                    onOpenChange(false);
                    navigate('/vehicles/new');
                  }}
                  icon={<Car />}
                  iconClassName="bg-[#007aff]"
                  label={t('quick_add.new_vehicle')}
                  chevron
                />
              </ListSection>
            </>
          )}
        </SheetContent>
      </Sheet>

      {vehicleId !== null && (
        <QuickAddDialog
          kind={kind}
          onClose={() => setKind(null)}
          vehicles={owned}
          defaultVehicleId={vehicleId}
        />
      )}
    </>
  );
}
