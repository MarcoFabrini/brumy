import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Archive, MoreHorizontal, Pencil, Trash2, Users } from 'lucide-react';
import {
  Alert,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  ListRow,
  ListSection,
  Skeleton,
} from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  AttachmentsSection,
  ConfirmDialog,
  FuelTypeBadge,
  InfoList,
  InfoRow,
  NotesRow,
  VehicleAddRecordMenu,
  VehicleCharts,
  VehicleShareCard,
  VehicleSummaryTiles,
} from '@/components/features';
import { VEHICLE_RECORD_ACTIONS, withVehicle, type VehicleRecordKind } from '@/components/features/vehicleRecordActions';
import { useReminders } from '@/hooks/useReminders';
import { DASHBOARD_CHART_MONTHS } from '@/hooks/useDashboardCharts';
import { monthSpending } from '@/lib/monthSpending';
import { nextDeadline } from '@/lib/nextDeadline';
import { useIsDesktop } from '@/hooks/useIsDesktop';
import {
  useArchiveVehicle,
  useDeleteVehicle,
  useUnarchiveVehicle,
  useVehicle,
  useVehicleCharts,
  useVehicleStats,
} from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useAuthStore } from '@/stores/useAuthStore';
import { useToast } from '@/hooks/useToast';
import { useFormat } from '@/hooks/useFormat';

/**
 * VehicleDetailPage — scheda del veicolo in stile iOS: in cima i numeri che servono (km, consumo, spesa del mese) e la
 * prossima scadenza; poi il registro (rifornimenti, manutenzioni, spese, promemoria) come righe che aprono le
 * liste, i dati del veicolo, gli allegati e i grafici. Le azioni sul veicolo
 * stanno nel menu "…"; si aggiunge dal "+" della tab bar, già su questo veicolo.
 */
