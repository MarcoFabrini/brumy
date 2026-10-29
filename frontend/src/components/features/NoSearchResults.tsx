import { useTranslation } from 'react-i18next';
import { SearchX } from 'lucide-react';
import { Button } from '@/components/ui';
import { EmptyState } from './EmptyState';

/**
 * NoSearchResults feature — stato vuoto di un elenco quando la ricerca non trova nulla: la parola cercata e un
 * pulsante per svuotare la ricerca. `role="status"` perché lo screen reader annunci il risultato.
 */
export interface NoSearchResultsProps {
  query: string;
  onClear: () => void;
}

export function NoSearchResults({ query, onClear }: NoSearchResultsProps) {
  const { t } = useTranslation();

  return (
    <div role="status">
      <EmptyState
        Icon={SearchX}
        title={t('search.no_results.title', { query })}
        description={t('search.no_results.description')}
        action={
          <Button type="button" variant="outline" onClick={onClear}>
            {t('search.clear')}
          </Button>
        }
      />
    </div>
  );
}
