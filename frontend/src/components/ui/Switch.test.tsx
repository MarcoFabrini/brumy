import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Switch } from './Switch';

describe('Switch', () => {
  it('è un interruttore accessibile che segnala lo stato e lo inverte al tocco', async () => {
    const onCheckedChange = vi.fn();
    render(<Switch checked={false} onCheckedChange={onCheckedChange} aria-label="Testo grande" />);

    const sw = screen.getByRole('switch', { name: 'Testo grande' });
    expect(sw).toHaveAttribute('aria-checked', 'false');
    await userEvent.click(sw);
    expect(onCheckedChange).toHaveBeenCalledWith(true);
  });

  it('disattivato non cambia stato', async () => {
    const onCheckedChange = vi.fn();
    render(<Switch checked onCheckedChange={onCheckedChange} disabled aria-label="Testo grande" />);

    await userEvent.click(screen.getByRole('switch', { name: 'Testo grande' }));
    expect(onCheckedChange).not.toHaveBeenCalled();
  });
});
