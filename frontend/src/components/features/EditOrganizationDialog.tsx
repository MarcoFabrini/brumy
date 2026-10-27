import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  FormField,
  Input,
} from '@/components/ui';
import { ApiError } from '@/api/client';
import { DialogFormActions } from './DialogFormActions';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { useUpdateOrganization } from '@/hooks/useAccount';
import { organizationSchema, type OrganizationFormData } from '@/schemas/account.schema';

export interface EditOrganizationDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  organizationId: number;
  currentName: string;
}

export function EditOrganizationDialog({
  open,
  onOpenChange,
  organizationId,
  currentName,
}: EditOrganizationDialogProps) {
  const { t } = useTranslation();
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const mutation = useUpdateOrganization();

  const form = useForm<OrganizationFormData>({
    resolver: zodResolver(organizationSchema),
    defaultValues: { name: currentName },
  });

  useEffect(() => {
    if (open) form.reset({ name: currentName });
  }, [open, currentName, form]);

  const errors = form.formState.errors;
  const tErr = useFieldErrorMessage();

  function submit(data: OrganizationFormData) {
    mutation.mutate(
      { id: organizationId, name: data.name },
      {
        onSuccess: () => {
          toast({ title: t('account.org_updated'), variant: 'success' });
          onOpenChange(false);
        },
        onError: (e) => {
          if (e instanceof ApiError && e.errors) {
            e.errors.forEach((err) => form.setError('name', { message: err.message }));
          } else {
            toast({ title: errorMessage(e), variant: 'error' });
          }
        },
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent mobileFullScreen>
        <DialogHeader>
          <DialogTitle>{t('account.edit_organization')}</DialogTitle>
          <DialogDescription className="sr-only">{t('account.edit_organization_description')}</DialogDescription>
        </DialogHeader>

        <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
          <FormField label={t('account.org_name')} error={tErr(errors.name?.message)} required>
            {(id) => <Input id={id} invalid={!!errors.name} {...form.register('name')} />}
          </FormField>

          <DialogFormActions isPending={mutation.isPending} onCancel={() => onOpenChange(false)} />
        </form>
      </DialogContent>
    </Dialog>
  );
}
