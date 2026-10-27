import { useTranslation } from 'react-i18next';
import { Copy, Download } from 'lucide-react';
import { Button, Text } from '@/components/ui';
import { useToast } from '@/hooks/useToast';
import { triggerDownload } from '@/lib/download';

export interface BackupCodesListProps {
  codes: string[];
  onDone: () => void;
}

/** I codici di recupero appena creati: si vedono solo qui, quindi "Copia" e "Scarica" prima di "Li ho salvati". */
export function BackupCodesList({ codes, onDone }: BackupCodesListProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const text = codes.join('\n');

  async function copy() {
    try {
      await navigator.clipboard.writeText(text);
      toast({ title: t('security.backup_codes.copied'), variant: 'success' });
    } catch {
      // Appunti non disponibili (permesso negato, contesto non sicuro): restano "Scarica" e la lista a schermo.
    }
  }

  return (
    <div className="space-y-4">
      <Text variant="muted">{t('security.backup_codes.description')}</Text>
      <ul
        aria-label={t('security.backup_codes.title')}
        className="grid grid-cols-2 gap-2 rounded-lg bg-secondary p-4 font-mono text-[1.0625rem] tabular-nums"
      >
        {codes.map((code) => (
          <li key={code} className="select-all text-center">
            {code}
          </li>
        ))}
      </ul>
      <div className="grid grid-cols-2 gap-2">
        <Button type="button" variant="outline" onClick={() => void copy()}>
          <Copy className="size-4" aria-hidden />
          {t('security.backup_codes.copy')}
        </Button>
        <Button
          type="button"
          variant="outline"
          onClick={() => triggerDownload(new Blob([text + '\n'], { type: 'text/plain' }), t('security.backup_codes.file_name'))}
        >
          <Download className="size-4" aria-hidden />
          {t('security.backup_codes.download')}
        </Button>
      </div>
      <Button type="button" fullWidth onClick={onDone}>
        {t('security.backup_codes.done')}
      </Button>
    </div>
  );
}
