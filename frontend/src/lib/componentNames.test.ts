import { describe, expect, it } from 'vitest';
import { componentFollowUpPath, nameKey, newComponentIds } from './componentNames';

describe('componentNames', () => {
  it('confronta i nomi come il server: spazi e maiuscole non contano', () => {
    expect(nameKey('  Filtro   OLIO ')).toBe('filtro olio');
  });

  it('riconosce i componenti appena creati tra quelli salvati con il lavoro', () => {
    const saved = [
      { id: 1, name: 'Filtro olio' },
      { id: 2, name: 'Olio 5W30' },
      { id: 3, name: 'Candele' },
    ];
    expect(newComponentIds(saved, ['olio  5w30', 'Candele'])).toEqual([2, 3]);
    expect(newComponentIds(saved, [])).toEqual([]);
  });

  it('apre il primo da completare con gli altri in coda e la pagina a cui tornare', () => {
    expect(componentFollowUpPath([2, 3, 4], '/maintenance/9')).toBe('/components/2/edit?back=%2Fmaintenance%2F9&next=3%2C4');
    expect(componentFollowUpPath([2], '/maintenance/9')).toBe('/components/2/edit?back=%2Fmaintenance%2F9');
    expect(componentFollowUpPath([], '/maintenance/9')).toBeNull();
  });
});
