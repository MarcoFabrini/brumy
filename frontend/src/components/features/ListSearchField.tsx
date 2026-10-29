import { useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { SearchInput } from '@/components/ui';
import { SEARCH_MAX_LENGTH } from '@/lib/listSearch';

/**
 * ListSearchField feature — il campo di ricerca sopra un elenco. Il valore arriva dalla pagina (`useListSearch`,
 * cioè `?q=` dell'URL); "Invio"/"Cerca" della tastiera non ricarica nulla (la ricerca parte già scrivendo), chiude
 * solo la tastiera su mobile.
 *
 * @example
 * const search = useListSearch(LIST_SEARCH_FAMILIES.refueling);
 * <ListSearchField value={search.query} onChange={search.setQuery} onClear={search.clear} />
 */
export interface ListSearchFieldProps {
  value: string;
  onChange: (value: string) => void;
  onClear: () => void;
}

export function ListSearchField({ value, onChange, onClear }: ListSearchFieldProps) {
  const { t } = useTranslation();
  const inputRef = useRef<HTMLInputElement>(null);

  return (
    <form
      role="search"
      onSubmit={(e) => {
        e.preventDefault();
        inputRef.current?.blur();
      }}
    >
      <SearchInput
        ref={inputRef}
        aria-label={t('search.label')}
        placeholder={t('search.placeholder')}
        clearLabel={t('search.clear')}
        maxLength={SEARCH_MAX_LENGTH}
        value={value}
        onValueChange={onChange}
        onClear={onClear}
      />
    </form>
  );
}
