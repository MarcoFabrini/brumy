import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ListRestart, ShieldCheck } from 'lucide-react';
import { ListRow, ListSection } from '@/components/ui';
import type { User } from '@/api/types/auth';
import { BackupCodesDialog } from './BackupCodesDialog';
import { TwoFactorDisableDialog } from './TwoFactorDisableDialog';
import { TwoFactorSetupDialog } from './TwoFactorSetupDialog';
import { useBeginTwoFactorSetup } from '@/hooks/useTwoFactor';

export interface SecuritySectionProps {
  user: Pick<User, 'twoFactorEnabled' | 'backupCodesRemaining'>;
}

type DialogKey = 'setup' | 'disable' | 'backup_codes' | null;

/**
 * Profilo › Sicurezza: verifica in due passaggi (attiva/non attiva) e, se attiva, i codici di recupero rimasti.
 * Toccare la riga da spenta avvia la configurazione (nuovo segreto + QR) e apre il dialog che la conclude.
 */
export function SecuritySection({ user }: SecuritySectionProps) {
  const { t } = useTranslation();
  const [dialog, setDialog] = useState<DialogKey>(null);
  const setup = useBeginTwoFactorSetup();

  function onTwoFactorRow() {
    if (user.twoFactorEnabled) {
      setDialog('disable');
      return;
    }
    setup.mutate();
    setDialog('setup');
  }

  return (
    <>
      <ListSection title={t('security.title')}>
        <ListRow
          as="button"
          type="button"
          onClick={onTwoFactorRow}
          icon={<ShieldCheck />}
          iconClassName="bg-[#34c759]"
          label={t('security.two_factor.label')}
          value={t(user.twoFactorEnabled ? 'security.two_factor.on' : 'security.two_factor.off')}
          chevron
        />
        {user.twoFactorEnabled && (
          <ListRow
            as="button"
            type="button"
            onClick={() => setDialog('backup_codes')}
            icon={<ListRestart />}
            iconClassName="bg-[#8e8e93]"
            label={t('security.backup_codes.title')}
            value={t('security.backup_codes.remaining', { count: user.backupCodesRemaining })}
            chevron
          />
        )}
      </ListSection>

      <TwoFactorSetupDialog
        open={dialog === 'setup'}
        onOpenChange={(o) => setDialog(o ? 'setup' : null)}
        setup={{ data: setup.data, isPending: setup.isPending, error: setup.error }}
      />
      <TwoFactorDisableDialog open={dialog === 'disable'} onOpenChange={(o) => setDialog(o ? 'disable' : null)} />
      <BackupCodesDialog open={dialog === 'backup_codes'} onOpenChange={(o) => setDialog(o ? 'backup_codes' : null)} />
    </>
  );
}
