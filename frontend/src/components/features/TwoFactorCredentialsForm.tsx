import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import { Alert, FormField, Input } from '@/components/ui';
import { ApiError } from '@/api/client';
import type { TwoFactorCredentials } from '@/api/types/auth';
import { DialogFormActions } from './DialogFormActions';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import { useServerFieldErrors } from '@/hooks/useServerFieldErrors';
import {
  twoFactorConfirmSchema,
  twoFactorEnableSchema,
  type TwoFactorCredentialsFormData,
} from '@/schemas/twoFactor.schema';

export interface TwoFactorCredentialsFormProps {
  /** `app`: solo il codice dell'app (attivazione); `any`: anche un codice di recupero (disattivazione, codici nuovi). */
  codeKind: 'app' | 'any';
  codeLabel: string;
  submitLabel: string;
  isPending: boolean;
  error: unknown;
  onSubmit: (credentials: TwoFactorCredentials) => void;
  onCancel: () => void;
}

/**
 * Password attuale + codice, il modulo comune ad attivazione, disattivazione e codici nuovi. Il codice sbagliato
 * (422 sul campo `code`) e la password sbagliata (400) finiscono sui campi; il resto (409, 429) in un avviso.
 */
export function TwoFactorCredentialsForm({
  codeKind,
  codeLabel,
  submitLabel,
  isPending,
  error,
  onSubmit,
  onCancel,
}: TwoFactorCredentialsFormProps) {
  const { t } = useTranslation();
  const tErr = useFieldErrorMessage();
  const errorMessage = useApiErrorMessage();
  const form = useForm<TwoFactorCredentialsFormData>({
    resolver: zodResolver(codeKind === 'app' ? twoFactorEnableSchema : twoFactorConfirmSchema),
    defaultValues: { currentPassword: '', code: '' },
  });
  const { hasUnmapped } = useServerFieldErrors(form, error);
  const errors = form.formState.errors;

  const wrongPassword = error instanceof ApiError && error.title === 'auth.invalid_current_password';
  const showAlert =
    error instanceof ApiError
      ? !wrongPassword && (error.title !== 'validation_failed' || hasUnmapped)
      : error != null;

  return (
    <form
      onSubmit={form.handleSubmit(({ currentPassword, code }) => onSubmit({ currentPassword, code: code.trim() }))}
      className="space-y-4"
      noValidate
    >
      <FormField
        label={t('account.current_password')}
        error={wrongPassword ? tErr('auth.invalid_current_password') : tErr(errors.currentPassword?.message)}
        required
      >
        {(id) => (
          <Input
            id={id}
            type="password"
            autoComplete="current-password"
            invalid={wrongPassword || !!errors.currentPassword}
            {...form.register('currentPassword')}
          />
        )}
      </FormField>

      <FormField label={codeLabel} error={tErr(errors.code?.message)} required>
        {(id) => (
          <Input
            id={id}
            autoComplete="one-time-code"
            inputMode={codeKind === 'app' ? 'numeric' : 'text'}
            autoCapitalize="none"
            spellCheck={false}
            maxLength={20}
            invalid={!!errors.code}
            {...form.register('code')}
          />
        )}
      </FormField>

      {showAlert && <Alert variant="error">{errorMessage(error)}</Alert>}

      <DialogFormActions isPending={isPending} onCancel={onCancel} submitLabel={submitLabel} />
    </form>
  );
}
