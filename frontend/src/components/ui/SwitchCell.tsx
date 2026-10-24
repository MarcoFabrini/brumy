import { useId } from 'react';
import { Switch } from './Switch';

/** Cella di modulo iOS con etichetta e interruttore (es. "Pieno", "Spesa ricorrente"), alta come i campi. */
export function SwitchCell({
  label,
  checked,
  onCheckedChange,
}: {
  label: string;
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
}) {
  const labelId = useId();
  return (
    <div className="flex min-h-[3.25rem] items-center justify-between gap-3 rounded-xl bg-card px-4">
      <span id={labelId} className="text-[1.0625rem]">
        {label}
      </span>
      <Switch checked={checked} onCheckedChange={onCheckedChange} aria-labelledby={labelId} />
    </div>
  );
}
