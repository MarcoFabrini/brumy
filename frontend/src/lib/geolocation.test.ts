import { describe, expect, it } from 'vitest';
import { requestPosition } from './geolocation';

const error = (code: number) => ({ code, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 }) as GeolocationPositionError;

function geolocation(result: { ok: GeolocationCoordinates } | { fail: GeolocationPositionError }): Geolocation {
  return {
    getCurrentPosition: (ok: PositionCallback, fail?: PositionErrorCallback | null) =>
      'ok' in result ? ok({ coords: result.ok } as GeolocationPosition) : fail?.(result.fail),
  } as Geolocation;
}

describe('requestPosition', () => {
  it('arrotonda la posizione a ~10 metri', async () => {
    const coords = { latitude: 44.647123456, longitude: 10.925219 } as GeolocationCoordinates;
    await expect(requestPosition(geolocation({ ok: coords }))).resolves.toEqual({ latitude: 44.6471, longitude: 10.9252 });
  });

  it('distingue posizione negata, lenta e non disponibile', async () => {
    await expect(requestPosition(geolocation({ fail: error(1) }))).rejects.toBe('denied');
    await expect(requestPosition(geolocation({ fail: error(3) }))).rejects.toBe('timeout');
    await expect(requestPosition(geolocation({ fail: error(2) }))).rejects.toBe('unavailable');
  });

  it('senza geolocalizzazione (browser vecchio, pagina non HTTPS) è non disponibile', async () => {
    await expect(requestPosition(undefined)).rejects.toBe('unavailable');
  });
});
