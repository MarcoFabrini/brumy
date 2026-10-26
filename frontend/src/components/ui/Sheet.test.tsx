import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { Sheet, SheetContent, SheetTitle } from './Sheet';
import { Dialog, DialogContent, DialogTitle } from './Dialog';
import i18n from '@/i18n';

// changedTouches serve anche al blocco dello scroll di Radix (react-remove-scroll), che lo legge a ogni tocco
const touch = (x: number, y: number) => ({ touches: [{ clientX: x, clientY: y }], changedTouches: [{ clientX: x, clientY: y }] });
const lift = { touches: [], changedTouches: [{ clientX: 100, clientY: 100 }] };

/** Orologio degli eventi: ogni test decide quanto tempo passa fra un tocco e l'altro. */
let now = 0;

/** Trascina dal punto (100, 100) fino a `toY`, poi stacca il dito. */
function drag(el: HTMLElement, toY: number) {
  fireEvent.touchStart(el, touch(100, 100));
  fireEvent.touchMove(el, touch(100, 112));
  fireEvent.touchMove(el, touch(100, toY));
  fireEvent.touchEnd(el, lift);
}

function stubWidth(mobile: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({ matches: query.includes('max-width') ? mobile : false, addEventListener: () => {}, removeEventListener: () => {} })),
  );
}

describe('trascina giù per chiudere', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
    // jsdom non fa layout: un foglio alto 600px
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(600);
    now = 0;
    vi.spyOn(Event.prototype, 'timeStamp', 'get').mockImplementation(() => now);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  function renderSheet(side: 'bottom' | 'right' = 'bottom') {
    const onOpenChange = vi.fn();
    render(
      <Sheet open onOpenChange={onOpenChange}>
        <SheetContent side={side} aria-describedby={undefined}>
          <SheetTitle>Aggiungi</SheetTitle>
          <input data-testid="field" />
        </SheetContent>
      </Sheet>,
    );
    return onOpenChange;
  }

  it('il foglio dal basso trascinato abbastanza giù si chiude', () => {
    const onOpenChange = renderSheet();
    drag(screen.getByRole('dialog'), 300);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('un colpo veloce chiude anche se corto', () => {
    const onOpenChange = renderSheet();
    const sheet = screen.getByRole('dialog');
    fireEvent.touchStart(sheet, touch(100, 100));
    now = 20;
    fireEvent.touchMove(sheet, touch(100, 112));
    now = 60;
    fireEvent.touchMove(sheet, touch(100, 160));
    now = 70;
    fireEvent.touchEnd(sheet, lift);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('trascinato poco torna al suo posto', () => {
    const onOpenChange = renderSheet();
    const sheet = screen.getByRole('dialog');
    fireEvent.touchStart(sheet, touch(100, 100));
    now = 50;
    fireEvent.touchMove(sheet, touch(100, 112));
    now = 400;
    fireEvent.touchMove(sheet, touch(100, 160));
    expect(sheet.style.transform).toBe('translate3d(0, 48px, 0)');

    // Il dito si ferma prima di staccarsi: non è un colpo veloce
    now = 900;
    fireEvent.touchEnd(sheet, lift);
    expect(onOpenChange).not.toHaveBeenCalled();
    expect(sheet.style.transform).toBe('');
  });

  it('non parte da un campo di testo', () => {
    const onOpenChange = renderSheet();
    drag(screen.getByTestId('field'), 400);
    expect(onOpenChange).not.toHaveBeenCalled();
  });

  it('il foglio laterale non si chiude trascinando', () => {
    const onOpenChange = renderSheet('right');
    drag(screen.getByRole('dialog'), 400);
    expect(onOpenChange).not.toHaveBeenCalled();
  });

  it('il dialog a tutto schermo si chiude trascinando solo dove è un foglio (mobile)', () => {
    for (const mobile of [true, false]) {
      stubWidth(mobile);
      const onOpenChange = vi.fn();
      const { unmount } = render(
        <Dialog open onOpenChange={onOpenChange}>
          <DialogContent mobileFullScreen aria-describedby={undefined}>
            <DialogTitle>Nuovo rifornimento</DialogTitle>
          </DialogContent>
        </Dialog>,
      );
      drag(screen.getByRole('dialog'), 400);
      expect(onOpenChange).toHaveBeenCalledTimes(mobile ? 1 : 0);
      unmount();
    }
  });
});
