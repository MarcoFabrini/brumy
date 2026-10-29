import { describe, expect, it } from 'vitest';
import { keepPreviousOf, LIST_PAGE_SIZE, nextListPage, searchKeyPart } from './pagination';

const page = (n: number) => Array.from({ length: n }, (_, i) => i);

describe('pagination', () => {
  it('chiede la pagina successiva solo dopo una pagina piena', () => {
    expect(nextListPage(page(LIST_PAGE_SIZE), [page(LIST_PAGE_SIZE)])).toBe(2);
    expect(nextListPage(page(LIST_PAGE_SIZE), [page(LIST_PAGE_SIZE), page(LIST_PAGE_SIZE)])).toBe(3);
    expect(nextListPage(page(LIST_PAGE_SIZE - 1), [page(LIST_PAGE_SIZE), page(LIST_PAGE_SIZE - 1)])).toBeUndefined();
    expect(nextListPage([], [[]])).toBeUndefined();
  });

  it('la chiave della ricerca si aggiunge solo con una ricerca', () => {
    expect(searchKeyPart(undefined)).toEqual([]);
    expect(searchKeyPart('')).toEqual([]);
    expect(searchKeyPart('q=tagl')).toEqual(['search', 'q=tagl']);
  });

  it('tiene i dati precedenti solo dello stesso elenco', () => {
    const keep = keepPreviousOf(['maintenances', 'vehicle', 1, 'scheduled']);
    const previous = { pages: [['a']] };

    expect(keep(previous, { queryKey: ['maintenances', 'vehicle', 1, 'scheduled'] })).toBe(previous);
    expect(keep(previous, { queryKey: ['maintenances', 'vehicle', 1, 'scheduled', 'search', 'q=x'] })).toBe(previous);
    expect(keep(previous, { queryKey: ['maintenances', 'vehicle', 2, 'scheduled'] })).toBeUndefined();
    expect(keep(previous, { queryKey: ['maintenances', 'vehicle', 1, 'inspection'] })).toBeUndefined();
    expect(keep(previous, undefined)).toBeUndefined();
  });
});
