import { Link } from 'react-router-dom';
import { useListReturnState } from '@/hooks/useListReturnState';
import { useTranslation } from 'react-i18next';
import { ListRow } from '@/components/ui';
import { MetaLine } from './MetaLine';
import { useFormat } from '@/hooks/useFormat';
import type { Expense } from '@/api/types/expense';
import { expenseKind, sectionOf } from './vehicleRecordActions';

export interface ExpenseCardProps {
  expense: Expense;
}

/**
 * Una spesa come riga di lista iOS: descrizione, poi categoria (tipo di tassa, compagnia), data e ricorrenza;
 * importo a destra. Icona della sezione.
 */
export function ExpenseCard({ expense: e }: ExpenseCardProps) {
  const returnState = useListReturnState();
  const { t } = useTranslation();
  const fmt = useFormat();
  const category = e.section === 'insurance' ? e.insurer : e.category;
  const { Icon, iconClassName, titleKey } = sectionOf(expenseKind(e.section));

  return (
    <ListRow
      as={Link}
      to={`/expenses/${e.id}`}
      state={returnState}
      icon={<Icon />}
      iconClassName={iconClassName}
      label={e.description || category || t(titleKey)}
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
