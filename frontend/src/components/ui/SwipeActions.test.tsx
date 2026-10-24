import { beforeAll, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SwipeActions } from './SwipeActions';

function renderRow(onAction = vi.fn(), onRowClick = vi.fn()) {
  render(
    <SwipeActions actions={[{ label: 'Completa', onAction }]}>
      <button type="button" onClick={onRowClick}>
        Revisione
      </button>
    </SwipeActions>,
  );
  const content = screen.getByRole('button', { name: 'Revisione' }).parentElement as HTMLElement;
  return { content, onAction, onRowClick };
}

const drag = (el: HTMLElement, dx: number, dy = 0) => {
  fireEvent.pointerDown(el, { clientX: 300, clientY: 100 });
  fireEvent.pointerMove(el, { clientX: 300 + dx / 2, clientY: 100 + dy / 2 });
  fireEvent.pointerMove(el, { clientX: 300 + dx, clientY: 100 + dy });
  fireEvent.pointerUp(el);
};

describe('SwipeActions', () => {
  // jsdom non ha PointerEvent: senza, gli eventi di test arriverebbero senza coordinate.
  beforeAll(() => {
    if (!('PointerEvent' in window)) {
      Object.defineProperty(window, 'PointerEvent', { configurable: true, value: class extends MouseEvent {} });
    }
  });

  it('trascinando a sinistra oltre metà corsa la riga resta aperta sulle azioni', () => {
    const { content } = renderRow();

    drag(content, -70);

    expect(content.style.transform).toBe('translateX(-88px)');
  });

  it('un trascinamento corto torna indietro', () => {
    const { content } = renderRow();

    drag(content, -30);

    expect(content.style.transform).toBe('translateX(0px)');
  });

  it('un gesto verticale è uno scroll: la riga non si muove', () => {
    const { content } = renderRow();

    drag(content, -20, 80);

    expect(content.style.transform).toBe('translateX(0px)');
  });

  it('con la riga aperta il tocco sul contenuto la richiude invece di aprirlo', async () => {
    const { content, onRowClick } = renderRow();
    drag(content, -70);

    await userEvent.click(screen.getByRole('button', { name: 'Revisione' }));

    expect(onRowClick).not.toHaveBeenCalled();
    expect(content.style.transform).toBe('translateX(0px)');
  });

  it("l'azione è un vero bottone: da tastiera prende il focus, apre la riga e si attiva", async () => {
    const { content, onAction } = renderRow();
    const user = userEvent.setup();

    await user.tab(); // la riga
    await user.tab(); // la sua azione
    expect(screen.getByRole('button', { name: 'Completa' })).toHaveFocus();
    expect(content.style.transform).toBe('translateX(-88px)');
    await user.keyboard('{Enter}');

    expect(onAction).toHaveBeenCalledTimes(1);
  });
});
