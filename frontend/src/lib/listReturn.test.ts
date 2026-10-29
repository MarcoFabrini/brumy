import { describe, expect, it } from 'vitest';
import { listReturnPath } from './listReturn';

const FALLBACK = '/maintenance?category=scheduled&vehicleId=1';

describe('listReturnPath', () => {
  it('torna all\'elenco da cui si è arrivati, con la sua ricerca', () => {
    expect(listReturnPath({ from: '/maintenance?vehicleId=1&q=tagl' }, '/maintenance', FALLBACK)).toBe(
      '/maintenance?vehicleId=1&q=tagl',
    );
    expect(listReturnPath({ from: '/maintenance' }, '/maintenance', FALLBACK)).toBe('/maintenance');
  });

  it.each([
    ['nessuno state (link diretto, notifica)', null],
    ['state non oggetto', 'from'],
    ['senza from', { other: 1 }],
    ['from non stringa', { from: 42 }],
    ['altro elenco', { from: '/refueling?vehicleId=1&q=x' }],
    ['pagina che inizia allo stesso modo', { from: '/maintenance/5' }],
    ['dettaglio veicolo', { from: '/vehicles/1' }],
    ['URL esterno', { from: 'https://evil.example/maintenance' }],
    ['protocol-relative', { from: '//evil.example/maintenance' }],
    ['backslash', { from: '/\\evil.example' }],
  ])('%s → l\'elenco di sempre', (_label, state) => {
    expect(listReturnPath(state, '/maintenance', FALLBACK)).toBe(FALLBACK);
  });
});
