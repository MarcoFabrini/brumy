import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Switch atom — interruttore in stile iOS (51×31, pallino bianco, verde quando acceso).
 * `role="switch"` + `aria-checked`: lo screen reader lo annuncia come interruttore. Il nome accessibile viene
 * dall'etichetta della riga (`aria-labelledby`) o da `aria-label`. Il bersaglio touch arriva a 44px con il
 * margine invisibile del bottone.
 *
 * @example
 * <Switch checked={largeText} onCheckedChange={setLargeText} aria-labelledby={labelId} />
 */
export interface SwitchProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onChange' | 'role'> {
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
}

export const Switch = forwardRef<HTMLButtonElement, SwitchProps>(
  ({ checked, onCheckedChange, className, disabled, ...props }, ref) => (
    <button
      ref={ref}
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onCheckedChange(!checked)}
      className={cn(
        'relative inline-flex h-[31px] w-[51px] shrink-0 cursor-pointer items-center rounded-full p-0.5 transition-colors duration-200',
        // Bersaglio da 44px senza cambiare l'aspetto: l'area cliccabile si allarga oltre il disegno.
        'before:absolute before:inset-x-0 before:-inset-y-[7px] before:content-[""]',
        checked ? 'bg-[#34c759] dark:bg-[#30d158]' : 'bg-[#e9e9eb] dark:bg-[#39393d]',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        'disabled:cursor-not-allowed disabled:opacity-50',
        className,
      )}
      {...props}
    >
      <span
        aria-hidden="true"
        className={cn(
          'size-[27px] rounded-full bg-white shadow-[0_3px_8px_rgba(0,0,0,0.15),0_3px_1px_rgba(0,0,0,0.06)] transition-transform duration-200',
          checked ? 'translate-x-5' : 'translate-x-0',
        )}
      />
    </button>
  ),
);
Switch.displayName = 'Switch';
