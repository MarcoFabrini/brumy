import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { logout } from '@/api/endpoints/auth';
import { unsubscribeThisDevice } from '@/lib/pushCleanup';
import { useAuthStore } from '@/stores/useAuthStore';

/**
 * useLogout — disiscrive le push di questo dispositivo (finché la sessione vale), chiude la sessione sul server
 * (cookie di sessione e remember-me), pulisce store e cache, poi redirect al login.
 */
export function useLogout() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  return async () => {
    await unsubscribeThisDevice();
    try {
      await logout();
    } catch {
      // Anche se la chiamata fallisce, deautentica comunque lato client
    }
    useAuthStore.getState().logout();
    qc.clear();
    navigate('/login', { replace: true });
  };
}
