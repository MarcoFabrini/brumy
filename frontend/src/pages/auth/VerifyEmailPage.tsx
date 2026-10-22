import { useEffect, useRef } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Alert, Button, Heading, Spinner, Text } from '@/components/ui';
import { AuthLayout } from '@/components/layout';
import { useVerifyEmail } from '@/hooks/useAccount';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';

/**
 * Pagina raggiunta dal link nell'email di verifica (`/verify-email?token=...`).
 * Pubblica: invia il token al mount e mostra esito.
 */
export function VerifyEmailPage() {
  const { t } = useTranslation();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const errorMessage = useApiErrorMessage();
  const { mutate, isPending, isSuccess, isError, error, data } = useVerifyEmail();
  const started = useRef(false);

  useEffect(() => {
    if (token && !started.current) {
      started.current = true;
      mutate({ token });
    }
  }, [token, mutate]);

  if (!token) {
    return (
      <AuthLayout>
        <div className="flex flex-col items-center gap-4 text-center">
          <Heading level={2}>{t('auth.verify.invalid_title')}</Heading>
          <Text variant="muted">{t('auth.verify.invalid_description')}</Text>
          <Button asChild variant="outline" className="mt-2">
            <Link to="/login">{t('auth.forgot.back_to_login')}</Link>
          </Button>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout>
      <div className="flex flex-col items-center gap-4 text-center">
        {(isPending || (!isSuccess && !isError)) && (
          <>
            <Spinner />
            <Text variant="muted">{t('auth.verify.checking')}</Text>
          </>
        )}

        {isSuccess && data.emailChanged && (
          <>
            <Heading level={2}>{t('auth.verify.email_changed_title')}</Heading>
            <Text variant="muted">{t('auth.verify.email_changed_description')}</Text>
            <Button asChild className="mt-2">
              <Link to="/login">{t('auth.verify.go_to_login')}</Link>
            </Button>
          </>
        )}

        {isSuccess && !data.emailChanged && (
          <>
            <Heading level={2}>{t('auth.verify.success_title')}</Heading>
            <Text variant="muted">{t('auth.verify.success_description')}</Text>
            <Button asChild className="mt-2">
              <Link to="/">{t('auth.verify.continue')}</Link>
            </Button>
          </>
        )}

        {isError && (
          <>
            <Heading level={2}>{t('auth.verify.error_title')}</Heading>
            <Alert variant="error">{errorMessage(error)}</Alert>
            <Button asChild variant="outline" className="mt-2">
              <Link to="/login">{t('auth.forgot.back_to_login')}</Link>
            </Button>
          </>
        )}
      </div>
    </AuthLayout>
  );
}
