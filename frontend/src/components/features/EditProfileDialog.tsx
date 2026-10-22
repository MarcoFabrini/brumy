import { useEffect, useRef } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import { Camera, Trash2 } from 'lucide-react';
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  FormField,
  Input,
  Spinner,
} from '@/components/ui';
import { ApiError } from '@/api/client';
import { DialogFormActions } from './DialogFormActions';
import { UserAvatar } from './UserAvatar';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { useUpdateProfile } from '@/hooks/useAccount';
import { useDeleteAvatar, useUploadAvatar } from '@/hooks/useAvatar';
import { profileSchema, type ProfileFormData } from '@/schemas/account.schema';
import type { User } from '@/stores/useAuthStore';

const AVATAR_MAX_BYTES = 2 * 1024 * 1024; // allineato al backend
const AVATAR_ACCEPT = 'image/jpeg,image/png,image/webp';

export interface EditProfileDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: User;
}

/**
 * Dialog unico del profilo: foto (upload immediato, mutation indipendente
 * dal form) + nome, cognome, email salvati insieme via PUT /api/auth/profile.
 */
export function EditProfileDialog({ open, onOpenChange, user }: EditProfileDialogProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const mutation = useUpdateProfile();
  const uploadAvatar = useUploadAvatar();
  const deleteAvatar = useDeleteAvatar();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const form = useForm<ProfileFormData>({
    resolver: zodResolver(profileSchema),
    defaultValues: { firstName: user.firstName, lastName: user.lastName, email: user.email, currentPassword: '' },
  });

  // Il form si riallinea al profilo solo quando il dialog si apre: caricare o rimuovere la foto crea un
  // nuovo oggetto `user` nello store e non deve cancellare ciò che si sta digitando.
  useEffect(() => {
    if (open) {
      form.reset({ firstName: user.firstName, lastName: user.lastName, email: user.email, currentPassword: '' });
    }
    // Solo al cambio di `open` (chiuso -> aperto): `user` cambia anche per l'avatar, `form` è stabile.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const errors = form.formState.errors;
  const emailChanged =
    (form.watch('email') ?? '').trim().toLowerCase() !== user.email.toLowerCase();
  const tErr = useFieldErrorMessage();
  const avatarBusy = uploadAvatar.isPending || deleteAvatar.isPending;

  function onPickFile(file: File | undefined) {
    if (!file) return;
    if (file.size > AVATAR_MAX_BYTES) {
      toast({ title: t('account.avatar_too_large'), variant: 'error' });
      return;
    }
    if (!AVATAR_ACCEPT.split(',').includes(file.type)) {
      toast({ title: t('account.avatar_invalid_type'), variant: 'error' });
      return;
    }
    uploadAvatar.mutate(file, {
      onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
    });
  }

  function submit({ currentPassword, ...data }: ProfileFormData) {
    if (emailChanged && !currentPassword) {
      form.setError('currentPassword', { message: 'account.current_password.required' });
      return;
    }
    mutation.mutate(
      { locale: user.locale, ...data, ...(emailChanged ? { currentPassword } : {}) },
      {
        onSuccess: (me) => {
          toast(
            emailChanged && me.pendingEmail
              ? { title: t('account.email_change_sent', { email: me.pendingEmail }), variant: 'success' }
              : { title: t('account.profile_updated'), variant: 'success' },
          );
          onOpenChange(false);
        },
        onError: (e) => {
          if (e instanceof ApiError && e.title === 'auth.invalid_current_password') {
            form.setError('currentPassword', { message: 'auth.invalid_current_password' });
          } else if (e instanceof ApiError && e.errors) {
            e.errors.forEach((err) =>
              form.setError(err.field as keyof ProfileFormData, { message: err.message }),
            );
          } else {
            toast({ title: errorMessage(e), variant: 'error' });
          }
        },
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent mobileFullScreen>
        <DialogHeader>
          <DialogTitle>{t('account.edit_profile')}</DialogTitle>
          <DialogDescription>{t('account.edit_profile_description')}</DialogDescription>
        </DialogHeader>

        {/* Foto profilo: upload/rimozione immediati, indipendenti dal salvataggio del form */}
        <div className="flex items-center justify-between gap-4">
          <UserAvatar user={user} size="lg" />
          <div className="flex flex-row gap-2">
            <input
              ref={fileInputRef}
              type="file"
              accept={AVATAR_ACCEPT}
              className="hidden"
              onChange={(e) => {
                onPickFile(e.target.files?.[0]);
                e.target.value = '';
              }}
            />
            <Button
              type="button"
              variant="outline"
              size="icon"
              disabled={avatarBusy}
              onClick={() => fileInputRef.current?.click()}
              aria-label={t('account.upload_photo')}
            >
              {avatarBusy ? <Spinner size="sm" /> : <Camera />}
            </Button>
            {user.hasAvatar && (
              <Button
                type="button"
                variant="destructive"
                size="icon"
                disabled={avatarBusy}
                onClick={() =>
                  deleteAvatar.mutate(undefined, {
                    onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
                  })
                }
                aria-label={t('account.remove_photo')}
              >
                <Trash2 />
              </Button>
            )}
          </div>
        </div>

        <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <FormField label={t('account.first_name')} error={tErr(errors.firstName?.message)} required>
              {(id) => <Input id={id} invalid={!!errors.firstName} {...form.register('firstName')} />}
            </FormField>
            <FormField label={t('account.last_name')} error={tErr(errors.lastName?.message)} required>
              {(id) => <Input id={id} invalid={!!errors.lastName} {...form.register('lastName')} />}
            </FormField>
          </div>

          <FormField
            label={t('account.email')}
            error={tErr(errors.email?.message)}
            hint={user.pendingEmail ? t('account.email_pending', { email: user.pendingEmail }) : undefined}
            required
          >
            {(id) => (
              <Input
                id={id}
                type="email"
                autoComplete="email"
                autoCapitalize="none"
                inputMode="email"
                invalid={!!errors.email}
                {...form.register('email')}
              />
            )}
          </FormField>

          {emailChanged && (
            <FormField
              label={t('account.current_password')}
              error={tErr(errors.currentPassword?.message)}
              hint={t('account.email_change_hint')}
              required
            >
              {(id) => (
                <Input
                  id={id}
                  type="password"
                  autoComplete="current-password"
                  invalid={!!errors.currentPassword}
                  {...form.register('currentPassword')}
                />
              )}
            </FormField>
          )}

          <DialogFormActions isPending={mutation.isPending} onCancel={() => onOpenChange(false)} />
        </form>
      </DialogContent>
    </Dialog>
  );
}
