import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Legge i token reali di index.css: se qualcuno li ritocca e il contrasto scende, il test lo segnala.
// Con `css: false` l'import ?raw torna vuoto: si legge il file dalla radice del frontend (cwd di vitest).
const css = readFileSync(resolve(process.cwd(), 'src/index.css'), 'utf8');

function block(selector: string): string {
  const start = css.indexOf(`${selector} {`);
  const end = css.indexOf('}', start);
  return css.slice(start, end);
}

type Hsl = [number, number, number];

function token(scope: string, name: string): Hsl {
  const m = new RegExp(`--${name}:\\s*([\\d.]+)\\s+([\\d.]+)%\\s+([\\d.]+)%`).exec(scope);
  if (!m) throw new Error(`token --${name} non trovato`);
  return [Number(m[1]), Number(m[2]), Number(m[3])];
}

function toRgb([h, s, l]: Hsl): [number, number, number] {
  const sat = s / 100;
  const light = l / 100;
  const a = sat * Math.min(light, 1 - light);
  const f = (n: number) => {
    const k = (n + h / 30) % 12;
    return light - a * Math.max(-1, Math.min(k - 3, Math.min(9 - k, 1)));
  };
  return [f(0), f(8), f(4)];
}

function luminance(rgb: [number, number, number]): number {
  const [r, g, b] = rgb.map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * (r ?? 0) + 0.7152 * (g ?? 0) + 0.0722 * (b ?? 0);
}

function ratio(a: [number, number, number], b: [number, number, number]): number {
  const x = luminance(a);
  const y = luminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

describe.each([
  ['chiaro', block(':root')],
  ['scuro', block('.dark')],
])('contrasto del rosso distruttivo (tema %s)', (_name, scope) => {
  const text = toRgb(token(scope, 'destructive-text'));

  it.each(['background', 'card', 'muted'])('il testo errore ha almeno 4.5:1 su %s', (surface) => {
    expect(ratio(text, toRgb(token(scope, surface)))).toBeGreaterThanOrEqual(4.5);
  });

  it('il testo del pulsante distruttivo ha almeno 4.5:1 sul suo sfondo', () => {
    const fg = toRgb(token(scope, 'destructive-foreground'));
    expect(ratio(fg, toRgb(token(scope, 'destructive')))).toBeGreaterThanOrEqual(4.5);
  });
});

describe.each([
  ['chiaro', block(':root')],
  ['scuro', block('.dark')],
])('contrasto dei colori di sistema iOS (tema %s)', (_name, scope) => {
  it('il testo dei pulsanti pieni ha almeno 4.5:1 sul blu dell\'app', () => {
    expect(ratio(toRgb(token(scope, 'primary-foreground')), toRgb(token(scope, 'primary')))).toBeGreaterThanOrEqual(4.5);
  });

  it.each(['background', 'card'])('il testo secondario ha almeno 4.5:1 su %s', (surface) => {
    expect(ratio(toRgb(token(scope, 'muted-foreground')), toRgb(token(scope, surface)))).toBeGreaterThanOrEqual(4.5);
  });

  it('il blu usato come testo (link, "Indietro") ha almeno 4.5:1 sullo sfondo', () => {
    expect(ratio(toRgb(token(scope, 'primary')), toRgb(token(scope, 'background')))).toBeGreaterThanOrEqual(4.5);
  });
});
