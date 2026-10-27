import { useTranslation } from 'react-i18next';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui';
import { TwoFactorCredentialsForm } from './TwoFactorCredentialsForm';
import { useDisableTwoFactor } from '@/hooks/useTwoFactor';
import { useToast } from '@/hooks/useToast';

export interface TwoFactorDisableDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/** Disattivazione: password + codice dell'app o di recupero. */
export function TwoFactorDisableDialog({ open, onOpenChange }: TwoFactorDisableDialogProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const disable = useDisableTwoFactor();

  function close() {
    disable.reset();
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={(o) => (o ? onOpenChange(true) : close())}>
      <DialogContent mobileFullScreen>
        <DialogHeader>
          <DialogTitle>{t('security.two_factor.disable_title')}</DialogTitle>
          <DialogDescription>{t('security.two_factor.disable_description')}</DialogDescription>
        </DialogHeader>
        <TwoFactorCredentialsForm
          codeKind="any"
          codeLabel={t('security.two_factor.code')}
          submitLabel={t('security.two_factor.disable')}
          isPending={disable.isPending}
          error={disable.error}
          onCancel={close}
          onSubmit={(credentials) =>
            disable.mutate(credentials, {
              onSuccess: () => {
                toast({ title: t('security.two_factor.disabled_toast'), variant: 'success' });
                close();
              },
            })
          }
        />
      </DialogContent>
    </Dialog>
  );
}
