import { useState } from 'react';

/**
 * Serie di un grafico nascoste dal tocco sulla legenda: un tocco la nasconde, il successivo la rimostra. Vale solo per il
 * disegno (la tabella per gli screen reader resta completa) e riparte a ogni montaggio del grafico.
 */
export function useHiddenSeries() {
  const [hidden, setHidden] = useState<ReadonlySet<string>>(() => new Set());
  const toggle = (key: string) =>
    setHidden((current) => {
      const next = new Set(current);
      if (!next.delete(key)) next.add(key);
      return next;
    });
  return { isHidden: (key: string) => hidden.has(key), toggle };
}
