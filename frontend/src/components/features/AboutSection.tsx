import { useTranslation } from 'react-i18next';
import { Download, Info } from 'lucide-react';
import { ListRow, ListSection } from '@/components/ui';
import { useAbout } from '@/hooks/useAbout';

/**
 * AboutSection — versione dell'app installata e, solo per l'amministratore dell'istanza (lo decide il server), la
 * versione più recente da scaricare con il link alle note su GitHub. Solo un avviso: l'aggiornamento si fa sul server.
 */
export function AboutSection() {
  const { t } = useTranslation();
  const about = useAbout();
  if (!about.data) return null;
  const { version, update } = about.data;

  return (
    <ListSection title={t('settings.about.title')}>
      <ListRow
        icon={<Info />}
        iconClassName="bg-[#8e8e93]"
        label={t('settings.about.version')}
        value={version === 'dev' ? t('settings.about.dev') : version}
      />
      {update && (
        <ListRow
          as="a"
          href={update.url}
          target="_blank"
          rel="noopener noreferrer"
          icon={<Download />}
          iconClassName="bg-[#34c759]"
          label={t('settings.about.update_available')}
          value={update.version}
          chevron
        />
      )}
    </ListSection>
  );
}
