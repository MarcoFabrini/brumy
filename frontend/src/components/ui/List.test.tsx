import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Link } from 'react-router-dom';
import { ListRow, ListSection } from './List';

describe('ListSection / ListRow', () => {
  it('la sezione ha il titoletto come h2 e la nota sotto', () => {
    render(
      <ListSection title="Preferenze" footer="Vale su questo dispositivo">
        <ListRow label="Lingua" value="Italiano" />
      </ListSection>,
    );

    expect(screen.getByRole('heading', { level: 2, name: 'Preferenze' })).toBeInTheDocument();
    expect(screen.getByText('Vale su questo dispositivo')).toBeInTheDocument();
    expect(screen.getByText('Italiano')).toBeInTheDocument();
  });

  it('con `as={Link}` la riga intera è un link (freccia decorativa)', () => {
    render(
      <MemoryRouter>
        <ListSection>
          <ListRow as={Link} to="/vehicles/1" label="Panda" description="Fiat Panda" chevron />
        </ListSection>
      </MemoryRouter>,
    );

    const link = screen.getByRole('link', { name: /Panda/ });
    expect(link).toHaveAttribute('href', '/vehicles/1');
    expect(link).toHaveTextContent('Fiat Panda');
  });

  it('con `as="button"` la riga è un bottone e ha il bersaglio da 44px', async () => {
    const onClick = vi.fn();
    render(<ListRow as="button" type="button" onClick={onClick} label="Esci" destructive />);

    const button = screen.getByRole('button', { name: 'Esci' });
    expect(button).toHaveClass('min-h-touch');
    await userEvent.click(button);
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it("l'icona è decorativa: il nome della riga resta l'etichetta", () => {
    render(<ListRow as="button" type="button" icon={<svg />} label="Notifiche" />);

    expect(screen.getByRole('button', { name: 'Notifiche' })).toBeInTheDocument();
  });
});
