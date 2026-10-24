import { useEffect } from 'react';
import { applyTextSize, applyTheme, useThemeStore } from '@/stores/useThemeStore';

/**
 * useThemeEffect — applica l'aspetto scelto su <html>: class 'dark' (riascoltando prefers-color-scheme quando
 * theme='system') e class 'text-large' per il testo grande.
 */
export function useThemeEffect() {
  const theme = useThemeStore((s) => s.theme);
  const largeText = useThemeStore((s) => s.largeText);

  useEffect(() => {
    applyTheme(theme);

    if (theme !== 'system') return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => applyTheme('system');
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, [theme]);

  useEffect(() => {
    applyTextSize(largeText);
  }, [largeText]);
}
