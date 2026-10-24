import { forwardRef, type ComponentPropsWithoutRef, type ElementRef, type HTMLAttributes } from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

/**
 * Dialog molecule (Radix-based).
 *
 * Mobile: fullscreen-ish con safe area. Desktop: centered card.
 *
 * @example
 * <Dialog>
 *   <DialogTrigger asChild><Button>Apri</Button></DialogTrigger>
 *   <DialogContent>
 *     <DialogHeader>
 *       <DialogTitle>Conferma eliminazione</DialogTitle>
 *       <DialogDescription>Operazione irreversibile.</DialogDescription>
 *     </DialogHeader>
 *     <DialogFooter>
 *       <DialogClose asChild><Button variant="outline">Annulla</Button></DialogClose>
 *       <Button variant="destructive">Elimina</Button>
 *     </DialogFooter>
 *   </DialogContent>
 * </Dialog>
 */
export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogPortal = DialogPrimitive.Portal;
export const DialogClose = DialogPrimitive.Close;

export const DialogOverlay = forwardRef<
  ElementRef<typeof DialogPrimitive.Overlay>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn(
      'fixed inset-0 z-50 bg-black/40',
      'data-[state=open]:animate-in data-[state=open]:fade-in-0',
      'data-[state=closed]:animate-out data-[state=closed]:fade-out-0',
      className,
    )}
    {...props}
  />
));
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

export const DialogContent = forwardRef<
  ElementRef<typeof DialogPrimitive.Content>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & {
    hideClose?: boolean;
    /** Su mobile (<sm) occupa tutto lo schermo come una pagina; da sm+ resta card centrata. */
    mobileFullScreen?: boolean;
  }
>(({ className, children, hideClose, mobileFullScreen, ...props }, ref) => {
  const { t } = useTranslation();
  return (
    <DialogPortal>
      <DialogOverlay />
      <DialogPrimitive.Content
        ref={ref}
        className={cn(
          'fixed z-50 grid gap-4 overflow-y-auto overscroll-contain bg-background shadow-lg',
          'data-[state=open]:animate-in data-[state=open]:fade-in-0',
          'data-[state=closed]:animate-out data-[state=closed]:fade-out-0',
          mobileFullScreen
            ? [
                // mobile: foglio iOS a tutta larghezza che sale dal basso e lascia vedere la cima della pagina, con la
                // maniglia in alto; il contenuto parte dall'alto e scorre dentro il foglio.
                'inset-x-0 bottom-0 top-[max(env(safe-area-inset-top),0.75rem)] w-full max-w-none content-start rounded-t-[12px] border-0 px-4 pb-safe pt-7',
                'before:absolute before:left-1/2 before:top-[7px] before:h-[5px] before:w-9 before:-translate-x-1/2 before:rounded-full before:bg-muted-foreground/40 before:content-[""] sm:before:hidden',
                'data-[state=open]:slide-in-from-bottom data-[state=closed]:slide-out-to-bottom data-[state=open]:duration-300 sm:data-[state=open]:slide-in-from-bottom-0 sm:data-[state=closed]:slide-out-to-bottom-0',
                // tablet/desktop (sm+): card centrata, contenuto in alto
                'sm:inset-auto sm:left-1/2 sm:top-1/2 sm:w-full sm:max-w-lg sm:max-h-[90vh] sm:content-stretch',
                'sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-lg sm:border sm:p-6',
                'sm:data-[state=open]:zoom-in-95 sm:data-[state=closed]:zoom-out-95',
              ]
            : [
                'left-1/2 top-1/2 w-full max-w-lg max-h-[90vh] -translate-x-1/2 -translate-y-1/2',
                'border p-6 sm:rounded-lg',
                'data-[state=open]:zoom-in-95 data-[state=closed]:zoom-out-95',
              ],
          className,
        )}
        {...props}
      >
        {children}
        {!hideClose && (
          <DialogPrimitive.Close
            // bersaglio da 44px, centrato dove stava l'icona (28px dall'angolo)
            className="absolute right-1.5 top-1.5 flex min-h-touch min-w-touch items-center justify-center rounded-md opacity-70 transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring disabled:pointer-events-none"
            aria-label={t('actions.close')}
          >
            <X className="size-4" />
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </DialogPortal>
  );
});
DialogContent.displayName = DialogPrimitive.Content.displayName;

export function DialogHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn('flex flex-col space-y-1.5 text-center sm:text-left', className)} {...props} />
  );
}
DialogHeader.displayName = 'DialogHeader';

export function DialogFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end sm:gap-2', className)}
      {...props}
    />
  );
}
DialogFooter.displayName = 'DialogFooter';

export const DialogTitle = forwardRef<
  ElementRef<typeof DialogPrimitive.Title>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title
    ref={ref}
    className={cn('text-lg font-semibold leading-none tracking-tight', className)}
    {...props}
  />
));
DialogTitle.displayName = DialogPrimitive.Title.displayName;

export const DialogDescription = forwardRef<
  ElementRef<typeof DialogPrimitive.Description>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Description
    ref={ref}
    className={cn('text-sm text-muted-foreground', className)}
    {...props}
  />
));
DialogDescription.displayName = DialogPrimitive.Description.displayName;
