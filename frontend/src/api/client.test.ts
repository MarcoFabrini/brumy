import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, REFRESH_RETRY_DELAY_MS, authFetch, authFetchBlob, refreshAccessToken } from '@/api/client';
import { useAuthStore, type User } from '@/stores/useAuthStore';

const user = { id: 1, locale: 'it' } as User;

function response(status: number, body: unknown = {}): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('api client — sessione e refresh', () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    useAuthStore.getState().setAuthenticated('old-token', user);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  const refreshCalls = () => fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/api/auth/refresh'));

  it('refresh riuscito: salva il nuovo token e riprova la richiesta una volta', async () => {
    fetchMock
      .mockResolvedValueOnce(response(401))
      .mockResolvedValueOnce(response(200, { access_token: 'new-token' }))
      .mockResolvedValueOnce(response(200, { ok: true }));

    await expect(authFetch('/api/vehicles')).resolves.toEqual({ ok: true });

    expect(useAuthStore.getState().accessToken).toBe('new-token');
    expect(useAuthStore.getState().status).toBe('authenticated');
  });

  it('risposta di refresh senza access_token valido: non si salva niente e non si fa logout', async () => {
    fetchMock.mockResolvedValueOnce(response(200, { access_token: 42 }));

    await expect(refreshAccessToken()).resolves.toBe('unavailable');

    expect(useAuthStore.getState().accessToken).toBe('old-token');
    expect(useAuthStore.getState().status).toBe('authenticated');
  });

  it('refresh rifiutato due volte di fila (401): sessione finita → logout', async () => {
    vi.useFakeTimers();
    fetchMock
      .mockResolvedValueOnce(response(401))
      .mockResolvedValueOnce(response(401))
      .mockResolvedValueOnce(response(401));

    const result = authFetch('/api/vehicles');
    const settled = expect(result).rejects.toMatchObject({ title: 'auth.session_expired' });
    await vi.advanceTimersByTimeAsync(REFRESH_RETRY_DELAY_MS);
    await settled;

    expect(refreshCalls()).toHaveLength(2);
    expect(useAuthStore.getState().status).toBe('unauthenticated');
  });

  it('refresh perso contro un\'altra scheda (401 poi 200): riprova dopo una pausa e NON fa logout', async () => {
    vi.useFakeTimers();
    fetchMock
      .mockResolvedValueOnce(response(401)) // richiesta con token scaduto
      .mockResolvedValueOnce(response(401)) // refresh: l'altra scheda ha già ruotato il cookie
      .mockResolvedValueOnce(response(200, { access_token: 'rotated' })) // secondo tentativo, cookie nuovo
      .mockResolvedValueOnce(response(200, { ok: true })); // richiesta riprovata

    const result = authFetch('/api/vehicles');
    // prima della pausa il secondo tentativo non è ancora partito
    await vi.advanceTimersByTimeAsync(REFRESH_RETRY_DELAY_MS - 1);
    expect(refreshCalls()).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);

    await expect(result).resolves.toEqual({ ok: true });
    expect(refreshCalls()).toHaveLength(2);
    expect(useAuthStore.getState().accessToken).toBe('rotated');
    expect(useAuthStore.getState().status).toBe('authenticated');
  });

  it.each([500, 502, 503])('refresh con HTTP %i: NON fa logout (server in difficoltà)', async (status) => {
    fetchMock.mockResolvedValueOnce(response(401)).mockResolvedValueOnce(response(status));

    await expect(authFetch('/api/vehicles')).rejects.toBeInstanceOf(ApiError);

    expect(useAuthStore.getState().status).toBe('authenticated');
    expect(useAuthStore.getState().accessToken).toBe('old-token');
  });

  it('refresh con rete assente: NON fa logout', async () => {
    fetchMock.mockResolvedValueOnce(response(401)).mockRejectedValueOnce(new TypeError('Failed to fetch'));

    await expect(authFetch('/api/vehicles')).rejects.toMatchObject({ title: 'network.unavailable' });

    expect(useAuthStore.getState().status).toBe('authenticated');
  });

  it('refresh simultanei vengono coalescenti in una sola chiamata', async () => {
    fetchMock.mockResolvedValue(response(200, { access_token: 'shared' }));

    const [a, b] = await Promise.all([refreshAccessToken(), refreshAccessToken()]);

    expect(a).toBe('ok');
    expect(b).toBe('ok');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('esito del refresh: invalid solo dopo due rifiuti (400/401), 5xx e rete unavailable', async () => {
    vi.useFakeTimers();

    fetchMock.mockResolvedValueOnce(response(400)).mockResolvedValueOnce(response(401));
    const invalid = refreshAccessToken();
    await vi.advanceTimersByTimeAsync(REFRESH_RETRY_DELAY_MS);
    expect(await invalid).toBe('invalid');

    // 5xx e rete al primo tentativo: nessun secondo tentativo, nessuna pausa
    fetchMock.mockResolvedValueOnce(response(503));
    expect(await refreshAccessToken()).toBe('unavailable');

    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    expect(await refreshAccessToken()).toBe('unavailable');
  });

  it('il secondo tentativo con rete assente o 5xx è unavailable, non invalid', async () => {
    vi.useFakeTimers();
    fetchMock.mockResolvedValueOnce(response(401)).mockRejectedValueOnce(new TypeError('offline'));
    const result = refreshAccessToken();
    await vi.advanceTimersByTimeAsync(REFRESH_RETRY_DELAY_MS);

    expect(await result).toBe('unavailable');
    expect(refreshCalls()).toHaveLength(2);
  });

  it('i refresh simultanei restano coalescenti anche durante la pausa del secondo tentativo', async () => {
    vi.useFakeTimers();
    fetchMock
      .mockResolvedValueOnce(response(401))
      .mockResolvedValueOnce(response(200, { access_token: 'shared' }));

    const first = refreshAccessToken();
    await vi.advanceTimersByTimeAsync(10);
    const second = refreshAccessToken(); // arriva mentre il primo aspetta la pausa
    await vi.advanceTimersByTimeAsync(REFRESH_RETRY_DELAY_MS);

    expect(await first).toBe('ok');
    expect(await second).toBe('ok');
    expect(refreshCalls()).toHaveLength(2);
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
