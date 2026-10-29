import type { ReactNode } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Trash2 } from 'lucide-react';
import { Button, ListRow, ListSection } from '@/components/ui';

/**
 * "Modifica" come nella navigation bar iOS: testo blu, non un'icona in un riquadro. Porta con sé lo `state` del
 * dettaglio (l'elenco di provenienza con la sua ricerca), che la pagina di modifica riconsegna tornando al dettaglio.
 */
export function EditLink({ to }: { to: string }) {
  const { t } = useTranslation();
  const { state } = useLocation();
  return (
    <Button asChild variant="ghost" className="-mr-3 px-3 text-[1.0625rem] font-normal text-primary hover:bg-transparent">
      <Link to={to} state={state}>
        {t('actions.edit')}
      </Link>
    </Button>
  );
}

/** Eliminazione come riga rossa in fondo alla pagina (iOS), lontana dalle azioni frequenti. */
export function DeleteRow({ label, onDelete }: { label: string; onDelete: () => void }) {
  return (
    <ListSection>
      <ListRow
        as="button"
        type="button"
        onClick={onDelete}
        destructive
        className="justify-center"
        label={
          <span className="flex items-center justify-center gap-2">
            <Trash2 className="size-5" aria-hidden="true" />
            {label}
          </span>
        }
      />
    </ListSection>
  );
}

/**
 * Dati di un record come lista iOS, con la semantica di una lista di definizioni: ogni riga è un `<dt>` (etichetta)
 * seguito dal suo `<dd>` (valore a destra), così gli screen reader li leggono in coppia.
 */
export function InfoList({ title, footer, children }: { title?: string; footer?: string; children: ReactNode }) {
  // Stesso aspetto di ListSection, ma il gruppo bianco è il <dl> stesso: così il separatore sparisce solo
  // sull'ultima riga.
  return (
    <section className="space-y-1.5">
      {title && <h2 className="px-4 text-[0.8125rem] uppercase tracking-wide text-muted-foreground">{title}</h2>}
      <dl className="overflow-hidden rounded-xl bg-card [&>:last-child_.list-row-body]:border-b-0">{children}</dl>
      {footer && <p className="px-4 text-[0.8125rem] text-muted-foreground">{footer}</p>}
    </section>
  );
}

export function InfoRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex min-h-touch items-center pl-4">
      <div className="list-row-body flex min-h-touch min-w-0 flex-1 items-center justify-between gap-3 border-b py-2.5 pr-4">
        <dt className="shrink-0 text-[1.0625rem]">{label}</dt>
        <dd className="flex min-w-0 items-center justify-end gap-1 text-right text-[1.0625rem] text-muted-foreground">{value}</dd>
      </div>
    </div>
  );
}

/** Le note di una scheda in fondo all'InfoList: testo libero su più righe, l'etichetta solo per gli screen reader. */
export function NotesRow({ label, notes }: { label: string; notes: string | null | undefined }) {
  if (!notes) return null;
  return (
    <div className="px-4 py-3">
      <dt className="sr-only">{label}</dt>
      <dd className="whitespace-pre-wrap text-[0.9375rem] text-muted-foreground">{notes}</dd>
    </div>
  );
}
