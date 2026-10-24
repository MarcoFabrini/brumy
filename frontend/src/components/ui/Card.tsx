import { forwardRef, type ElementType, type HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Card primitive — container with border, background, padding.
 *
 * Compose con sub-components: CardHeader, CardTitle, CardDescription,
 * CardContent, CardFooter.
 *
 * @example
 * <Card>
 *   <CardHeader>
 *     <CardTitle>Veicolo</CardTitle>
 *     <CardDescription>Dati base</CardDescription>
 *   </CardHeader>
 *   <CardContent>...</CardContent>
 *   <CardFooter>...</CardFooter>
 * </Card>
 */
export const Card = forwardRef<HTMLDivElement, HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn('rounded-xl bg-card text-card-foreground', className)}
      {...props}
    />
  ),
);
Card.displayName = 'Card';

export const CardHeader = forwardRef<HTMLDivElement, HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('flex flex-col space-y-1.5 p-4 md:p-6', className)} {...props} />
  ),
);
CardHeader.displayName = 'CardHeader';

export interface CardTitleProps extends HTMLAttributes<HTMLHeadingElement> {
  /** Tag del titolo (default `div`, senza semantica): `h2`/`h3` dove la card è una sezione della pagina. */
  as?: ElementType;
}

export const CardTitle = forwardRef<HTMLElement, CardTitleProps>(
  ({ className, as: Component = 'div', ...props }, ref) => (
    <Component
      ref={ref}
      className={cn('text-base font-semibold leading-none tracking-tight md:text-lg', className)}
      {...props}
    />
  ),
);
CardTitle.displayName = 'CardTitle';

export const CardDescription = forwardRef<HTMLDivElement, HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('text-sm text-muted-foreground', className)} {...props} />
  ),
);
CardDescription.displayName = 'CardDescription';

export interface CardContentProps extends HTMLAttributes<HTMLDivElement> {
  /**
   * Card senza CardHeader (riga di lista, stat, link): padding uniforme p-4
   * anche da desktop. Di default il padding-top è 0 perché il contenuto sta
   * sotto un header — in una card standalone spingerebbe tutto in alto.
   * NB: non basta sovrascrivere con `p-4` nel className: tailwind-merge non
   * toglie le varianti `md:p-6 md:pt-0` del default.
   */
  standalone?: boolean;
}

export const CardContent = forwardRef<HTMLDivElement, CardContentProps>(
  ({ className, standalone = false, ...props }, ref) => (
    <div
      ref={ref}
      className={cn(standalone ? 'p-4' : 'p-4 pt-0 md:p-6 md:pt-0', className)}
      {...props}
    />
  ),
);
CardContent.displayName = 'CardContent';

export const CardFooter = forwardRef<HTMLDivElement, HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn('flex items-center p-4 pt-0 md:p-6 md:pt-0', className)}
      {...props}
    />
  ),
);
CardFooter.displayName = 'CardFooter';
