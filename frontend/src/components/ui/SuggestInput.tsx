import { forwardRef, useId, useState, type KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { Input } from './Input';

const MAX_SUGGESTIONS = 6;

/** Minuscolo e senza accenti: "caffe" trova "Caffè". */
function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
}

export interface SuggestInputProps {
  id: string;
  value: string;
  onChange: (value: string) => void;
  onBlur?: () => void;
  /** Già ordinati per uso (le più usate prima). */
  suggestions: readonly string[];
  invalid?: boolean;
  maxLength?: number;
  'aria-describedby'?: string;
  /** Chiamata (dopo `onChange`) quando si sceglie un suggerimento: per i campi che raccolgono più valori. */
  onChoose?: (value: string) => void;
  /** Invio senza un suggerimento evidenziato: se presente, Invio non invia il form. */
  onEnter?: () => void;
}

/**
 * Campo di testo libero che suggerisce i valori già usati (combobox ARIA con listbox).
 * La lista sta sotto il campo, non in un portale, così resta nello schermo su iPhone.
 */
export const SuggestInput = forwardRef<HTMLInputElement, SuggestInputProps>(function SuggestInput(
  { id, value, onChange, onBlur, suggestions, invalid, maxLength, 'aria-describedby': describedBy, onChoose, onEnter },
  ref,
) {
  const listId = useId();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);

  const needle = fold(value.trim());
  const visible = (needle === ''
    ? suggestions
    : suggestions.filter((s) => fold(s).includes(needle) && fold(s) !== needle)
  ).slice(0, MAX_SUGGESTIONS);
  const expanded = open && visible.length > 0;
  const optionId = (index: number) => `${listId}-${index}`;

  const choose = (name: string) => {
    onChange(name);
    onChoose?.(name);
    setOpen(false);
    setActive(-1);
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (visible.length === 0) return;
      event.preventDefault();
      if (!expanded) {
        setOpen(true);
        return;
      }
      const step = event.key === 'ArrowDown' ? 1 : -1;
      setActive((active + step + visible.length + (active === -1 && step === -1 ? 1 : 0)) % visible.length);
    } else if (event.key === 'Enter' && expanded && active >= 0) {
      event.preventDefault();
      choose(visible[active] as string);
    } else if (event.key === 'Enter' && onEnter) {
      event.preventDefault();
      onEnter();
    } else if (event.key === 'Escape' && expanded) {
      // Chiude solo la lista, non il Sheet/Dialog che contiene il form.
      event.preventDefault();
      event.stopPropagation();
      setOpen(false);
      setActive(-1);
    }
  };

  return (
    <div className="relative">
      <Input
        ref={ref}
        id={id}
        role="combobox"
        aria-expanded={expanded}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={expanded && active >= 0 ? optionId(active) : undefined}
        aria-describedby={describedBy}
        autoComplete="off"
        value={value}
        maxLength={maxLength}
        invalid={invalid}
        onFocus={() => setOpen(true)}
        onChange={(event) => {
          onChange(event.target.value);
          setOpen(true);
          setActive(-1);
        }}
        onBlur={() => {
          setOpen(false);
          setActive(-1);
          onBlur?.();
        }}
        onKeyDown={onKeyDown}
      />
      <ul
        id={listId}
        role="listbox"
        hidden={!expanded}
        className="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-xl border border-black/10 bg-card shadow-lg dark:border-white/10"
      >
        {visible.map((name, index) => (
          <li
            key={name}
            id={optionId(index)}
            role="option"
            aria-selected={index === active}
            // Niente perdita di focus prima della scelta, altrimenti la lista si chiude sul blur.
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => choose(name)}
            className={cn(
              'min-h-touch cursor-pointer px-4 py-2.5 text-[1.0625rem] leading-6 text-foreground',
              'border-t border-black/10 first:border-t-0 dark:border-white/10',
              index === active && 'bg-primary/10',
            )}
          >
            {name}
          </li>
        ))}
      </ul>
    </div>
  );
});
