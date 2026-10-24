import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import { Clock, Trash2, UserPlus, X } from 'lucide-react';
import {
  Alert,
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  FormField,
  ListRow,
  ListSection,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from '@/components/ui';
import { ConfirmDialog } from './ConfirmDialog';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import {
  useChangeMemberRole,
  useInvitations,
  useInviteMember,
  useMembers,
  useRemoveMember,
  useRevokeInvitation,
  type MemberRole,
  type OrgMember,
} from '@/hooks/useOrganizationMembers';
import {
  MEMBER_ROLES,
  assignableRoles,
  isDemotion,
  isMemberRole,
  roleChangeKind,
} from '@/lib/memberRoles';
import { inviteMemberSchema, type InviteMemberFormData } from '@/schemas/account.schema';

export interface MembersCardProps {
  organizationId: number;
  currentUserId: number;
  /** Ruolo dell'utente corrente nell'organizzazione: decide su quali righe compare il Select del ruolo. */
  currentRole: MemberRole;
}

/**
 * Gestione membri organizzazione (owner/admin): membri attivi + inviti pendenti
 * + form per invitare un'email (anche non registrata: l'account si crea in
 * fase di accettazione). Il badge del ruolo diventa un Select dove l'utente può
 * cambiarlo (`assignableRoles`, stessa matrice del backend che resta l'autorità);
 * ogni cambio passa da una conferma.
 */
export function MembersCard({ organizationId, currentUserId, currentRole }: MembersCardProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const tErr = useFieldErrorMessage();

  const membersQuery = useMembers(organizationId);
  const invitationsQuery = useInvitations(organizationId);
  const invite = useInviteMember(organizationId);
  const revoke = useRevokeInvitation(organizationId);
  const remove = useRemoveMember(organizationId);
  const changeRole = useChangeMemberRole(organizationId);

  const [toRemove, setToRemove] = useState<OrgMember | null>(null);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [roleChange, setRoleChange] = useState<{ member: OrgMember; role: MemberRole } | null>(null);

  const ownerCount = membersQuery.data?.filter((m) => m.role === 'owner').length ?? 0;

  const form = useForm<InviteMemberFormData>({
    resolver: zodResolver(inviteMemberSchema),
    defaultValues: { email: '', role: 'member' },
  });
  const errors = form.formState.errors;

  function submit(data: InviteMemberFormData) {
    invite.mutate(data, {
      onSuccess: () => {
        toast({ title: t('settings.members.invited'), variant: 'success' });
        form.reset({ email: '', role: 'member' });
        setInviteOpen(false);
      },
      onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
    });
  }

  function confirmRemove() {
    if (!toRemove) return;
    remove.mutate(toRemove.id, {
      onSuccess: () => toast({ title: t('settings.members.removed'), variant: 'success' }),
      onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
    });
    setToRemove(null);
  }

  function askRoleChange(member: OrgMember, value: string) {
    if (isMemberRole(value) && value !== member.role) setRoleChange({ member, role: value });
  }

  // mutateAsync, non i callback di mutate(): se l'utente cambia il proprio ruolo la card si smonta
  // (SettingsPage la mostra solo a owner/admin) prima della fine e i callback non partirebbero.
  async function confirmRoleChange() {
    if (!roleChange) return;
    const { member, role } = roleChange;
    try {
      await changeRole.mutateAsync({ memberId: member.id, role, self: member.user.id === currentUserId });
      toast({ title: t('settings.members.role_updated'), variant: 'success' });
    } catch (e) {
      toast({ title: errorMessage(e), variant: 'error' });
    }
    setRoleChange(null);
  }

  function roleChangeDescription(): string | undefined {
    if (!roleChange) return undefined;
    const { member, role } = roleChange;
    const name = `${member.user.firstName} ${member.user.lastName}`;
    const kind = roleChangeKind(member.role, role);
    if (kind === 'owner_demoted' && member.user.id === currentUserId) {
      return t('settings.members.role_self_demoted', { role: t(`settings.role.${role}`).toLowerCase() });
    }
    return t(`settings.members.role_${kind}`, { name, role: t(`settings.role.${role}`).toLowerCase() });
  }

  function revokeInvitation(id: number) {
    revoke.mutate(id, {
      onSuccess: () => toast({ title: t('settings.members.invite_revoked'), variant: 'success' }),
      onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
    });
  }

  return (
    <div className="space-y-6">
      {membersQuery.isLoading && <Spinner size="sm" />}
      {membersQuery.error && <Alert variant="error">{errorMessage(membersQuery.error)}</Alert>}
      {invitationsQuery.error && <Alert variant="error">{errorMessage(invitationsQuery.error)}</Alert>}

      {/* Membri come lista iOS: nome ed email a sinistra, ruolo (selettore compatto) e rimozione a destra. */}
      {membersQuery.data && (
        <ListSection title={t('settings.members.title')}>
          <ul>
            {membersQuery.data.map((m) => (
              <li key={m.id} className="flex min-h-touch items-center gap-3 pl-4">
                <div className="list-row-body flex min-w-0 flex-1 items-center gap-2 border-b py-2.5 pr-2">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[1.0625rem]">
                      {m.user.firstName} {m.user.lastName}
                      {m.user.id === currentUserId && (
                        <span className="text-muted-foreground"> ({t('settings.members.you')})</span>
                      )}
                    </p>
                    <p className="truncate text-[0.9375rem] text-muted-foreground">{m.user.email}</p>
                  </div>
                  <MemberRoleControl
                    member={m}
                    options={assignableRoles(currentRole, m.role, m.user.id === currentUserId, ownerCount)}
                    disabled={changeRole.isPending}
                    onChange={(value) => askRoleChange(m, value)}
                  />
                  {m.role !== 'owner' &&
                    m.user.id !== currentUserId &&
                    !(m.role === 'admin' && currentRole !== 'owner') && (
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('settings.members.remove')}
                        onClick={() => setToRemove(m)}
                      >
                        <Trash2 className="text-destructive-text" />
                      </Button>
                    )}
                </div>
              </li>
            ))}
          </ul>
        </ListSection>
      )}

      {invitationsQuery.data && invitationsQuery.data.length > 0 && (
        <ListSection title={t('settings.members.pending_title')}>
          <ul>
            {invitationsQuery.data.map((inv) => (
              <li key={inv.id} className="flex min-h-touch items-center gap-3 pl-4">
                <div className="list-row-body flex min-w-0 flex-1 items-center gap-2 border-b py-2.5 pr-2">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[1.0625rem]">{inv.email}</p>
                    <p className="flex items-center gap-1 text-[0.9375rem] text-muted-foreground">
                      <Clock className="size-3.5" aria-hidden="true" />
                      {t(`settings.role.${inv.role}`)} · {t('settings.members.pending')}
                    </p>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('settings.members.revoke')}
                    disabled={revoke.isPending}
                    onClick={() => revokeInvitation(inv.id)}
                  >
                    <X className="text-destructive-text" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        </ListSection>
      )}

      <ListSection>
        <ListRow
          as="button"
          type="button"
          onClick={() => setInviteOpen(true)}
          icon={<UserPlus />}
          iconClassName="bg-[#34c759]"
          label={<span className="text-primary">{t('settings.members.invite_title')}</span>}
        />
      </ListSection>

      <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
        <DialogContent mobileFullScreen>
          <DialogHeader>
            <DialogTitle>{t('settings.members.invite_title')}</DialogTitle>
            <DialogDescription>{t('settings.members.invite_description')}</DialogDescription>
          </DialogHeader>
          <form onSubmit={form.handleSubmit(submit)} className="space-y-4">
            <FormField label={t('settings.members.email')} error={tErr(errors.email?.message)} required>
              {(id) => (
                <Input
                  id={id}
                  type="email"
                  autoComplete="off"
                  autoCapitalize="none"
                  inputMode="email"
                  invalid={!!errors.email}
                  {...form.register('email')}
                />
              )}
            </FormField>

            <FormField label={t('settings.members.role')}>
              {(id) => (
                <Select
                  value={form.watch('role')}
                  onValueChange={(v) => form.setValue('role', v as 'member' | 'admin')}
                >
                  <SelectTrigger id={id}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="member">{t('settings.role.member')}</SelectItem>
                    <SelectItem value="admin">{t('settings.role.admin')}</SelectItem>
                  </SelectContent>
                </Select>
              )}
            </FormField>

            <Button type="submit" size="lg" fullWidth disabled={invite.isPending}>
              {invite.isPending ? <Spinner size="sm" /> : t('settings.members.invite_submit')}
            </Button>
          </form>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={toRemove !== null}
        onOpenChange={(o) => !o && setToRemove(null)}
        title={t('settings.members.remove_title')}
        description={
          toRemove ? t('settings.members.remove_description', { email: toRemove.user.email }) : undefined
        }
        confirmLabel={t('settings.members.remove')}
        confirmVariant="destructive"
        onConfirm={confirmRemove}
      />

      <ConfirmDialog
        open={roleChange !== null}
        onOpenChange={(o) => !o && !changeRole.isPending && setRoleChange(null)}
        title={t('settings.members.role_confirm_title')}
        description={roleChangeDescription()}
        confirmVariant={roleChange && isDemotion(roleChange.member.role, roleChange.role) ? 'destructive' : 'primary'}
        isPending={changeRole.isPending}
        onConfirm={() => void confirmRoleChange()}
      />
    </div>
  );
}

interface MemberRoleControlProps {
  member: OrgMember;
  /** Ruoli a cui l'utente corrente può portare il membro; vuoto = solo badge. */
  options: MemberRole[];
  disabled: boolean;
  onChange: (value: string) => void;
}

/** Select del ruolo (controllato dal dato del server: annullare la conferma non cambia nulla) o badge di sola lettura. */
function MemberRoleControl({ member, options, disabled, onChange }: MemberRoleControlProps) {
  const { t } = useTranslation();
  if (options.length === 0) {
    return <Badge variant="secondary">{t(`settings.role.${member.role}`)}</Badge>;
  }

  return (
    <Select value={member.role} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger
        // "Pop-up button" iOS: solo il ruolo nel colore dell'app con le frecce, senza riquadro.
        className="h-auto min-h-touch w-auto max-w-[9rem] shrink-0 gap-1 border-0 bg-transparent px-1 text-[0.9375rem] text-primary shadow-none"
        aria-label={t('settings.members.change_role', { name: `${member.user.firstName} ${member.user.lastName}` })}
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {MEMBER_ROLES.filter((r) => r === member.role || options.includes(r)).map((r) => (
          <SelectItem key={r} value={r}>
            {t(`settings.role.${r}`)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
