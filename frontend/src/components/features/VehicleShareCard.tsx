import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronsUpDown, Trash2, UserRoundCog } from 'lucide-react';
import {
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuTrigger,
  ListRow,
  ListSection,
  Spinner,
} from '@/components/ui';
import { ConfirmDialog } from './ConfirmDialog';
import { TransferVehicleDialog } from './TransferVehicleDialog';
import { useActiveMembership } from '@/hooks/useActiveMembership';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import {
  useCreateShare,
  useRevokeShare,
  useShareCandidates,
  useVehicleShares,
  type ShareCandidate,
  type VehicleShare,
} from '@/hooks/useVehicleShares';

export interface VehicleShareCardProps {
  vehicleId: number;
  currentUserId: number;
  /** Dopo un trasferimento che toglie all'utente l'accesso al veicolo: la pagina naviga altrove. */
  onAccessLost: () => void;
}

const fullName = (p: { firstName: string; lastName: string }) => `${p.firstName} ${p.lastName}`;

/**
 * Condivisione di un veicolo (visibile a chi può gestirlo: proprietario o org
 * owner/admin). Mostra il proprietario e le condivisioni, e permette di
 * condividere in SOLA LETTURA con uno o più membri dell'organizzazione scelti
 * per nome e cognome da un elenco (nessuna email da digitare). Lo share del
 * proprietario non è revocabile.
 */
export function VehicleShareCard({ vehicleId, currentUserId, onAccessLost }: VehicleShareCardProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();

  const sharesQuery = useVehicleShares(vehicleId);
  const candidatesQuery = useShareCandidates(vehicleId);
  const create = useCreateShare(vehicleId);
  const revoke = useRevokeShare(vehicleId);

  const orgRole = useActiveMembership()?.role;

  const [transferOpen, setTransferOpen] = useState(false);
  const [toRevoke, setToRevoke] = useState<VehicleShare | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);

  const candidates = candidatesQuery.data ?? [];
  // Derivato, non duplicato in uno state: dopo un refetch scompaiono le persone
  // che non sono più selezionabili (es. appena condivise da un altro dispositivo).
  const selected: ShareCandidate[] = candidates.filter((c) => selectedIds.includes(c.id));

  function toggle(id: number) {
    setSelectedIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
  }

  function submit() {
    if (selected.length === 0) return;
    create.mutate(
      { userIds: selected.map((c) => c.id) },
      {
        onSuccess: () => {
          toast({ title: t('vehicle.share.shared'), variant: 'success' });
          setSelectedIds([]);
        },
        onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
      },
    );
  }

  function confirmRevoke() {
    if (!toRevoke) return;
    revoke.mutate(toRevoke.id, {
      onSuccess: () => toast({ title: t('vehicle.share.revoked'), variant: 'success' }),
      onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
    });
    setToRevoke(null);
  }

  const triggerLabel =
    selected.length === 0
      ? t('vehicle.share.select_people')
      : selected.length <= 2
        ? selected.map(fullName).join(', ')
        : t('vehicle.share.selected_count', { count: selected.length });

  return (
    <div className="space-y-6">
      {/* Chi vede il veicolo: lista iOS, il proprietario in testa (non revocabile), gli altri con la revoca. */}
      <ListSection title={t('vehicle.share.people_title')} footer={t('vehicle.share.description')}>
        {sharesQuery.isLoading && (
          <div className="p-4">
            <Spinner size="sm" />
          </div>
        )}
        {sharesQuery.data?.length === 0 && (
          <p className="px-4 py-3 text-[0.9375rem] text-muted-foreground">{t('vehicle.share.empty')}</p>
        )}
        {sharesQuery.data && sharesQuery.data.length > 0 && (
          <ul>
            {sharesQuery.data.map((s) => {
              // Lo share `admin` è il proprietario del veicolo: non revocabile.
              const revocable = s.role !== 'admin' && s.user.id !== currentUserId;
              return (
                <li key={s.id} className="flex min-h-touch items-center gap-3 pl-4">
                  <div className="list-row-body flex min-w-0 flex-1 items-center gap-2 border-b py-2.5 pr-2">
                    <p className="min-w-0 flex-1 truncate text-[1.0625rem]">
                      {fullName(s.user)}
                      {s.user.id === currentUserId && (
                        <span className="text-muted-foreground"> ({t('vehicle.share.you')})</span>
                      )}
                    </p>
                    <Badge variant="secondary">{t(`vehicle.share.role.${s.role}`)}</Badge>
                    {revocable ? (
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('vehicle.share.revoke')}
                        onClick={() => setToRevoke(s)}
                      >
                        <Trash2 className="text-destructive-text" />
                      </Button>
                    ) : (
                      <span className="w-2" aria-hidden="true" />
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </ListSection>

      <section className="space-y-2">
        <h2 className="px-4 text-[0.8125rem] uppercase tracking-wide text-muted-foreground">
          {t('vehicle.share.invite_title')}
        </h2>
        {candidatesQuery.isLoading && <Spinner size="sm" />}
        {candidatesQuery.data && candidates.length === 0 && (
          <p className="px-4 text-[0.9375rem] text-muted-foreground">{t('vehicle.share.no_candidates')}</p>
        )}
        {candidates.length > 0 && (
          <div className="space-y-3">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" className="w-full justify-between bg-card text-foreground">
                  <span className="truncate">{triggerLabel}</span>
                  <ChevronsUpDown className="opacity-50" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent
                align="start"
                className="max-h-72 w-[var(--radix-dropdown-menu-trigger-width)] overflow-y-auto"
              >
                <DropdownMenuLabel>{t('vehicle.share.select_people')}</DropdownMenuLabel>
                {candidates.map((c) => (
                  <DropdownMenuCheckboxItem
                    key={c.id}
                    checked={selectedIds.includes(c.id)}
                    onCheckedChange={() => toggle(c.id)}
                    // Multi-selezione: il menu resta aperto dopo ogni scelta.
                    onSelect={(e) => e.preventDefault()}
                  >
                    {fullName(c)}
                  </DropdownMenuCheckboxItem>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>

            <Button type="button" fullWidth onClick={submit} disabled={selected.length === 0 || create.isPending}>
              {create.isPending ? (
                <Spinner size="sm" />
              ) : selected.length > 1 ? (
                t('vehicle.share.submit_many', { count: selected.length })
              ) : (
                t('vehicle.share.invite_submit')
              )}
            </Button>
          </div>
        )}
      </section>

      <ListSection footer={t('vehicle.transfer.section_description')}>
        <ListRow
          as="button"
          type="button"
          onClick={() => setTransferOpen(true)}
          icon={<UserRoundCog />}
          iconClassName="bg-[#5856d6]"
          label={t('vehicle.transfer.button')}
          chevron
        />
      </ListSection>

      <TransferVehicleDialog
        open={transferOpen}
        onOpenChange={setTransferOpen}
        vehicleId={vehicleId}
        orgRole={orgRole}
        onAccessLost={onAccessLost}
      />

      <ConfirmDialog
        open={toRevoke !== null}
        onOpenChange={(o) => !o && setToRevoke(null)}
        title={t('vehicle.share.revoke_title')}
        description={
          toRevoke ? t('vehicle.share.revoke_description', { name: fullName(toRevoke.user) }) : undefined
        }
        confirmLabel={t('vehicle.share.revoke')}
        confirmVariant="destructive"
        onConfirm={confirmRevoke}
      />
    </div>
  );
}
