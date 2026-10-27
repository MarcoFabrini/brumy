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
  /**
   * Richiesta che non presuppone una sessione (login, codice della verifica in due passaggi, logout, registrazione,
   * rotte pubbliche, `/me` del bootstrap): un 401 è la risposta da mostrare, non una sessione scaduta, e lo store non
   * cambia.
   */
  anonymous?: boolean;
}

/**
 * La sessione viaggia nel cookie HttpOnly `brumy_session`, che il browser manda da solo alla stessa origine: nessun
 * header di autenticazione. Nemmeno un header CSRF: il backend accetta le scritture solo dalla stessa origine
 * (`Sec-Fetch-Site`/`Origin`, li mette il browser).
 */
function requestInit({ anonymous: _anonymous, ...opts }: AuthFetchOptions): RequestInit {
  const headers = new Headers(opts.headers);
  // JSON salvo richiesta diversa (i download di allegati passano `*/*`). Il backend risponde
  // comunque in JSON agli errori sotto /api: l'header dichiara solo cosa si aspetta il client.
  if (!headers.has('Accept')) headers.set('Accept', 'application/json');
  return { ...opts, credentials: 'same-origin', headers };
}

async function problemOf(res: Response): Promise<ApiError> {
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
  return new ApiError(problem.title ?? `http.${res.status}`, res.status, problem.detail, problem.errors);
}

/**
 * Fetch con la sessione del browser. Ritorna la Response grezza; lancia ApiError su non-2xx.
 * Un 401 su una chiamata non anonima chiude la sessione nello store: `auth.two_factor_required` porta al
 * secondo passaggio del login, ogni altro 401 al login (`auth.session_expired`).
 */
async function authFetchResponse(path: string, opts: AuthFetchOptions): Promise<Response> {
  const res = await fetch(`${API_BASE}${path}`, requestInit(opts));
  if (res.ok) return res;

  const error = await problemOf(res);
  if (res.status !== 401 || opts.anonymous) throw error;

  if (error.title === 'auth.two_factor_required') {
    useAuthStore.getState().setTwoFactorPending();
    throw error;
  }
  useAuthStore.getState().setUnauthenticated();
  throw new ApiError('auth.session_expired', 401);
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
 * Fetch autenticato che ritorna un Blob — usato per i download degli allegati e dell'avatar, mostrati da object URL.
 * Stessa gestione della sessione di authFetch.
 */
export async function authFetchBlob(path: string, opts: AuthFetchOptions = {}): Promise<Blob> {
  const res = await authFetchResponse(path, {
    ...opts,
    headers: { Accept: '*/*', ...Object.fromEntries(new Headers(opts.headers)) },
  });
  return res.blob();
}
