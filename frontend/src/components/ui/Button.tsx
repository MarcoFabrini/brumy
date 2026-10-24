import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

/**
 * Button atom — il button standard di tutta l'app.
 *
 * Variants: primary, secondary, ghost, destructive, outline, link
 * Sizes: sm, md (default), lg, icon
 *
 * Touch target sempre >= 44px (Apple HIG / Material).
 *
 * @example
 * <Button variant="primary">Salva</Button>
 * <Button variant="ghost" size="icon"><Settings /></Button>
 * <Button asChild><Link to="/login">Accedi</Link></Button>
 */
export const buttonVariants = cva(
  // iOS: angoli da 10px, testo semibold, nessuna ombra; alla pressione il pulsante si attenua (active:opacity).
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-[10px] text-[0.9375rem] font-semibold ' +
    'transition-[background-color,opacity] active:opacity-70 ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ' +
    'disabled:pointer-events-none disabled:opacity-50 ' +
    "[&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        primary: 'bg-primary text-primary-foreground hover:bg-primary/90',
        secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        ghost: 'hover:bg-accent hover:text-accent-foreground',
        destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
        // Pulsante "grigio" di iOS: riempimento tenue, testo nel colore dell'app, niente bordo.
        outline: 'bg-secondary text-primary hover:bg-secondary/80',
        link: 'text-primary underline-offset-4 hover:underline',
      },
      size: {
        sm: 'h-9 px-3 text-[0.8125rem] min-h-touch',
        md: 'h-11 px-4 py-2 min-h-touch',
        lg: 'h-[3.125rem] px-8 text-[1.0625rem] min-h-touch',
        icon: 'h-11 w-11 min-h-touch min-w-touch',
      },
      fullWidth: {
        true: 'w-full',
        false: '',
      },
    },
    defaultVariants: {
      variant: 'primary',
      size: 'md',
      fullWidth: false,
    },
  },
);

export interface ButtonProps
  extends ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  /** Render como child (Radix Slot) — utile per <Link>, etc. */
  asChild?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, fullWidth, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    return (
      <Comp
        ref={ref}
        className={cn(buttonVariants({ variant, size, fullWidth, className }))}
        {...props}
      />
    );
  },
);
Button.displayName = 'Button';
