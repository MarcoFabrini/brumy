import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Receipt } from 'lucide-react';
import { ListRow } from '@/components/ui';
import { MetaLine } from './MetaLine';
import { useFormat } from '@/hooks/useFormat';
import type { Expense } from '@/api/types/expense';

export interface ExpenseCardProps {
  expense: Expense;
}

/** Una spesa come riga di lista iOS: descrizione, poi categoria, data e ricorrenza; importo a destra. */
export function ExpenseCard({ expense: e }: ExpenseCardProps) {
  const { t } = useTranslation();
  const fmt = useFormat();
  const category = t(`expense.category_options.${e.category}`);

  return (
    <ListRow
      as={Link}
      to={`/expenses/${e.id}`}
      icon={<Receipt />}
      iconClassName="bg-[#5856d6]"
      label={e.description || category}
      description={
        <MetaLine
          parts={[
            e.description ? category : null,
            fmt.date(e.occurredAt),
            e.recurring && e.recurringPeriod ? t(`expense.recurring_options.${e.recurringPeriod}`) : null,
            e.recurring && e.recurringUntil ? t('expense.recurring_until_on', { date: fmt.date(e.recurringUntil) }) : null,
          ]}
        />
      }
      value={<span className="font-medium text-foreground">{fmt.currency(e.amount)}</span>}
      chevron
    />
  );
}