export function VehicleDetailPage() {
  const { t } = useTranslation();
  const fmt = useFormat();
  const { id } = useParams<{ id: string }>();
  const vehicleId = Number(id);
  const navigate = useNavigate();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useVehicle(vehicleId);
  const stats = useVehicleStats(vehicleId);
  const reminders = useReminders(vehicleId);
  // Stessa query dei grafici in fondo: da qui la spesa del mese nel riepilogo.
  const charts = useVehicleCharts(vehicleId, DASHBOARD_CHART_MONTHS);
  const deleteMutation = useDeleteVehicle();
  const archive = useArchiveVehicle();
  const unarchive = useUnarchiveVehicle();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [shareOpen, setShareOpen] = useState(false);
  const isDesktop = useIsDesktop();

  const currentUser = useAuthStore((s) => s.user);

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-48 w-full rounded-lg" />
      </div>
    );
  }

  if (error) return <Alert variant="error">{errorMessage(error)}</Alert>;
  if (!data) return null;

  const archived = Boolean(data.archivedAt);
  // Permessi sul singolo veicolo (proprietario, org owner/admin o sola lettura
  // via condivisione): chi ha solo lo share in lettura non vede le azioni.
  const { canEdit, canDelete, canShare } = data.permissions;

  const nextReminder = nextDeadline(reminders.data ?? [], stats.data?.currentKm);
  const totals = stats.data?.totals;
  // Il registro del veicolo: una riga per sezione, che apre la sua lista filtrata su questo veicolo, col numero
  // di record a destra (dalle statistiche; i promemoria dalla loro lista).
  const counts: Partial<Record<VehicleRecordKind, number | undefined>> = {
    ...totals?.sections,
    refueling: totals?.refuelings,
    reminder: reminders.data?.length,
  };
  const log = VEHICLE_RECORD_ACTIONS.map((section) => ({
    to: withVehicle(section.listPath, data.id),
    label: t(section.titleKey),
    count: counts[section.kind],
    Icon: section.Icon,
    iconClassName: section.iconClassName,
  }));

  return (
    <div className="space-y-6">
      <PageHeader
        title={data.name}
        description={[`${data.brand} ${data.model} · ${data.year}`, data.licensePlate].filter(Boolean).join(' · ')}
        onBack={() => navigate('/vehicles')}
        action={
          canEdit || canDelete ? (
            <>
              {/* Solo desktop: su mobile si aggiunge dal "+" della barra in basso (già su questo veicolo). */}
              {canEdit && !archived && (
                <VehicleAddRecordMenu vehicleId={data.id} className="hidden md:inline-flex" />
              )}
              {canShare && (
                <Button
                  variant="ghost"
                  size="icon"
                  className="text-primary"
                  aria-label={t('vehicle.share.title')}
                  onClick={() => (isDesktop ? setShareOpen(true) : navigate(`/vehicles/${data.id}/sharing`))}
                >
                  <Users className="!size-[1.375rem]" />
                </Button>
              )}
              {/* Come in iOS: le azioni sul veicolo in un menu "…", l'eliminazione in rosso in fondo. */}
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="-mr-2 text-primary" aria-label={t('vehicle.summary.more_actions')}>
                    <MoreHorizontal className="!size-6" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  {canEdit && (
                    <DropdownMenuItem asChild>
                      <Link to={`/vehicles/${data.id}/edit`}>
                        <Pencil />
                        {t('actions.edit')}
                      </Link>
                    </DropdownMenuItem>
                  )}
                  {/* Archiviare richiede lo stesso permesso dell'eliminazione (voter DELETE). */}
                  {canDelete && !archived && (
                    <DropdownMenuItem onSelect={() => setArchiveOpen(true)}>
                      <Archive />
                      {t('actions.archive')}
                    </DropdownMenuItem>
                  )}
                  {canDelete && (
                    <>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem className="text-destructive-text" onSelect={() => setConfirmOpen(true)}>
                        <Trash2 />
                        {t('actions.delete')}
                      </DropdownMenuItem>
                    </>
                  )}
                </DropdownMenuContent>
              </DropdownMenu>
            </>
          ) : undefined
        }
      />

      {archived && canDelete && (
        <Alert variant="warning" className="items-center justify-between">
          <span>{t('vehicle.archived_banner')}</span>
          <Button
            size="sm"
            variant="outline"
            disabled={unarchive.isPending}
            onClick={() =>
              unarchive.mutate(data.id, {
                onSuccess: () => toast({ title: t('vehicle.restored'), variant: 'success' }),
                onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
              })
            }
          >
            {t('vehicle.restore')}
          </Button>
        </Alert>
      )}

      <VehicleSummaryTiles
        stats={stats.data}
        statsError={stats.isError}
        monthSpent={charts.data ? monthSpending(charts.data).total : undefined}
        monthError={charts.isError}
        next={nextReminder}
      />

      <ListSection aria-label={t('vehicle.summary.log')}>
        {log.map(({ to, label, count, Icon, iconClassName }) => (
          <ListRow
            key={to}
            as={Link}
            to={to}
            icon={<Icon />}
            iconClassName={iconClassName}
            label={label}
            value={count ?? undefined}
            chevron
          />
        ))}
      </ListSection>

      <InfoList>
        <InfoRow label={t('vehicle.type')} value={t(`vehicle.type_options.${data.type}`)} />
        <InfoRow
          label={t('vehicle.fuel_type')}
          value={
            <span className="flex flex-wrap justify-end gap-1">
              <FuelTypeBadge type={data.fuelType} />
              {data.secondaryFuelType && <FuelTypeBadge type={data.secondaryFuelType} />}
            </span>
          }
        />
        {data.licensePlate && <InfoRow label={t('vehicle.license_plate')} value={data.licensePlate} />}
        {data.vin && <InfoRow label={t('vehicle.vin')} value={<span className="truncate">{data.vin}</span>} />}
        <InfoRow label={t('vehicle.initial_km')} value={fmt.km(data.initialKm)} />
        {data.purchasePrice && <InfoRow label={t('vehicle.purchase_price')} value={fmt.currency(data.purchasePrice)} />}
        {stats.data && <InfoRow label={t('vehicle.stats.km_driven')} value={fmt.km(stats.data.kmDriven)} />}
        {stats.data && <InfoRow label={t('vehicle.stats.total_cost')} value={fmt.currency(stats.data.totals.cost)} />}
        {stats.data && stats.data.costPerKm !== null && (
          <InfoRow label={t('vehicle.stats.cost_per_km')} value={fmt.currency(stats.data.costPerKm)} />
        )}
        {data.ownership === 'shared' && <InfoRow label={t('vehicle.access')} value={t('vehicle.shared_read_only')} />}
        <NotesRow label={t('common.notes')} notes={data.notes} />
      </InfoList>

      <AttachmentsSection entityType="vehicle" entityId={data.id} readOnly={!canEdit} />

      <VehicleCharts vehicleId={data.id} />

      {/* Condivisione: su desktop in una finestra; sul telefono è una pagina (/vehicles/:id/sharing). */}
      {canShare && currentUser && (
        <Dialog open={shareOpen} onOpenChange={setShareOpen}>
          <DialogContent className="max-w-xl bg-background">
            <DialogHeader>
              <DialogTitle>{t('vehicle.share.title')}</DialogTitle>
              <DialogDescription>{data.name}</DialogDescription>
            </DialogHeader>
            <VehicleShareCard
              vehicleId={data.id}
              currentUserId={currentUser.id}
              onAccessLost={() => void navigate('/vehicles')}
            />
          </DialogContent>
        </Dialog>
      )}

      <ConfirmDialog
        open={archiveOpen}
        onOpenChange={setArchiveOpen}
        title={t('vehicle.archive.title')}
        description={t('vehicle.archive.description')}
        confirmVariant="primary"
        confirmLabel={t('actions.archive')}
        isPending={archive.isPending}
        onConfirm={() =>
          archive.mutate(vehicleId, {
            onSuccess: () => {
              toast({ title: t('vehicle.archive.done'), variant: 'success' });
              navigate('/vehicles', { replace: true });
            },
            onError: (e) => {
              setArchiveOpen(false);
              toast({ title: errorMessage(e), variant: 'error' });
            },
          })
        }
      />

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('vehicle.delete.title')}
        description={t('vehicle.delete.description')}
        isPending={deleteMutation.isPending}
        onConfirm={() =>
          deleteMutation.mutate(vehicleId, {
            onSuccess: () => {
              toast({ title: t('vehicle.deleted'), variant: 'success' });
              navigate('/vehicles', { replace: true });
            },
            onError: (e) =>
              toast({ title: errorMessage(e), variant: 'error' }),
          })
        }
        confirmLabel={t('actions.delete')}
      />
    </div>
  );
}
