import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { Alert, ListRow, ListSection, Skeleton } from '@/components/ui';
import { PageHeader } from '@/components/layout';
import { UserValueDialog } from '@/components/features';
import { USER_VALUE_KINDS, type ManagedUserValue, type UserValueKind } from '@/api/types/userValue';
import { useUserValues } from '@/hooks/useUserValues';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';

const isKind = (k: string | undefined): k is UserValueKind => USER_VALUE_KINDS.some((kind) => kind === k);

/** Elenco di un tipo (`/settings/values/:kind`): aggiungi, e tocca una voce per rinominarla, unirla o eliminarla. */
export function UserValueListPage() {
  const { kind } = useParams<{ kind: string }>();
  if (!isKind(kind)) return <Navigate to="/settings/values" replace />;
  return <KindList key={kind} kind={kind} />;
}

function KindList({ kind }: { kind: UserValueKind }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const errorMessage = useApiErrorMessage();
  const { data, isLoading, error } = useUserValues(kind);
  // Voce aperta nel dialog: `null` = nuova voce.
  const [editing, setEditing] = useState<ManagedUserValue | null>(null);
  const [open, setOpen] = useState(false);

  function edit(value: ManagedUserValue | null) {
    setEditing(value);
    setOpen(true);
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t(`values.kind.${kind}`)} onBack={() => void navigate('/settings/values')} />
      <ListSection>
        <ListRow
          as="button"
          type="button"
          onClick={() => edit(null)}
          icon={<Plus />}
          iconClassName="bg-[#34c759]"
          label={t(`values.add.${kind}`)}
        />
      </ListSection>
      <ListSection>
        {isLoading ? (
          <Skeleton className="h-12 w-full rounded-none" />
        ) : error ? (
          <Alert variant="error">{errorMessage(error)}</Alert>
        ) : data?.length === 0 ? (
          <ListRow label={<span className="text-muted-foreground">{t('values.empty')}</span>} />
        ) : (
          data?.map((v) => (
            <ListRow
              key={v.id}
              as="button"
              type="button"
              onClick={() => edit(v)}
              label={v.name}
              description={t('values.usage', { count: v.usageCount })}
              chevron
            />
          ))
        )}
      </ListSection>
      <UserValueDialog open={open} onOpenChange={setOpen} kind={kind} value={editing ?? undefined} values={data} />
    </div>
  );
}
