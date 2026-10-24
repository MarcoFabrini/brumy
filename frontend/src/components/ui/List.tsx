import { forwardRef, useId, type ComponentPropsWithoutRef, type ElementType, type ReactNode } from 'react';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Liste raggruppate in stile iOS ("inset grouped", come le Impostazioni di iPhone).
 *
 * - `ListSection`: titoletto maiuscolo grigio sopra, gruppo bianco con angoli da 10px, nota opzionale sotto.
 * - `ListRow`: riga da almeno 44px con icona in un quadratino colorato, etichetta, sottotitolo, valore a destra e
 *   freccia ">" se porta da qualche parte. Il separatore parte dopo l'icona, come in iOS. Con `as` diventa un link o
 *   un bottone (es. `as={Link} to="/..."`): resta tutto cliccabile.
 *
 * @example
 * <ListSection title="Preferenze">
 *   <ListRow icon={<Globe />} iconClassName="bg-blue-500" label="Lingua" value="Italiano" />
 *   <ListRow as={Link} to="/settings/push" icon={<Bell />} label="Notifiche" chevron />
 * </ListSection>
 */
export interface ListSectionProps extends Omit<ComponentPropsWithoutRef<'section'>, 'title'> {
  /** Titoletto della sezione (diventa l'h2, a meno di `titleAs`). */
  title?: ReactNode;
  titleAs?: ElementType;
  /** Nota sotto il gruppo. */
  footer?: ReactNode;
}

export const ListSection = forwardRef<HTMLElement, ListSectionProps>(
  ({ title, titleAs: Title = 'h2', footer, className, children, ...props }, ref) => {
    // Con un titolo la sezione ne prende il nome (diventa una "region" navigabile per gli screen reader).
    const titleId = useId();
    return (
      <section ref={ref} aria-labelledby={title ? titleId : undefined} className={cn('space-y-1.5', className)} {...props}>
        {title && (
          <Title id={titleId} className="px-4 text-[0.8125rem] font-normal uppercase tracking-wide text-muted-foreground">
            {title}
          </Title>
        )}
        {/* Separatori: ogni riga ha il suo bordo sotto; sparisce sull'ultima riga del gruppo, anche se è avvolta
            (es. in SwipeActions). */}
        <div className="overflow-hidden rounded-xl bg-card [&>:last-child_.list-row-body]:border-b-0">{children}</div>
        {footer && <div className="px-4 text-[0.8125rem] text-muted-foreground">{footer}</div>}
      </section>
    );
  },
);
ListSection.displayName = 'ListSection';

type ListRowOwnProps<C extends ElementType> = {
  as?: C;
  /** Icona (lucide) nel quadratino colorato; `iconClassName` ne dà il colore di sfondo (es. `bg-blue-500`). */
  icon?: ReactNode;
  iconClassName?: string;
  label: ReactNode;
  /** Riga secondaria sotto l'etichetta. */
  description?: ReactNode;
  /** Valore o controllo a destra (testo grigio, interruttore, badge…). */
  value?: ReactNode;
  /** Freccia ">" in fondo: la riga apre un'altra schermata. */
  chevron?: boolean;
  /** Testo rosso centrato o a sinistra per azioni distruttive ("Esci", "Elimina veicolo"). */
  destructive?: boolean;
  className?: string;
};

export type ListRowProps<C extends ElementType = 'div'> = ListRowOwnProps<C> &
  Omit<ComponentPropsWithoutRef<C>, keyof ListRowOwnProps<C>>;

export function ListRow<C extends ElementType = 'div'>({
  as,
  icon,
  iconClassName,
  label,
  description,
  value,
  chevron,
  destructive,
  className,
  ...props
}: ListRowProps<C>) {
  const Comp: ElementType = as ?? 'div';
  const interactive = as !== undefined;
  return (
    <Comp
      className={cn(
        'flex min-h-touch w-full items-center gap-3 pl-4 text-left',
        interactive &&
          'cursor-pointer transition-colors active:bg-accent focus-visible:bg-accent focus-visible:outline-none',
        className,
      )}
      {...props}
    >
      {icon && (
        <span
          aria-hidden="true"
          className={cn(
            'flex size-[29px] shrink-0 items-center justify-center rounded-[7px] text-white [&_svg]:size-[18px]',
            iconClassName ?? 'bg-primary',
          )}
        >
          {icon}
        </span>
      )}
      {/* Il separatore sta sul corpo della riga: parte dopo l'icona (l'ultima riga del gruppo lo perde, vedi ListSection). */}
      <span className="list-row-body flex min-h-touch min-w-0 flex-1 items-center gap-3 border-b border-border py-2.5 pr-4">
        <span className="min-w-0 flex-1">
          <span className={cn('block truncate text-[1.0625rem]', destructive && 'text-destructive-text')}>{label}</span>
          {description && <span className="block text-[0.9375rem] text-muted-foreground">{description}</span>}
        </span>
        {value !== undefined && value !== null && (
          <span className="flex min-w-0 shrink-0 items-center gap-2 text-[1.0625rem] text-muted-foreground">{value}</span>
        )}
        {chevron && <ChevronRight aria-hidden="true" className="size-5 shrink-0 text-muted-foreground/60" />}
      </span>
    </Comp>
  );
}
