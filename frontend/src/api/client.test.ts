import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, authFetch, authFetchBlob } from '@/api/client';
import { useAuthStore } from '@/stores/useAuthStore';
import { testUser } from '@/test/authFixtures';

function response(status: number, body: unknown = {}): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const problem = (status: number, title: string) => response(status, { type: 'about:blank', title, status });

describe('api client — sessione', () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    useAuthStore.getState().setAuthenticated(testUser(), 10);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('la sessione è il cookie della stessa origine: nessun header di autenticazione né CSRF', async () => {
    fetchMock.mockResolvedValue(response(200, { ok: true }));

    await expect(authFetch('/api/vehicles', { method: 'POST', body: '{}' })).resolves.toEqual({ ok: true });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.credentials).toBe('same-origin');
    const headers = new Headers(init.headers);
    // Solo Accept: niente token né header di sessione o CSRF, li sostituiscono cookie e Sec-Fetch-Site del browser.
    expect([...headers.keys()]).toEqual(['accept']);
    expect(init).not.toHaveProperty('anonymous');
  });

  it('un 401 su una chiamata autenticata chiude la sessione e lancia auth.session_expired', async () => {
    fetchMock.mockResolvedValue(problem(401, 'http.401'));

    await expect(authFetch('/api/vehicles')).rejects.toMatchObject({ title: 'auth.session_expired', status: 401 });

    expect(useAuthStore.getState()).toMatchObject({ status: 'unauthenticated', user: null, activeOrgId: null });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('un 401 auth.two_factor_required porta al secondo passo del login', async () => {
    fetchMock.mockResolvedValue(problem(401, 'auth.two_factor_required'));

    await expect(authFetch('/api/vehicles')).rejects.toMatchObject({ title: 'auth.two_factor_required', status: 401 });

    expect(useAuthStore.getState().status).toBe('two_factor');
  });

  it('con anonymous un 401 è la risposta da mostrare: lo store non cambia', async () => {
    fetchMock.mockResolvedValue(problem(401, 'auth.invalid_credentials'));

    await expect(authFetch('/api/auth/login', { method: 'POST', anonymous: true })).rejects.toMatchObject({
      title: 'auth.invalid_credentials',
    });

    expect(useAuthStore.getState().status).toBe('authenticated');
  });

  it('un 403 (es. CSRF) non tocca la sessione', async () => {
    fetchMock.mockResolvedValue(problem(403, 'auth.csrf_invalid'));

    await expect(authFetch('/api/vehicles', { method: 'POST' })).rejects.toBeInstanceOf(ApiError);

    expect(useAuthStore.getState().status).toBe('authenticated');
  });

  it('chiede JSON, tranne per i download di file', async () => {
    fetchMock.mockImplementation(async () => response(200, { ok: true }));

    await authFetch('/api/vehicles');
    await authFetchBlob('/api/attachments/1/download');

    const accept = fetchMock.mock.calls.map(([, init]) => new Headers((init as RequestInit).headers).get('Accept'));
    expect(accept).toEqual(['application/json', '*/*']);
  });

  it('un Accept passato dal chiamante non viene sovrascritto', async () => {
    fetchMock.mockImplementation(async () => response(200, { ok: true }));

    await authFetch('/api/vehicles', { headers: { Accept: 'text/csv' } });

    expect(new Headers((fetchMock.mock.calls[0]?.[1] as RequestInit).headers).get('Accept')).toBe('text/csv');
  });

  it('il corpo Problem Details diventa titolo, stato ed errori di campo', async () => {
    fetchMock.mockResolvedValue(
      response(422, { type: 'about:blank', title: 'validation_failed', status: 422, errors: [{ field: 'name', message: 'vehicle.name.required' }] }),
    );

    await expect(authFetch('/api/vehicles', { method: 'POST' })).rejects.toMatchObject({
      title: 'validation_failed',
      status: 422,
      errors: [{ field: 'name', message: 'vehicle.name.required' }],
    });
  });

  it('un errore senza corpo JSON ricade su http.<status>', async () => {
    fetchMock.mockResolvedValue(new Response('<html>oops</html>', { status: 502 }));

    await expect(authFetch('/api/vehicles')).rejects.toMatchObject({ title: 'http.502', status: 502 });
  });
});
