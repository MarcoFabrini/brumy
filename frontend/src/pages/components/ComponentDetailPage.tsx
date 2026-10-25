import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, ListRow, ListSection, Skeleton } from '@/components/ui';
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
import { maintenanceKind, sectionOf } from '@/components/features/vehicleRecordActions';
import { useComponent, useDeleteComponent } from '@/hooks/useComponents';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';
import { useFormat } from '@/hooks/useFormat';

/** Scheda di un componente: i suoi dati, dove è stato comprato e in quali lavori è stato usato. */
export function ComponentDetailPage() {
  const { t } = useTranslation();
  const fmt = useFormat();
  const { id } = useParams<{ id: string }>();
  const componentId = Number(id);
  const navigate = useNavigate();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useComponent(componentId);
  const canEdit = useCanEditVehicle(data?.vehicleId ?? 0);
  const deleteMutation = useDeleteComponent();
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
  const listPath = `/components?vehicleId=${data.vehicleId}`;

  return (
    <div className="space-y-6">
      <PageHeader
        title={data.name}
        description={data.code ?? undefined}
        onBack={() => navigate(listPath)}
        action={canEdit ? <EditLink to={`/components/${data.id}/edit`} /> : undefined}
      />

      <InfoList>
        {data.code && <InfoRow label={t('component.code')} value={data.code} />}
        {data.serialNumber && <InfoRow label={t('component.serial_number')} value={data.serialNumber} />}
        {data.shop && <InfoRow label={t('component.shop')} value={<span className="truncate">{data.shop}</span>} />}
        {data.purchasedAt && <InfoRow label={t('component.purchased_at')} value={fmt.date(data.purchasedAt)} />}
        {data.price && <InfoRow label={t('component.price')} value={<span className="font-semibold text-foreground">{fmt.currency(data.price)}</span>} />}
        <NotesRow label={t('component.notes')} notes={data.notes} />
      </InfoList>

      <ListSection title={t('component.used_in')}>
        {data.maintenances.length === 0 ? (
          <ListRow label={<span className="text-muted-foreground">{t('component.never_used')}</span>} />
        ) : (
          data.maintenances.map((m) => {
            const { Icon, iconClassName, titleKey } = sectionOf(maintenanceKind(m.category));
            return (
              <ListRow
                key={m.id}
                as={Link}
                to={`/maintenance/${m.id}`}
                icon={<Icon />}
                iconClassName={iconClassName}
                label={m.description}
                description={`${fmt.date(m.performedAt.slice(0, 10))} · ${t(titleKey)}`}
                chevron
              />
            );
          })
        )}
      </ListSection>

      <AttachmentsSection entityType="component" entityId={data.id} readOnly={!canEdit} />

      {canEdit && <DeleteRow label={t('component.delete.action')} onDelete={() => setConfirmOpen(true)} />}

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('component.delete.title')}
        description={t('component.delete.description')}
        isPending={deleteMutation.isPending}
        confirmLabel={t('actions.delete')}
        onConfirm={() =>
          deleteMutation.mutate(data.id, {
            onSuccess: () => {
              toast({ title: t('component.deleted'), variant: 'success' });
              navigate(listPath, { replace: true });
            },
            onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
          })
        }
      />
    </div>
  );
}
