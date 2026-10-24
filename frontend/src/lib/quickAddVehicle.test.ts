import { describe, expect, it } from 'vitest';
import { pickQuickAddVehicle, vehicleIdFromLocation } from './quickAddVehicle';

describe('pickQuickAddVehicle', () => {
  it('il veicolo che si sta guardando vince', () => {
    expect(pickQuickAddVehicle([1, 2, 3], 2, 3)).toBe(2);
  });

  it("senza contesto vale l'ultimo usato", () => {
    expect(pickQuickAddVehicle([1, 2, 3], null, 3)).toBe(3);
  });

  it('un contesto o un ultimo usato che non è fra i proposti (condiviso, archiviato, sparito) si ignora', () => {
    expect(pickQuickAddVehicle([1, 2], 9, 8)).toBe(1);
  });

  it('senza veicoli non sceglie nulla', () => {
    expect(pickQuickAddVehicle([], 1, 1)).toBeNull();
  });
});

describe('vehicleIdFromLocation', () => {
  it.each([
    ['/vehicles/7', '', 7],
    ['/vehicles/7/edit', '', 7],
    ['/refueling', '?vehicleId=4', 4],
    ['/vehicles/new', '', null],
    ['/', '', null],
    ['/refueling', '?vehicleId=abc', null],
  ])('%s%s → %s', (path, search, expected) => {
    expect(vehicleIdFromLocation(path, search)).toBe(expected);
  });
});
