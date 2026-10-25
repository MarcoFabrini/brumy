import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Fuel, Landmark, Receipt, ShieldCheck, ShoppingBag, Wrench, type LucideIcon } from 'lucide-react';
import { ListRow, ListSection } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { USER_VALUE_KINDS, type UserValueKind } from '@/api/types/userValue';
import { useUserValues } from '@/hooks/useUserValues';

/** Icona e colore di ogni elenco: quelli del record in cui la voce si scrive (rifornimento, manutenzione, spesa); il negozio ha i suoi. */
const KIND_ICON: Record<UserValueKind, { Icon: LucideIcon; className: string }> = {
  station: { Icon: Fuel, className: 'bg-[#34c759]' },
  workshop: { Icon: Wrench, className: 'bg-[#ff9500]' },
  shop: { Icon: ShoppingBag, className: 'bg-[#007aff]' },
  expense_category: { Icon: Receipt, className: 'bg-[#5856d6]' },
  tax_type: { Icon: Landmark, className: 'bg-[#af52de]' },
  insurer: { Icon: ShieldCheck, className: 'bg-[#30b0c7]' },
};

function KindRow({ kind }: { kind: UserValueKind }) {
  const { t } = useTranslation();
  const { data } = useUserValues(kind);
  const { Icon, className } = KIND_ICON[kind];

  return (
    <ListRow
      as={Link}
      to={`/settings/values/${kind}`}
      icon={<Icon />}
      iconClassName={className}
      label={t(`values.kind.${kind}`)}
      value={data?.length}
      chevron
    />
  );
}

/** Elenchi personali: una riga per tipo (distributori, officine, negozi, categorie spese, tipi di tassa, compagnie), ognuna apre il suo elenco. */
export function UserValuesPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();

  return (
    <div className="space-y-6">
      <PageHeader title={t('values.title')} description={t('values.description')} onBack={() => void navigate('/settings')} />
      <ListSection>
        {USER_VALUE_KINDS.map((kind) => (
          <KindRow key={kind} kind={kind} />
        ))}
      </ListSection>
    </div>
  );
}
