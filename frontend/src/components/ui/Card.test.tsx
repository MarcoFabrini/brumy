import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { CardContent, CardTitle } from './Card';

/**
 * Regressione: da desktop le card senza CardHeader avevano padding-top 0 e
 * 24px sotto (md:p-6 md:pt-0 del default non veniva rimosso da un semplice
 * `p-4` nel className), quindi icona e testo risultavano schiacciati in alto.
 */
describe('CardContent', () => {
  it('di default tiene pt-0 (sta sotto un CardHeader)', () => {
    const { container } = render(<CardContent>x</CardContent>);
    const cls = (container.firstElementChild as HTMLElement).className;
    expect(cls).toContain('pt-0');
    expect(cls).toContain('md:p-6');
  });

  it('standalone: padding uniforme, nessun pt-0 né md:p-6 da desktop', () => {
    const { container } = render(<CardContent standalone>x</CardContent>);
    const cls = (container.firstElementChild as HTMLElement).className;
    expect(cls).toBe('p-4');
  });

  it('standalone mantiene le classi del chiamante', () => {
    const { container } = render(
      <CardContent standalone className="flex items-center gap-4">
        x
      </CardContent>,
    );
    const cls = (container.firstElementChild as HTMLElement).className;
    expect(cls).toContain('flex items-center gap-4');
    expect(cls).not.toMatch(/pt-0|md:p-6/);
  });
});

describe('CardTitle', () => {
  it('di default non ha semantica di heading', () => {
    render(<CardTitle>Titolo</CardTitle>);
    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });

  it('con `as` rende il tag richiesto mantenendo lo stile', () => {
    render(<CardTitle as="h2">Titolo</CardTitle>);
    const heading = screen.getByRole('heading', { level: 2, name: 'Titolo' });
    expect(heading).toHaveClass('font-semibold');
  });
});
