import { forwardRef, type InputHTMLAttributes } from 'react';
import { Search, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from './Button';

/**
 * SearchInput atom — barra di ricerca in stile iOS: lente a sinistra, superficie piena, "x" per svuotare visibile solo
 * con del testo (quella nativa di `type="search"` è nascosta, per non averne due). `text-base` evita lo zoom di iOS.
 * Nessun testo proprio: etichetta (`aria-label`), `placeholder` e `clearLabel` arrivano tradotti dal chiamante.
 *
 * @example
 * <SearchInput aria-label={t('search.label')} clearLabel={t('search.clear')} value={q} onValueChange={setQ} onClear={clear} />
 */
export interface SearchInputProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'value' | 'onChange' | 'defaultValue'> {
  value: string;
  onValueChange: (value: string) => void;
  onClear: () => void;
  /** Nome accessibile del pulsante che svuota il campo. */
  clearLabel: string;
}

export const SearchInput = forwardRef<HTMLInputElement, SearchInputProps>(
  ({ value, onValueChange, onClear, clearLabel, className, ...props }, ref) => (
    <div className={cn('relative', className)}>
      <Search
        aria-hidden="true"
        className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
      />
      <input
        ref={ref}
        type="search"
        enterKeyHint="search"
        autoComplete="off"
        spellCheck={false}
        value={value}
        onChange={(e) => onValueChange(e.target.value)}
        className={cn(
          'flex min-h-touch w-full rounded-lg border-0 bg-secondary py-2 pl-9 pr-11 text-base text-foreground',
          'placeholder:text-muted-foreground',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          '[&::-webkit-search-cancel-button]:appearance-none [&::-webkit-search-decoration]:appearance-none',
        )}
        {...props}
      />
      {value !== '' && (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label={clearLabel}
          onClick={onClear}
          className="absolute right-0 top-1/2 -translate-y-1/2 text-muted-foreground hover:bg-transparent"
        >
          <XCircle />
        </Button>
      )}
    </div>
  ),
);
SearchInput.displayName = 'SearchInput';
