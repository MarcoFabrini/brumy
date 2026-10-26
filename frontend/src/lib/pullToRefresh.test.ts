import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  GESTURE_SLOP,
  MAX_PULL,
  PULL_THRESHOLD,
  classifyGesture,
  isPastThreshold,
  isPullToRefreshEnabled,
  pullDistance,
  shouldIgnoreTarget,
} from './pullToRefresh';

describe('classifyGesture', () => {
  it('aspetta finché il dito si è mosso poco', () => {
    expect(classifyGesture(2, GESTURE_SLOP - 1, 0)).toBe('pending');
  });

  it('un trascinamento chiaramente verso il basso è un pull', () => {
    expect(classifyGesture(3, 30, 0)).toBe('pull');
  });

  it('ignora swipe orizzontali e diagonali', () => {
    expect(classifyGesture(40, 10, 0)).toBe('ignore');
    expect(classifyGesture(25, 30, 0)).toBe('ignore');
  });

  it('ignora il trascinamento verso l\'alto', () => {
    expect(classifyGesture(0, -30, 0)).toBe('ignore');
  });

  it('ignora se il contenitore non è in cima', () => {
    expect(classifyGesture(0, 60, 1)).toBe('ignore');
  });
});

describe('pullDistance', () => {
  it('segue il dito con resistenza crescente, senza mai superare il tetto', () => {
    expect(pullDistance(-10)).toBe(0);
    expect(pullDistance(40)).toBeCloseTo(23.6, 1);
    expect(pullDistance(200) - pullDistance(160)).toBeLessThan(pullDistance(40) - pullDistance(0));
    expect(pullDistance(10_000)).toBeLessThan(MAX_PULL);
    expect(pullDistance(10_000)).toBeGreaterThan(MAX_PULL * 0.95);
  });

  it('la soglia si raggiunge solo con un trascinamento deciso', () => {
    expect(isPastThreshold(pullDistance(PULL_THRESHOLD))).toBe(false);
    expect(isPastThreshold(pullDistance(PULL_THRESHOLD * 3))).toBe(true);
  });
});

describe('shouldIgnoreTarget', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('lascia passare il gesto su contenuto normale', () => {
    document.body.innerHTML = '<main><p id="t">ciao</p></main>';
    expect(shouldIgnoreTarget(document.getElementById('t'))).toBe(false);
  });

  it('ignora campi di testo, grafici e zone marcate', () => {
    document.body.innerHTML =
      '<input id="i"><div class="recharts-wrapper"><span id="c"></span></div><div data-no-pull-refresh><b id="n"></b></div>';
    expect(shouldIgnoreTarget(document.getElementById('i'))).toBe(true);
    expect(shouldIgnoreTarget(document.getElementById('c'))).toBe(true);
    expect(shouldIgnoreTarget(document.getElementById('n'))).toBe(true);
  });

  it('ignora se un campo ha il focus o c\'è un dialog aperto', () => {
    document.body.innerHTML = '<textarea id="x"></textarea><p id="t"></p>';
    document.getElementById('x')?.focus();
    expect(shouldIgnoreTarget(document.getElementById('t'))).toBe(true);

    document.body.innerHTML = '<p id="t"></p><div role="dialog"></div>';
    expect(shouldIgnoreTarget(document.getElementById('t'))).toBe(true);
  });
});

describe('isPullToRefreshEnabled', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function win(standalone: boolean, mobile: boolean, iosStandalone?: boolean): Window {
    return {
      matchMedia: (query: string) => ({ matches: query.includes('display-mode') ? standalone : mobile }),
      navigator: { standalone: iosStandalone },
    } as unknown as Window;
  }

  it('serve PWA installata e layout mobile', () => {
    expect(isPullToRefreshEnabled(win(true, true))).toBe(true);
    expect(isPullToRefreshEnabled(win(false, true, true))).toBe(true);
  });

  it('non in una scheda del browser (c\'è il gesto nativo) né su desktop', () => {
    expect(isPullToRefreshEnabled(win(false, true))).toBe(false);
    expect(isPullToRefreshEnabled(win(true, false))).toBe(false);
  });

  it('senza matchMedia è spento', () => {
    expect(isPullToRefreshEnabled({} as Window)).toBe(false);
  });
});
