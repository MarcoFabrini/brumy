import { useTranslation } from 'react-i18next';
import { useAuthStore } from '@/stores/useAuthStore';
import { useResendVerification } from '@/hooks/useAccount';
import { useToast } from '@/hooks/useToast';
import { useApiErrorMessage } from '@/hooks/useApiErrorMessage';

/**
 * Banner non bloccante mostrato agli utenti con un cambio email in attesa di conferma o con l'email non ancora
 * verificata. Permette di rinviare il link. Sparisce una volta confermato.
 */
export function EmailVerificationBanner() {
  const { t } = useTranslation();
  const user = useAuthStore((s) => s.user);
  const { toast } = useToast();
  const errorMessage = useApiErrorMessage();
  const { mutate, isPending } = useResendVerification();

  if (!user || (user.emailVerified && !user.pendingEmail)) return null;

  return (
    <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
      <span>
        {user.pendingEmail
          ? t('auth.verify.pending_banner', { email: user.pendingEmail })
          : t('auth.verify.banner')}
      </span>
      <button
        type="button"
        disabled={isPending}
        onClick={() =>
          mutate(undefined, {
            onSuccess: () => toast({ title: t('auth.verify.resent'), variant: 'success' }),
            // 429 (troppi invii) o rete assente: senza avviso l'utente crederebbe che la mail sia partita.
            onError: (e) => toast({ title: errorMessage(e), variant: 'error' }),
          })
        }
        // -my-3: il bersaglio tocca i 44px senza allargare il banner (che ha py-3)
        className="-my-3 inline-flex min-h-touch min-w-touch items-center justify-center font-medium underline underline-offset-2 disabled:opacity-50"
      >
        {t('auth.verify.resend')}
      </button>
    </div>
  );
}
