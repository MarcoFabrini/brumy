import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus, X } from 'lucide-react';
import { Button, SuggestInput } from '@/components/ui';
import { nameKey } from '@/lib/componentNames';

export interface ComponentsInputProps {
  id: string;
  /** Nomi già scelti. */
  value: string[];
  onChange: (value: string[]) => void;
  /** Nomi dei componenti del veicolo, suggeriti mentre si scrive. */
  suggestions: readonly string[];
  invalid?: boolean;
}

/**
 * Più componenti in un campo: ogni nome scelto (dai suggerimenti, con Invio o con "+") diventa una chip
 * rimovibile. Un nome che il veicolo non ha ancora crea il componente al salvataggio del lavoro.
 */
export function ComponentsInput({ id, value, onChange, suggestions, invalid }: ComponentsInputProps) {
  const { t } = useTranslation();
  const [draft, setDraft] = useState('');
  const taken = new Set(value.map(nameKey));

  const add = (name: string) => {
    const clean = name.trim().replace(/\s+/gu, ' ');
    if (clean !== '' && !taken.has(nameKey(clean))) onChange([...value, clean]);
    setDraft('');
  };

  return (
    <div className="space-y-2">
      {value.length > 0 && (
        <ul className="flex flex-wrap gap-2" aria-label={t('component.selected')}>
          {value.map((name) => (
            <li key={nameKey(name)}>
              <span className="inline-flex items-center gap-1 rounded-full bg-secondary py-1 pl-3 pr-1 text-[0.9375rem]">
                {name}
                <button
                  type="button"
                  className="inline-flex size-6 items-center justify-center rounded-full text-muted-foreground hover:bg-muted"
                  aria-label={t('component.remove', { name })}
                  onClick={() => onChange(value.filter((v) => v !== name))}
                >
                  <X className="size-4" />
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
      <div className="flex gap-2">
        <div className="min-w-0 flex-1">
          <SuggestInput
            id={id}
            value={draft}
            onChange={setDraft}
            onChoose={add}
            onEnter={() => add(draft)}
            onBlur={() => add(draft)}
            suggestions={suggestions.filter((s) => !taken.has(nameKey(s)))}
            invalid={invalid}
            maxLength={200}
          />
        </div>
        <Button type="button" variant="outline" aria-label={t('component.add')} disabled={draft.trim() === ''} onClick={() => add(draft)}>
          <Plus />
        </Button>
      </div>
    </div>
  );
}
