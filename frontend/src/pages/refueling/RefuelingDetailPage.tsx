import { useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Badge, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import {
  AttachmentsSection,
  ConfirmDialog,
  DeleteRow,
  EditLink,
  FuelTypeBadge,
  InfoList,
  InfoRow,
  NotesRow,
} from '@/components/features';
import { useDeleteRefueling, useRefueling } from '@/hooks/useRefuelings';
import { useCanEditVehicle } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useToast } from '@/hooks/useToast';
import { useFormat } from '@/hooks/useFormat';
import { listReturnPath } from '@/lib/listReturn';

export function RefuelingDetailPage() {
  const { t } = useTranslation();
  const fmt = useFormat();
  const { id } = useParams<{ id: string }>();
  const refuelingId = Number(id);
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const { data, isLoading, error } = useRefueling(refuelingId);
  // Veicolo condiviso in sola lettura: niente modifica, eliminazione, allegati.
  const canEdit = useCanEditVehicle(data?.vehicleId ?? 0);
  const deleteMutation = useDeleteRefueling(data?.vehicleId ?? 0);
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

  // All'elenco da cui si è arrivati (con la sua ricerca), altrimenti ai rifornimenti del veicolo
  const listPath = listReturnPath(location.state, '/refueling', `/refueling?vehicleId=${data.vehicleId}`);
  const litersNum = Number.parseFloat(data.liters);

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('refueling.detail_title', {
          liters: fmt.decimal(litersNum, 2),
        })}
        description={data.description ?? fmt.date(data.refueledAt)}
        onBack={() => navigate(listPath)}
        action={canEdit ? <EditLink to={`/refueling/${data.id}/edit`} /> : undefined}
      />

      <InfoList>
        <InfoRow label={t('refueling.total_cost')} value={<span className="font-semibold text-foreground">{fmt.currency(data.totalCost)}</span>} />
        <InfoRow label={t('refueling.refueled_at')} value={fmt.date(data.refueledAt)} />
        <InfoRow label={t('refueling.km')} value={fmt.km(data.km)} />
        <InfoRow label={t('refueling.liters')} value={`${fmt.decimal(litersNum, 2)} L`} />
        <InfoRow label={t('refueling.price_per_liter')} value={fmt.currency(data.pricePerLiter)} />
        <InfoRow
          label={t('refueling.fuel_type')}
          value={
            <span className="flex gap-1">
              <FuelTypeBadge type={data.fuelType} />
              {data.fullTank && <Badge variant="success">{t('refueling.full_tank')}</Badge>}
            </span>
          }
        />
        {data.station && <InfoRow label={t('refueling.station')} value={<span className="truncate">{data.station}</span>} />}
        <NotesRow label={t('common.notes')} notes={data.notes} />
      </InfoList>

      <AttachmentsSection entityType="refueling" entityId={data.id} readOnly={!canEdit} />

      {canEdit && <DeleteRow label={t('refueling.delete.action')} onDelete={() => setConfirmOpen(true)} />}

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t('refueling.delete.title')}
        description={t('refueling.delete.description')}
        isPending={deleteMutation.isPending}
        confirmLabel={t('actions.delete')}
        onConfirm={() =>
          deleteMutation.mutate(refuelingId, {
            onSuccess: () => {
              toast({ title: t('refueling.deleted'), variant: 'success' });
              navigate(listPath, { replace: true });
            },
            onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
          })
        }
      />
    </div>
  );
}
