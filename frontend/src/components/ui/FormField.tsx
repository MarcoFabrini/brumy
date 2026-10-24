import { cloneElement, isValidElement, useId, type ReactElement, type ReactNode } from 'react';
import { Label } from './Label';
import { cn } from '@/lib/utils';

/**
 * FormField molecule — Label + child input + error/hint sotto.
 *
 * Genera id univoco automaticamente, lo passa come prop al child via render-prop.
 * Mantiene a11y consistente: aria-describedby per hint/error.
 *
 * @example
 * <FormField label="Email" error={errors.email?.message}>
 *   {(id) => <Input id={id} type="email" {...register('email')} />}
 * </FormField>
 *
 * <FormField label="Password" hint="Almeno 8 caratteri">
 *   {(id) => <Input id={id} type="password" />}
 * </FormField>
 */
export interface FormFieldProps {
  label: string;
  /** Render prop: riceve l'id generato e lo passa all'input child */
  children: (id: string) => ReactNode;
  /** Messaggio errore (mostrato in rosso sotto l'input) */
  error?: string;
  /** Hint sotto l'input (testo grigio). Non mostrato se error è presente. */
  hint?: string;
  /** Mostra asterisco rosso sul label */
  required?: boolean;
  className?: string;
}

export function FormField({ label, children, error, hint, required, className }: FormFieldProps) {
  const id = useId();
  const messageId = error || hint ? `${id}-message` : undefined;

  return (
    <div className={cn('space-y-1', className)}>
      {/* Cella iOS: etichetta piccola grigia in alto e valore senza bordo, nella stessa superficie arrotondata; la
          cella si accende quando il campo ha il focus, si bordano di rosso quando c'è un errore. */}
      <div
        className={cn(
          'rounded-xl bg-card px-4 pb-1 pt-2 transition-shadow focus-within:ring-2 focus-within:ring-ring/60',
          CELL_FIELDS,
          error && 'ring-1 ring-destructive-text focus-within:ring-destructive-text',
        )}
      >
        <Label htmlFor={id} className="text-[0.8125rem] font-normal text-muted-foreground">
          {label}
          {required && <span className="ml-0.5 text-destructive-text">*</span>}
        </Label>
        {withDescription(children(id), messageId)}
      </div>
      {error ? (
        <p id={messageId} role="alert" className="px-4 text-[0.8125rem] font-medium text-destructive-text">
          {error}
        </p>
      ) : hint ? (
        <p id={messageId} className="px-4 text-[0.8125rem] text-muted-foreground">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

/**
 * Dentro la cella i campi (input, textarea, trigger dei Select, anche annidati in un componente) perdono bordo,
 * sfondo, padding laterale e anello: li dà la cella. Selettori discendenti, così vale qualunque sia il figlio.
 */
const CELL_FIELDS = [
  '[&_input:not([type=checkbox]):not([type=file])]:h-auto [&_input:not([type=checkbox]):not([type=file])]:min-h-[2.25rem]',
  '[&_input:not([type=checkbox]):not([type=file])]:rounded-none [&_input:not([type=checkbox]):not([type=file])]:border-0',
  '[&_input:not([type=checkbox]):not([type=file])]:bg-transparent [&_input:not([type=checkbox]):not([type=file])]:px-0',
  '[&_input:not([type=checkbox]):not([type=file])]:py-1 [&_input:not([type=checkbox]):not([type=file])]:text-[1.0625rem]',
  '[&_input:not([type=checkbox]):not([type=file])]:shadow-none [&_input:not([type=checkbox]):not([type=file])]:ring-0',
  '[&_textarea]:rounded-none [&_textarea]:border-0 [&_textarea]:bg-transparent [&_textarea]:px-0 [&_textarea]:text-[1.0625rem] [&_textarea]:shadow-none [&_textarea]:ring-0',
  '[&_[role=combobox]]:h-auto [&_[role=combobox]]:min-h-[2.25rem] [&_[role=combobox]]:rounded-none [&_[role=combobox]]:border-0',
  '[&_[role=combobox]]:bg-transparent [&_[role=combobox]]:px-0 [&_[role=combobox]]:text-[1.0625rem] [&_[role=combobox]]:shadow-none [&_[role=combobox]]:ring-0',
].join(' ');

/**
 * Lo screen reader legge hint/errore insieme al campo solo se `aria-describedby` sta sull'input
 * stesso, non su un wrapper. Il child è di norma un solo elemento (Input, Textarea, ...): se ne ha già
 * uno proprio lo lasciamo stare, altrimenti ricevi quello del messaggio.
 */
function withDescription(node: ReactNode, messageId: string | undefined): ReactNode {
  if (!messageId || !isValidElement(node)) return node;
  const element = node as ReactElement<{ 'aria-describedby'?: string }>;
  if (element.props['aria-describedby']) return node;

  return cloneElement(element, { 'aria-describedby': messageId });
}
