import { Link } from 'react-router-dom';
import { useListReturnState } from '@/hooks/useListReturnState';
import { Package } from 'lucide-react';
import { ListRow } from '@/components/ui';
import { MetaLine } from './MetaLine';
import { useFormat } from '@/hooks/useFormat';
import type { Component } from '@/api/types/component';

export interface ComponentCardProps {
  component: Component;
}

/** Un componente come riga di lista iOS: nome, poi modello/codice, negozio e data d'acquisto; prezzo a destra. */
export function ComponentCard({ component: c }: ComponentCardProps) {
  const returnState = useListReturnState();
  const fmt = useFormat();

  return (
    <ListRow
      as={Link}
      to={`/components/${c.id}`}
      state={returnState}
      icon={<Package />}
      iconClassName="bg-[#a2845e]"
      label={c.name}
      description={<MetaLine parts={[c.code, c.shop, c.purchasedAt ? fmt.date(c.purchasedAt) : null]} />}
      value={c.price ? <span className="font-medium text-foreground">{fmt.currency(c.price)}</span> : undefined}
      chevron
    />
  );
}
