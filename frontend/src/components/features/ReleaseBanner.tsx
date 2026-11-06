import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Download, X } from 'lucide-react';
import { useAbout } from '@/hooks/useAbout';

const DISMISSED_KEY = 'brumy.release.dismissed';

function dismissedVersion(): string | null {
  try {
    return localStorage.getItem(DISMISSED_KEY);
  } catch {
    return null;
  }
}

/**
 * ReleaseBanner — sulla Home, solo per l'amministratore dell'istanza (lo decide il server con `update` di
 * `/api/about`): è uscita su GitHub una versione più nuova di quella installata. Si chiude una volta per versione
 * (ricordato su questo dispositivo); la riga "Aggiornamento disponibile" del Profilo resta finché il server non si
 * aggiorna.
 */
export function ReleaseBanner() {
  const { t } = useTranslation();
  const update = useAbout().data?.update ?? null;
  const [dismissed, setDismissed] = useState(dismissedVersion);

  if (!update || dismissed === update.version) return null;

  function dismiss(version: string) {
    try {
      localStorage.setItem(DISMISSED_KEY, version);
    } catch {
      // Storage non disponibile: si chiude solo fino al prossimo avvio
    }
    setDismissed(version);
  }

  return (
    <div role="status" className="flex items-center gap-3 rounded-xl bg-card py-1 pl-4 text-sm">
      <Download className="size-5 shrink-0 text-primary" aria-hidden="true" />
      <span className="flex-1 py-2">
        {t('settings.about.release_banner', { version: update.version })}{' '}
        <a
          href={update.url}
          target="_blank"
          rel="noopener noreferrer"
          className="font-medium text-primary underline underline-offset-2"
        >
          {t('settings.about.release_notes')}
        </a>
      </span>
      <button
        type="button"
        onClick={() => dismiss(update.version)}
        aria-label={t('actions.close')}
        className="inline-flex min-h-touch min-w-touch shrink-0 items-center justify-center rounded-lg text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <X className="size-5" aria-hidden="true" />
      </button>
    </div>
  );
}
