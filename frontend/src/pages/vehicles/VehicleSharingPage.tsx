import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { VehicleShareCard } from '@/components/features';
import { useVehicle } from '@/hooks/useVehicles';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useAuthStore } from '@/stores/useAuthStore';

/**
 * Condivisione del veicolo come pagina a sé (sul telefono; su desktop la stessa cosa si apre in una finestra dal
 * dettaglio): chi ha accesso, condividi con altri membri, trasferisci la proprietà.
 */
export function VehicleSharingPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const errorMessage = useApiErrorMessage();
  const { id } = useParams<{ id: string }>();
  const vehicleId = Number(id);
  const { data, isLoading, error } = useVehicle(vehicleId);
  const currentUser = useAuthStore((s) => s.user);

  if (isLoading) return <Skeleton className="h-48 w-full rounded-xl" />;
  if (error) return <Alert variant="error">{errorMessage(error)}</Alert>;
  if (!data || !currentUser) return null;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('vehicle.share.title')}
        description={data.name}
        onBack={() => navigate(`/vehicles/${data.id}`)}
      />
      {data.permissions.canShare ? (
        <VehicleShareCard
          vehicleId={data.id}
          currentUserId={currentUser.id}
          onAccessLost={() => void navigate('/vehicles')}
        />
      ) : (
        <Alert variant="warning">{t('vehicle.share.not_allowed')}</Alert>
      )}
    </div>
  );
}
