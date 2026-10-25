import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  FormField,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui';
import { ApiError } from '@/api/client';
import type { ManagedUserValue, UserValueKind } from '@/api/types/userValue';
import { ConfirmDialog } from './ConfirmDialog';
import { DialogFormActions } from './DialogFormActions';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import {
  useCreateUserValue,
  useDeleteUserValue,
  useMergeUserValue,
  useRenameUserValue,
} from '@/hooks/useUserValues';

export interface UserValueDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  kind: UserValueKind;
  /** La voce da gestire; assente = nuova voce. */
  value?: ManagedUserValue;
  /** Tutte le voci dello stesso tipo: le altre sono le destinazioni dell'unione. */
  values?: ManagedUserValue[];
}

interface FormData {
  name: string;
}

/**
 * Dialog di una voce privata (distributore, officina, categoria): rinomina, unione in un'altra voce,
 * eliminazione (morbida: i record che la usano continuano a mostrarla). Con `value` assente crea una nuova voce.
 */
export function UserValueDialog({ open, onOpenChange, kind, value, values = [] }: UserValueDialogProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const tErr = useFieldErrorMessage();
  const create = useCreateUserValue();
  const rename = useRenameUserValue();
  const merge = useMergeUserValue();
  const remove = useDeleteUserValue();

  const form = useForm<FormData>({ defaultValues: { name: value?.name ?? '' } });
  const [mergeIntoId, setMergeIntoId] = useState('');
  const [confirm, setConfirm] = useState<'merge' | 'delete' | null>(null);

  // Il form riparte dalla voce corrente a ogni apertura.
  useEffect(() => {
    if (open) {
      form.reset({ name: value?.name ?? '' });
      setMergeIntoId('');
      setConfirm(null);
    }
    // Solo al cambio di `open`: `value` si aggiorna anche dopo il salvataggio.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const errors = form.formState.errors;
  const targets = values.filter((v) => v.id !== value?.id);
  const target = targets.find((v) => String(v.id) === mergeIntoId);
  const busy = create.isPending || rename.isPending || merge.isPending || remove.isPending;

  const onError = (e: unknown) => {
    const field = e instanceof ApiError ? e.errors?.find((err) => err.field === 'name') : undefined;
    if (field) form.setError('name', { message: field.message });
    else toast({ title: errorMessage(e), variant: 'error' });
  };
  const done = () => onOpenChange(false);

  function submit({ name }: FormData) {
    const trimmed = name.trim();
    if (!trimmed) {
      form.setError('name', { message: 'values.name_required' });
      return;
    }
    if (!value) create.mutate({ kind, name: trimmed }, { onSuccess: done, onError });
    else if (trimmed === value.name) done();
    else rename.mutate({ id: value.id, name: trimmed }, { onSuccess: done, onError });
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent mobileFullScreen>
          <DialogHeader>
            <DialogTitle>{value ? t('values.edit_title') : t(`values.add.${kind}`)}</DialogTitle>
            <DialogDescription>{t(`values.kind.${kind}`)}</DialogDescription>
          </DialogHeader>

          <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
            <FormField label={t('values.name')} error={tErr(errors.name?.message)} required>
              {(id) => <Input id={id} maxLength={200} invalid={!!errors.name} {...form.register('name')} />}
            </FormField>
            <DialogFormActions isPending={create.isPending || rename.isPending} onCancel={done} />
          </form>

          {value && (
            <div className="space-y-4 border-t border-border pt-4">
              {targets.length > 0 && (
                <div className="space-y-2">
                  <Label id="user-value-merge-label">{t('values.merge')}</Label>
                  <div className="flex gap-2">
                    <Select value={mergeIntoId} onValueChange={setMergeIntoId}>
                      <SelectTrigger aria-labelledby="user-value-merge-label">
                        <SelectValue placeholder={t('values.merge_placeholder')} />
                      </SelectTrigger>
                      <SelectContent>
                        {targets.map((v) => (
                          <SelectItem key={v.id} value={String(v.id)}>
                            {v.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <Button
                      type="button"
                      variant="outline"
                      disabled={!target || busy}
                      onClick={() => setConfirm('merge')}
                    >
                      {t('values.merge_action')}
                    </Button>
                  </div>
                </div>
              )}

              <Button
                type="button"
                variant="destructive"
                className="w-full"
                disabled={busy}
                onClick={() => setConfirm('delete')}
              >
                {t('actions.delete')}
              </Button>
            </div>
          )}
        </DialogContent>
      </Dialog>

      {value && target && (
        <ConfirmDialog
          open={confirm === 'merge'}
          onOpenChange={(o) => setConfirm(o ? 'merge' : null)}
          title={t('values.merge_confirm_title')}
          description={t('values.merge_confirm', { from: value.name, into: target.name })}
          confirmLabel={t('actions.confirm')}
          confirmVariant="primary"
          isPending={merge.isPending}
          onConfirm={() => merge.mutate({ id: value.id, intoId: target.id }, { onSuccess: done, onError })}
        />
      )}
      {value && (
        <ConfirmDialog
          open={confirm === 'delete'}
          onOpenChange={(o) => setConfirm(o ? 'delete' : null)}
          title={t('values.delete_confirm_title')}
          description={t('values.delete_confirm', { name: value.name })}
          confirmLabel={t('actions.delete')}
          isPending={remove.isPending}
          onConfirm={() => remove.mutate(value.id, { onSuccess: done, onError })}
        />
      )}
    </>
  );
}
