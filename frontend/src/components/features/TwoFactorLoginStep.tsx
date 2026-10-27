import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation } from 'react-i18next';
import { Alert, Button, FormField, Input, Spinner, Text } from '@/components/ui';
import { ApiError } from '@/api/client';
import { useCancelTwoFactor, useVerifyTwoFactor } from '@/hooks/useLogin';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';
import { useFieldErrorMessage } from '@/hooks/useFieldErrorMessage';
import {
  twoFactorAppCodeSchema,
  twoFactorRecoveryCodeSchema,
  type TwoFactorCodeFormData,
} from '@/schemas/twoFactor.schema';

type Mode = 'app' | 'recovery';

/**
 * Secondo passo del login con la verifica in due passaggi attiva: codice dell'app di autenticazione oppure, con il
 * link apposito, un codice di recupero. "Annulla" chiude la sessione a metà e torna al primo passo. A verifica
 * riuscita lo store passa ad 'authenticated' e la pagina porta dentro.
 */
export function TwoFactorLoginStep() {
  const { t } = useTranslation();
  const [mode, setMode] = useState<Mode>('app');
  const cancel = useCancelTwoFactor();

  return (
    <div className="space-y-4">
      <Text variant="muted" className="text-center">
        {t(mode === 'app' ? 'auth.login.two_factor.description' : 'auth.login.two_factor.recovery_description')}
      </Text>

      {/* key: cambiando tipo di codice il campo riparte vuoto, con il proprio schema e il focus */}
      <CodeForm key={mode} mode={mode} />

      <div className="flex flex-col items-center gap-1">
        <Button type="button" variant="link" onClick={() => setMode(mode === 'app' ? 'recovery' : 'app')}>
          {t(mode === 'app' ? 'auth.login.two_factor.use_recovery' : 'auth.login.two_factor.use_app')}
        </Button>
        <Button type="button" variant="link" disabled={cancel.isPending} onClick={() => cancel.mutate()}>
          {t('auth.login.two_factor.cancel')}
        </Button>
      </div>
    </div>
  );
}

function CodeForm({ mode }: { mode: Mode }) {
  const { t } = useTranslation();
  const errorMessage = useApiErrorMessage();
  const tErr = useFieldErrorMessage();
  const verify = useVerifyTwoFactor();

  const form = useForm<TwoFactorCodeFormData>({
    resolver: zodResolver(mode === 'app' ? twoFactorAppCodeSchema : twoFactorRecoveryCodeSchema),
    defaultValues: { code: '' },
  });
  const codeError = form.formState.errors.code?.message;

  function submit({ code }: TwoFactorCodeFormData) {
    verify.mutate(code.trim(), {
      onError: (e) => {
        if (e instanceof ApiError && e.title === 'auth.invalid_two_factor_code') {
          form.setError('code', { message: e.title }, { shouldFocus: true });
        }
      },
    });
  }

  // Il codice sbagliato è un errore del campo; il resto (troppi tentativi, rete) un avviso generico.
  const generalError =
    verify.error && !(verify.error instanceof ApiError && verify.error.title === 'auth.invalid_two_factor_code')
      ? verify.error
      : null;

  return (
    <form onSubmit={form.handleSubmit(submit)} className="space-y-4" noValidate>
      <FormField
        label={t(mode === 'app' ? 'auth.login.two_factor.code' : 'auth.login.two_factor.recovery_code')}
        error={tErr(codeError)}
        required
      >
        {(id) =>
          mode === 'app' ? (
            <Input
              id={id}
              autoFocus
              autoComplete="one-time-code"
              inputMode="numeric"
              pattern="[0-9 ]*"
              maxLength={7}
              invalid={!!codeError}
              {...form.register('code')}
            />
          ) : (
            <Input
              id={id}
              autoFocus
              autoComplete="off"
              autoCapitalize="none"
              spellCheck={false}
              maxLength={20}
              invalid={!!codeError}
              {...form.register('code')}
            />
          )
        }
      </FormField>

      {generalError && <Alert variant="error">{errorMessage(generalError)}</Alert>}

      <Button type="submit" fullWidth disabled={verify.isPending}>
        {verify.isPending ? <Spinner size="sm" /> : t('auth.login.two_factor.submit')}
      </Button>
    </form>
  );
}
