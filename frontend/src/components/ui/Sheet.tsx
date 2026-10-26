import { forwardRef, useMemo, useRef, type ComponentPropsWithoutRef, type ElementRef, type HTMLAttributes } from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { cva, type VariantProps } from 'class-variance-authority';
import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn, mergeRefs } from '@/lib/utils';
import { useSwipeToDismiss } from '@/hooks/useSwipeToDismiss';

/**
 * Sheet molecule — side drawer (left/right/top/bottom).
 *
 * Uso ideale mobile: sheet from-bottom invece di Dialog centered.
 *
 * @example
 * <Sheet>
 *   <SheetTrigger asChild><Button>Filtra</Button></SheetTrigger>
 *   <SheetContent side="bottom">
 *     <SheetHeader>
 *       <SheetTitle>Filtri</SheetTitle>
 *     </SheetHeader>
 *     ...
 *   </SheetContent>
 * </Sheet>
 */
export const Sheet = DialogPrimitive.Root;
export const SheetTrigger = DialogPrimitive.Trigger;
export const SheetClose = DialogPrimitive.Close;
export const SheetPortal = DialogPrimitive.Portal;

export const SheetOverlay = forwardRef<
  ElementRef<typeof DialogPrimitive.Overlay>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn(
      'fixed inset-0 z-50 bg-black/40',
      'data-[state=open]:animate-in data-[state=open]:fade-in-0',
      'data-[state=closed]:animate-out data-[state=closed]:fade-out-0',
      // Lo sfondo si scurisce e si schiarisce col passo del foglio, non di colpo
      'duration-300',
      className,
    )}
    {...props}
  />
));
SheetOverlay.displayName = DialogPrimitive.Overlay.displayName;

const sheetVariants = cva(
  'fixed z-50 gap-4 bg-background p-6 shadow-lg transition data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:duration-300 data-[state=open]:duration-500',
  {
    variants: {
      side: {
        top: 'inset-x-0 top-0 border-b data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top pt-safe',
        bottom:
          // Foglio iOS: angoli superiori da 12px e maniglia grigia in cima (pseudo-elemento, decorativa).
          'inset-x-0 bottom-0 data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom [animation-timing-function:cubic-bezier(0.32,0.72,0,1)] pb-safe rounded-t-[12px] pt-7 before:absolute before:left-1/2 before:top-[7px] before:h-[5px] before:w-9 before:-translate-x-1/2 before:rounded-full before:bg-muted-foreground/40 before:content-[""]',
        left: 'inset-y-0 left-0 h-full w-3/4 border-r data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left sm:max-w-sm',
        right:
          'inset-y-0 right-0 h-full w-3/4 border-l data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right sm:max-w-sm',
      },
    },
    defaultVariants: { side: 'right' },
  },
);

export interface SheetContentProps
  extends ComponentPropsWithoutRef<typeof DialogPrimitive.Content>,
    VariantProps<typeof sheetVariants> {
  hideClose?: boolean;
}

export const SheetContent = forwardRef<
  ElementRef<typeof DialogPrimitive.Content>,
  SheetContentProps
>(({ side = 'right', className, children, hideClose, ...props }, ref) => {
  const { t } = useTranslation();
  // Il foglio dal basso si chiude anche trascinandolo giù: lo chiude il pulsante nascosto, come la X
  const swipeClose = useRef<HTMLButtonElement>(null);
  const swipeRef = useSwipeToDismiss(() => swipeClose.current?.click(), { enabled: side === 'bottom' });
  const contentRef = useMemo(() => mergeRefs(ref, swipeRef), [ref, swipeRef]);
  return (
    <SheetPortal>
      <SheetOverlay />
      <DialogPrimitive.Content ref={contentRef} className={cn(sheetVariants({ side }), className)} {...props}>
        {children}
        {side === 'bottom' && <DialogPrimitive.Close ref={swipeClose} hidden tabIndex={-1} aria-hidden="true" />}
        {!hideClose && (
          <DialogPrimitive.Close
            // bersaglio da 44px, centrato dove stava l'icona (28px dall'angolo)
            className="absolute right-1.5 top-1.5 flex min-h-touch min-w-touch items-center justify-center rounded-md opacity-70 transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring"
            aria-label={t('actions.close')}
          >
            <X className="size-4" />
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </SheetPortal>
  );
});
SheetContent.displayName = DialogPrimitive.Content.displayName;

export function SheetHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('flex flex-col space-y-2 text-center sm:text-left', className)} {...props} />;
}
SheetHeader.displayName = 'SheetHeader';

export function SheetFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', className)} {...props} />
  );
}
SheetFooter.displayName = 'SheetFooter';

export const SheetTitle = forwardRef<
  ElementRef<typeof DialogPrimitive.Title>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title
    ref={ref}
    className={cn('text-lg font-semibold text-foreground', className)}
    {...props}
  />
));
SheetTitle.displayName = DialogPrimitive.Title.displayName;

export const SheetDescription = forwardRef<
  ElementRef<typeof DialogPrimitive.Description>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Description
    ref={ref}
    className={cn('text-sm text-muted-foreground', className)}
    {...props}
  />
));
SheetDescription.displayName = DialogPrimitive.Description.displayName;
