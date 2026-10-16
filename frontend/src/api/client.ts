import { useAuthStore } from '@/stores/useAuthStore';

const API_BASE = import.meta.env.VITE_API_URL ?? '';

export class ApiError extends Error {
  constructor(
    public readonly title: string,
    public readonly status: number,
    public readonly detail?: string,
    public readonly errors?: Array<{ field: string; message: string }>,
  ) {
    super(`${title} (HTTP ${status})`);
  }
}

interface AuthFetchOptions extends RequestInit {
  /** Skip auth refresh attempt on 401 (used internally during refresh) */
  skipRefresh?: boolean;
}

/**
 * Esito del refresh:
 * - 'ok': nuovo access token in store
 * - 'invalid': il server ha rifiutato il refresh token (400/401) → sessione davvero finita
 * - 'unavailable': rete assente o 5xx → la sessione può essere ancora valida, NON fare logout
 */
export type RefreshResult = 'ok' | 'invalid' | 'unavailable';

let refreshPromise: Promise<RefreshResult> | null = null;

/**
 * Pausa prima del secondo tentativo dopo un 400/401. Il refresh token ruota a ogni uso e il backend
 * non ha finestra di tolleranza: con due schede che rinnovano insieme la perdente riceve un rifiuto
 * anche se il cookie è stato ruotato correttamente dall'altra. Dopo una breve attesa il browser
 * manda il cookie nuovo.
 */
export const REFRESH_RETRY_DELAY_MS = 250;

async function attemptRefresh(): Promise<RefreshResult> {
  try {
    const res = await fetch(`${API_BASE}/api/auth/refresh`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-Client-Type': 'web', Accept: 'application/json' },
    });
    if (res.status === 400 || res.status === 401) return 'invalid';
    if (!res.ok) return 'unavailable';
    const data: unknown = await res.json();
    // La risposta è del nostro server, ma entra come dato non tipizzato: si controlla invece di forzare il tipo.
    const token = typeof data === 'object' && data !== null && 'access_token' in data ? data.access_token : null;
    if (typeof token !== 'string' || token === '') return 'unavailable';
    useAuthStore.getState().setAccessToken(token);
    return 'ok';
  } catch {
    return 'unavailable';
  }
}

export async function refreshAccessToken(): Promise<RefreshResult> {
  // Coalesce simultaneous refresh attempts (nella stessa scheda; tra schede vedi REFRESH_RETRY_DELAY_MS)
  if (refreshPromise) return refreshPromise;

  refreshPromise = (async () => {
    try {
      const first = await attemptRefresh();
      if (first !== 'invalid') return first;
      await new Promise((resolve) => setTimeout(resolve, REFRESH_RETRY_DELAY_MS));
      return await attemptRefresh();
    } finally {
      refreshPromise = null;
    }
  })();

  return refreshPromise;
}

function withAuth(opts: AuthFetchOptions): RequestInit {
  const access = useAuthStore.getState().accessToken;
  const headers = new Headers(opts.headers);
  headers.set('X-Client-Type', 'web');
  // JSON salvo richiesta diversa (i download di allegati passano `*/*`). Il backend risponde
  // comunque in JSON agli errori sotto /api: l'header dichiara solo cosa si aspetta il client.
  if (!headers.has('Accept')) headers.set('Accept', 'application/json');
  if (access) headers.set('Authorization', `Bearer ${access}`);
  return {
    ...opts,
    credentials: 'include',
    headers,
  };
}

/**
 * Fetch autenticato con retry su 401 (refresh + retry una volta).
 * Ritorna la Response grezza; lancia ApiError su non-2xx.
 */
async function authFetchResponse(path: string, opts: AuthFetchOptions): Promise<Response> {
  let res = await fetch(`${API_BASE}${path}`, withAuth(opts));

  if (res.status === 401 && !opts.skipRefresh) {
    const refreshed = await refreshAccessToken();
    if (refreshed === 'ok') {
      res = await fetch(`${API_BASE}${path}`, withAuth(opts));
    } else if (refreshed === 'invalid') {
      useAuthStore.getState().logout();
      throw new ApiError('auth.session_expired', 401);
    } else {
      // Rete/5xx sul refresh: la sessione resta, la richiesta fallisce e si potrà riprovare.
      throw new ApiError('network.unavailable', 503);
    }
  }

  if (!res.ok) {
    let problem: {
      title?: string;
      status?: number;
      detail?: string;
      errors?: Array<{ field: string; message: string }>;
    } = {};
    try {
      problem = await res.json();
    } catch {
      /* response non-JSON */
    }
    throw new ApiError(
      problem.title ?? `http.${res.status}`,
      res.status,
      problem.detail,
      problem.errors,
    );
  }

  return res;
}

/**
 * Fetch autenticato che ritorna JSON tipizzato (o undefined su 204).
 * Lancia ApiError su non-2xx.
 */
export async function authFetch<T = unknown>(
  path: string,
  opts: AuthFetchOptions = {},
): Promise<T> {
  const res = await authFetchResponse(path, opts);
  if (res.status === 204) return undefined as T;
  return res.json() as Promise<T>;
}

/**
 * Fetch autenticato che ritorna un Blob — usato per download allegati, dove
 * `<img src>` non può inviare l'header Bearer. Stessa auth/retry di authFetch.
 */
export async function authFetchBlob(path: string, opts: AuthFetchOptions = {}): Promise<Blob> {
  const res = await authFetchResponse(path, {
    ...opts,
    headers: { Accept: '*/*', ...Object.fromEntries(new Headers(opts.headers)) },
  });
  return res.blob();
}
