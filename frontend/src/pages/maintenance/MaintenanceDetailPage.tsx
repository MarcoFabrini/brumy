import { useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Badge, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  AttachmentsSection,
  ConfirmDialog,
  DeleteRow,
  EditLink,
  InfoList,
  InfoRow,
  NotesRow,
} from '@/components/features';
import { maintenanceKind, sectionOf, withVehicle } from '@/components/features/vehicleRecordActions';
import { useDeleteMaintenance, useMaintenance } from '@/hooks/useMaintenances';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';
import { useFormat } from '@/hooks/useFormat';
import { listReturnPath } from '@/lib/listReturn';

export function MaintenanceDetailPage() {
  const { t } = useTranslation();
  const fmt = useFormat();
  const { id } = useParams<{ id: string }>();
  const maintenanceId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useMaintenance(maintenanceId);
  // Veicolo condiviso in sola lettura: niente modifica, eliminazione, allegati.
  const canEdit = useCanEditVehicle(data?.vehicleId ?? 0);
  const deleteMutation = useDeleteMaintenance(data?.vehicleId ?? 0);
  const [confirmOpen, setConfirmOpen] = useState(false);

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
  const section = sectionOf(maintenanceKind(data.category));
  // All'elenco da cui si è arrivati (con la sua ricerca), altrimenti alla sezione del veicolo
  const listPath = listReturnPath(location.state, '/maintenance', withVehicle(section.listPath, data.vehicleId));

  return (
    <div className="space-y-6">
      <PageHeader
        title={data.category === 'inspection' ? t(section.titleKey) : t(`maintenance.type_options.${data.type}`)}
        description={data.description}
        onBack={() => navigate(listPath)}
        action={canEdit ? <EditLink to={`/maintenance/${data.id}/edit`} /> : undefined}
      />

      <InfoList>
        <InfoRow label={t('maintenance.cost')} value={<span className="font-semibold text-foreground">{fmt.currency(data.cost)}</span>} />
        <InfoRow label={t('maintenance.performed_at')} value={fmt.date(data.performedAt)} />
        <InfoRow label={t('maintenance.km')} value={fmt.km(data.km)} />
        <InfoRow
          label={t('maintenance.section')}
          value={
            <Badge variant={data.category === 'unscheduled' ? 'warning' : 'success'}>
              {t(section.titleKey)}
            </Badge>
          }
        />
        {data.workshop && <InfoRow label={t('maintenance.workshop')} value={<span className="truncate">{data.workshop}</span>} />}
        {data.shop && <InfoRow label={t('maintenance.shop')} value={<span className="truncate">{data.shop}</span>} />}
        {data.components.length > 0 && (
          <InfoRow
            label={t('maintenance.components')}
            value={
              <span className="flex flex-wrap justify-end gap-x-3 gap-y-1">
                {data.components.map((c) =>
                  c.deleted ? (
                    <span key={c.id}>{t('component.deleted_name', { name: c.name })}</span>
                  ) : (
                    <Link key={c.id} to={`/components/${c.id}`} className="text-primary">
                      {c.name}
                    </Link>
                  ),
                )}
              </span>
            }
          />
        )}
        <NotesRow label={t('common.notes')} notes={data.notes} />
      </InfoList>

      <AttachmentsSection entityType="maintenance" entityId={data.id} readOnly={!canEdit} />

      {canEdit && <DeleteRow label={t('maintenance.delete.action')} onDelete={() => setConfirmOpen(true)} />}

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('maintenance.delete.title')}
        description={t('maintenance.delete.description')}
        isPending={deleteMutation.isPending}
        confirmLabel={t('actions.delete')}
        onConfirm={() =>
          deleteMutation.mutate(maintenanceId, {
            onSuccess: () => {
              toast({ title: t('maintenance.deleted'), variant: 'success' });
              navigate(listPath, { replace: true });
            },
            onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
          })
        }
      />
    </div>
  );
}
