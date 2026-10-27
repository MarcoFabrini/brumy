import { useTranslation } from 'react-i18next';
import { Copy, ExternalLink } from 'lucide-react';
import {
  Alert,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  Spinner,
  Text,
} from '@/components/ui';
import type { TwoFactorSetup } from '@/api/types/auth';
import { BackupCodesList } from './BackupCodesList';
import { TwoFactorCredentialsForm } from './TwoFactorCredentialsForm';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useEnableTwoFactor } from '@/hooks/useTwoFactor';
import { useToast } from '@/hooks/useToast';

export interface TwoFactorSetupDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Configurazione appena iniziata (`POST /api/auth/2fa/setup`, la avvia chi apre il dialog). */
  setup: { data: TwoFactorSetup | undefined; isPending: boolean; error: unknown };
}

/**
 * Attivazione della verifica in due passaggi: QR da inquadrare (o chiave da inserire a mano, o link che apre l'app),
 * poi password + codice dell'app. Riuscita: i codici di recupero, mostrati una volta sola.
 */
export function TwoFactorSetupDialog({ open, onOpenChange, setup }: TwoFactorSetupDialogProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const enable = useEnableTwoFactor();
  const codes = enable.data;

  function close() {
    enable.reset();
    onOpenChange(false);
  }

  async function copyKey(secret: string) {
    try {
      await navigator.clipboard.writeText(secret);
      toast({ title: t('security.two_factor.key_copied'), variant: 'success' });
    } catch {
      // Appunti non disponibili: la chiave resta selezionabile a schermo.
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => (o ? onOpenChange(true) : close())}>
      <DialogContent mobileFullScreen>
        <DialogHeader>
          <DialogTitle>{t(codes ? 'security.backup_codes.title' : 'security.two_factor.setup_title')}</DialogTitle>
          {!codes && <DialogDescription>{t('security.two_factor.scan')}</DialogDescription>}
        </DialogHeader>

        {codes ? (
          <BackupCodesList codes={codes} onDone={close} />
        ) : setup.isPending ? (
          <div className="flex justify-center py-8">
            <Spinner size="lg" />
          </div>
        ) : setup.data ? (
          <div className="space-y-5">
            <img
              src={setup.data.qrCode}
              alt={t('security.two_factor.qr_alt')}
              className="mx-auto size-48 rounded-lg bg-white p-2"
            />
            <div className="space-y-1">
              <Text variant="muted">{t('security.two_factor.manual_key')}</Text>
              <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 select-all break-all rounded-md bg-secondary px-3 py-2 font-mono text-sm">
                  {setup.data.secret}
                </code>
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  aria-label={t('security.two_factor.copy_key')}
                  onClick={() => void copyKey(setup.data?.secret ?? '')}
                >
                  <Copy className="size-4" aria-hidden />
                </Button>
              </div>
              <Button asChild variant="link" className="px-0">
                <a href={setup.data.otpauthUri}>
                  <ExternalLink className="size-4" aria-hidden />
                  {t('security.two_factor.open_app')}
                </a>
              </Button>
            </div>
            <Text variant="muted">{t('security.two_factor.confirm_description')}</Text>
            <TwoFactorCredentialsForm
              codeKind="app"
              codeLabel={t('security.two_factor.confirm_code')}
              submitLabel={t('security.two_factor.enable')}
              isPending={enable.isPending}
              error={enable.error}
              onCancel={close}
              onSubmit={(credentials) =>
                enable.mutate(credentials, {
                  onSuccess: () => toast({ title: t('security.two_factor.enabled_toast'), variant: 'success' }),
                })
              }
            />
          </div>
        ) : (
          setup.error != null && <Alert variant="error">{errorMessage(setup.error)}</Alert>
        )}
      </DialogContent>
    </Dialog>
  );
}
