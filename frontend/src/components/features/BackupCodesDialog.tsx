import { useTranslation } from 'react-i18next';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui';
import { BackupCodesList } from './BackupCodesList';
import { TwoFactorCredentialsForm } from './TwoFactorCredentialsForm';
import { useRegenerateBackupCodes } from '@/hooks/useTwoFactor';

export interface BackupCodesDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/** Codici di recupero nuovi (password + codice), mostrati una volta sola; i precedenti smettono di valere. */
export function BackupCodesDialog({ open, onOpenChange }: BackupCodesDialogProps) {
  const { t } = useTranslation();
  const regenerate = useRegenerateBackupCodes();
  const codes = regenerate.data;

  function close() {
    regenerate.reset();
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={(o) => (o ? onOpenChange(true) : close())}>
      <DialogContent mobileFullScreen>
        <DialogHeader>
          <DialogTitle>{t(codes ? 'security.backup_codes.title' : 'security.backup_codes.regenerate')}</DialogTitle>
          {!codes && <DialogDescription>{t('security.backup_codes.regenerate_description')}</DialogDescription>}
        </DialogHeader>
        {codes ? (
          <BackupCodesList codes={codes} onDone={close} />
        ) : (
          <TwoFactorCredentialsForm
            codeKind="any"
            codeLabel={t('security.two_factor.code')}
            submitLabel={t('security.backup_codes.regenerate')}
            isPending={regenerate.isPending}
            error={regenerate.error}
            onCancel={close}
            onSubmit={(credentials) => regenerate.mutate(credentials)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}
