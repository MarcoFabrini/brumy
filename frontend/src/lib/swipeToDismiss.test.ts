import { describe, expect, it } from 'vitest';
import { DISMISS_DISTANCE, FLICK_MIN_DISTANCE, shouldDismiss, shouldIgnoreSwipeTarget } from './swipeToDismiss';

describe('shouldDismiss', () => {
  it('chiude oltre un quarto dell\'altezza, o oltre la distanza fissa per i fogli alti', () => {
    expect(shouldDismiss(90, 0, 400)).toBe(false);
    expect(shouldDismiss(100, 0, 400)).toBe(true);
    expect(shouldDismiss(DISMISS_DISTANCE - 1, 0, 900)).toBe(false);
    expect(shouldDismiss(DISMISS_DISTANCE, 0, 900)).toBe(true);
  });

  it('un colpo veloce chiude anche se corto, ma non un tocco nervoso', () => {
    expect(shouldDismiss(40, 0.8, 900)).toBe(true);
    expect(shouldDismiss(FLICK_MIN_DISTANCE - 1, 2, 900)).toBe(false);
  });
});

describe('shouldIgnoreSwipeTarget', () => {
  function sheetWith(html: string) {
    const sheet = document.createElement('div');
    sheet.innerHTML = html;
    document.body.append(sheet);
    return sheet;
  }

  it('lascia stare campi di testo e aree marcate', () => {
    const sheet = sheetWith('<input id="a" /><div data-no-swipe-dismiss><span id="b"></span></div><p id="c"></p>');
    expect(shouldIgnoreSwipeTarget(sheet.querySelector('#a'), sheet)).toBe(true);
    expect(shouldIgnoreSwipeTarget(sheet.querySelector('#b'), sheet)).toBe(true);
    expect(shouldIgnoreSwipeTarget(sheet.querySelector('#c'), sheet)).toBe(false);
  });

  it('non chiude se il foglio o un\'area dentro è già scorsa verso il basso', () => {
    const sheet = sheetWith('<div id="list"><p id="row"></p></div>');
    const list = sheet.querySelector('#list')!;
    const row = sheet.querySelector('#row');
    list.scrollTop = 30;
    expect(shouldIgnoreSwipeTarget(row, sheet)).toBe(true);
    list.scrollTop = 0;
    sheet.scrollTop = 10;
    expect(shouldIgnoreSwipeTarget(row, sheet)).toBe(true);
    sheet.scrollTop = 0;
    expect(shouldIgnoreSwipeTarget(row, sheet)).toBe(false);
  });
});
