import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useDebouncedValue } from './useDebouncedValue';

describe('useDebouncedValue', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('parte dal valore iniziale, senza attesa', () => {
    const { result } = renderHook(() => useDebouncedValue('tagl', 300));
    expect(result.current).toBe('tagl');
  });

  it('cambia solo dopo il ritardo dall\'ultimo cambiamento', () => {
    const { result, rerender } = renderHook(({ value }) => useDebouncedValue(value, 300), {
      initialProps: { value: '' },
    });

    rerender({ value: 't' });
    act(() => vi.advanceTimersByTime(200));
    rerender({ value: 'ta' });
    act(() => vi.advanceTimersByTime(200));
    expect(result.current).toBe('');

    rerender({ value: 'tag' });
    act(() => vi.advanceTimersByTime(299));
    expect(result.current).toBe('');
    act(() => vi.advanceTimersByTime(1));
    expect(result.current).toBe('tag');
  });

  it('smontato prima del ritardo non aggiorna nulla (timer cancellato)', () => {
    const clear = vi.spyOn(globalThis, 'clearTimeout');
    const { rerender, unmount } = renderHook(({ value }) => useDebouncedValue(value, 300), {
      initialProps: { value: 'a' },
    });
    rerender({ value: 'ab' });
    unmount();

    expect(clear).toHaveBeenCalled();
    expect(vi.getTimerCount()).toBe(0);
  });
});
