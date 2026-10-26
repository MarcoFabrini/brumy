import { useRef } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { PullToRefresh } from './PullToRefresh';
import i18n from '@/i18n';
import * as pwaUpdate from '@/lib/pwaUpdate';

/** Come AppLayout: un <main> scrollabile con il contenuto dentro l'indicatore. */
function Harness() {
  const ref = useRef<HTMLElement>(null);
  return (
    <QueryClientProvider client={new QueryClient()}>
      <main ref={ref} data-testid="scroller">
        <PullToRefresh scrollRef={ref}>
          <p data-testid="content">contenuto</p>
          <input data-testid="field" />
        </PullToRefresh>
      </main>
    </QueryClientProvider>
  );
}

function stubEnvironment(standalone: boolean, mobile: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: query.includes('display-mode') ? standalone : query.includes('max-width') ? mobile : false,
      addEventListener: () => {},
      removeEventListener: () => {},
    })),
  );
}

const touch = (x: number, y: number) => ({ touches: [{ clientX: x, clientY: y }] });

describe('PullToRefresh', () => {
  let refresh: ReturnType<typeof vi.spyOn>;

  beforeEach(async () => {
    await i18n.changeLanguage('it');
    refresh = vi.spyOn(pwaUpdate, 'refreshInPlace').mockResolvedValue(undefined);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('un tirone oltre la soglia mostra "Rilascia" e al rilascio aggiorna', () => {
    stubEnvironment(true, true);
    render(<Harness />);
    const scroller = screen.getByTestId('scroller');

    fireEvent.touchStart(scroller, touch(100, 100));
    fireEvent.touchMove(scroller, touch(102, 130));
    expect(screen.getByRole('status')).toHaveTextContent('Trascina per aggiornare');

    fireEvent.touchMove(scroller, touch(102, 300));
    expect(screen.getByRole('status')).toHaveTextContent('Rilascia per aggiornare');
    // La pagina scende col dito
    expect(screen.getByTestId('content').parentElement?.style.transform).toMatch(/^translateY\(\d/);

    fireEvent.touchEnd(scroller);
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('status')).toHaveTextContent('Aggiornamento in corso');
  });

  it('rilasciando sotto la soglia non aggiorna e l\'indicatore sparisce', () => {
    stubEnvironment(true, true);
    render(<Harness />);
    const scroller = screen.getByTestId('scroller');

    fireEvent.touchStart(scroller, touch(100, 100));
    fireEvent.touchMove(scroller, touch(100, 140));
    fireEvent.touchEnd(scroller);

    expect(refresh).not.toHaveBeenCalled();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('non parte se il contenitore non è in cima', () => {
    stubEnvironment(true, true);
    render(<Harness />);
    const scroller = screen.getByTestId('scroller');
    scroller.scrollTop = 50;

    fireEvent.touchStart(scroller, touch(100, 100));
    fireEvent.touchMove(scroller, touch(100, 300));
    fireEvent.touchEnd(scroller);

    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(refresh).not.toHaveBeenCalled();
  });

  it('ignora uno swipe orizzontale', () => {
    stubEnvironment(true, true);
    render(<Harness />);
    const scroller = screen.getByTestId('scroller');

    fireEvent.touchStart(scroller, touch(100, 100));
    fireEvent.touchMove(scroller, touch(220, 130));
    fireEvent.touchMove(scroller, touch(260, 300));
    fireEvent.touchEnd(scroller);

    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(refresh).not.toHaveBeenCalled();
  });

  it('ignora il gesto che parte da un campo di testo', () => {
    stubEnvironment(true, true);
    render(<Harness />);

    fireEvent.touchStart(screen.getByTestId('field'), touch(100, 100));
    fireEvent.touchMove(screen.getByTestId('field'), touch(100, 300));
    fireEvent.touchEnd(screen.getByTestId('field'));

    expect(refresh).not.toHaveBeenCalled();
  });

  it('ignora il gesto se c\'è un dialog aperto', () => {
    stubEnvironment(true, true);
    render(
      <>
        <Harness />
        <div role="dialog" />
      </>,
    );
    const scroller = screen.getByTestId('scroller');

    fireEvent.touchStart(scroller, touch(100, 100));
    fireEvent.touchMove(scroller, touch(100, 300));
    fireEvent.touchEnd(scroller);

    expect(refresh).not.toHaveBeenCalled();
  });

  it('è spento in una scheda del browser e su desktop', () => {
    for (const [standalone, mobile] of [
      [false, true],
      [true, false],
    ] as const) {
      stubEnvironment(standalone, mobile);
      const { unmount } = render(<Harness />);
      const scroller = screen.getByTestId('scroller');
      fireEvent.touchStart(scroller, touch(100, 100));
      fireEvent.touchMove(scroller, touch(100, 300));
      fireEvent.touchEnd(scroller);
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
      expect(refresh).not.toHaveBeenCalled();
      unmount();
    }
  });
});
