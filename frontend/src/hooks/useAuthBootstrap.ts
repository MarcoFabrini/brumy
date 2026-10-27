import { useCallback, useEffect } from 'react';
import { ApiError } from '@/api/client';
import { getMe } from '@/api/endpoints/auth';
import { splitMe } from '@/lib/authSession';
import { useAuthStore } from '@/stores/useAuthStore';

/**
 * useAuthBootstrap — al mount chiede `GET /api/auth/me` per sapere chi è collegato. Lo store è in memoria, ma la
 * sessione è nel cookie HttpOnly (e il remember-me la riapre dopo un riavvio del browser): un reload non fa uscire.
 *
 * - 200 → sessione aperta;
 * - 401 `auth.two_factor_required` → password già data, manca il codice: il login mostra il secondo passo;
 * - altri 401 → nessuna sessione;
 * - rete assente o 5xx → 'unreachable': la sessione può essere ancora valida, l'utente può riprovare.
 *
 * In StrictMode il doppio mount fa due `/me`: sono letture, nessun problema; il primo esito viene scartato.
 */
export function useAuthBootstrap() {
  const status = useAuthStore((s) => s.status);

  const bootstrap = useCallback(async (isCancelled: () => boolean) => {
    const store = useAuthStore.getState();
    try {
      const { user, activeOrgId } = splitMe(await getMe({ anonymous: true }));
      if (isCancelled()) return;
      store.setAuthenticated(user, activeOrgId);
    } catch (err) {
      if (isCancelled()) return;
      if (!(err instanceof ApiError) || err.status !== 401) store.setUnreachable();
      else if (err.title === 'auth.two_factor_required') store.setTwoFactorPending();
      else store.setUnauthenticated();
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    void bootstrap(() => cancelled);
    return () => {
      cancelled = true;
    };
  }, [bootstrap]);

  const retry = useCallback(() => {
    useAuthStore.setState({ status: 'loading' });
    void bootstrap(() => false);
  }, [bootstrap]);

  return { status, retry };
}
