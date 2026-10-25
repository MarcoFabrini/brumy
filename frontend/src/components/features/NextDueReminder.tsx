import { useEffect, useState } from 'react';
import { useController, type Control, type FieldValues, type Path } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { FormField, Input, SwitchCell } from '@/components/ui';

export interface NextDueReminderProps<T extends FieldValues> {
  control: Control<T>;
  /** Campo della data (`nextDueDate`): null = nessun promemoria. */
  name: Path<T>;
  /** Data proposta (dalla data del record più il periodo); segue il record finché l'utente non la cambia. */
  proposedDate: string;
}

/**
 * "Ricordami la prossima scadenza" per i record periodici (revisione, assicurazione, bollo): acceso di default con
 * la data proposta, modificabile. Salvando, il server aggiorna o crea il promemoria.
 */
export function NextDueReminder<T extends FieldValues>({ control, name, proposedDate }: NextDueReminderProps<T>) {
  const { t } = useTranslation();
  const { field, fieldState } = useController({ control, name });
  const value = field.value as string | null | undefined;
  const [edited, setEdited] = useState(false);
  const enabled = value !== null;

  // Finché la data non è stata toccata segue quella proposta (cambia la data del record → cambia la scadenza)
  useEffect(() => {
    if (enabled && !edited && value !== proposedDate) field.onChange(proposedDate);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [proposedDate, enabled, edited]);

  return (
    <div className="space-y-3">
      <SwitchCell
        label={t('reminder.remind_next')}
        checked={enabled}
        onCheckedChange={(on) => {
          setEdited(false);
          field.onChange(on ? proposedDate : null);
        }}
      />
      {enabled && (
        <FormField label={t('reminder.next_due_date')} error={fieldState.error?.message ? t(`errors.${fieldState.error.message}`) : undefined}>
          {(id) => (
            <Input
              id={id}
              type="date"
              value={value ?? ''}
              invalid={!!fieldState.error}
              onChange={(event) => {
                setEdited(true);
                field.onChange(event.target.value);
              }}
              onBlur={field.onBlur}
            />
          )}
        </FormField>
      )}
    </div>
  );
}
