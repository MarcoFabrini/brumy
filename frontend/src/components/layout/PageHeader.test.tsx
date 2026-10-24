import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@/i18n';
import i18n from '@/i18n';
import { PageHeader } from './PageHeader';

describe('PageHeader', () => {
  it('con un titolo visibile rende quello come unico h1, grande e visibile ovunque', () => {
    render(<PageHeader title="Golf" srTitle="Manutenzioni" />);

    const h1 = screen.getAllByRole('heading', { level: 1 });
    expect(h1).toHaveLength(1);
    expect(h1[0]).toHaveTextContent('Golf');
    expect(h1[0]).not.toHaveClass('md:sr-only');
  });

  it('srTitle è il titolo grande su mobile e solo per screen reader da md (la sidebar dice già dove siamo)', () => {
    render(<PageHeader srTitle="Manutenzioni" action={<button type="button">Nuovo</button>} />);

    const h1 = screen.getByRole('heading', { level: 1, name: 'Manutenzioni' });
    expect(h1).toHaveClass('md:sr-only');
    expect(h1).not.toHaveClass('sr-only');
    expect(screen.getByRole('button', { name: 'Nuovo' })).toBeInTheDocument();
  });

  it('"Indietro" in stile iOS chiama onBack', async () => {
    await i18n.changeLanguage('it');
    const onBack = vi.fn();
    render(<PageHeader title="Golf" onBack={onBack} />);

    // La barra compatta ha un altro "Indietro", ma finché non si scorre è fuori dall'albero di accessibilità.
    await userEvent.click(screen.getByRole('button', { name: 'Indietro' }));
    expect(onBack).toHaveBeenCalledTimes(1);
  });

  it('il titolo della barra compatta non è un secondo titolo per gli screen reader', () => {
    render(<PageHeader title="Golf" />);

    expect(screen.getAllByText('Golf')).toHaveLength(2);
    expect(screen.getAllByRole('heading')).toHaveLength(1);
  });

  it('senza nulla da mostrare né annunciare non rende niente', () => {
    const { container } = render(<PageHeader />);
    expect(container).toBeEmptyDOMElement();
  });
});
