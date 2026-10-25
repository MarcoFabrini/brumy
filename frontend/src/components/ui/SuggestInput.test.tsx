import { describe, it, expect, vi } from 'vitest';
import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SuggestInput } from './SuggestInput';

const NAMES = ['Eni A1', 'Q8 Via Roma', 'Caffè Rossi', 'Shell', 'Tamoil', 'IP', 'Esso'];

function Harness({ onChange = vi.fn(), initial = '' }: { onChange?: (v: string) => void; initial?: string }) {
  const [value, setValue] = useState(initial);
  return (
    <SuggestInput
      id="f"
      value={value}
      suggestions={NAMES}
      onChange={(v) => {
        setValue(v);
        onChange(v);
      }}
    />
  );
}

describe('SuggestInput', () => {
  it('al focus con campo vuoto mostra le prime 6 voci', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('combobox'));

    const options = screen.getAllByRole('option');
    expect(options.map((o) => o.textContent)).toEqual(NAMES.slice(0, 6));
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-expanded', 'true');
  });

  it('filtra senza maiuscole né accenti', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.type(screen.getByRole('combobox'), 'caffe');

    expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(['Caffè Rossi']);
  });

  it('esclude la voce identica al testo', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.type(screen.getByRole('combobox'), 'shell');

    expect(screen.queryByRole('option')).not.toBeInTheDocument();
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-expanded', 'false');
  });

  it('frecce e Invio scelgono la voce', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Harness onChange={onChange} />);

    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{ArrowDown}');
    const first = screen.getAllByRole('option')[0] as HTMLElement;
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-activedescendant', first.id);
    expect(first).toHaveAttribute('aria-selected', 'true');
    await user.keyboard('{Enter}');

    expect(onChange).toHaveBeenLastCalledWith('Eni A1');
    expect(screen.getByRole('combobox')).toHaveValue('Eni A1');
    expect(screen.queryByRole('option')).not.toBeInTheDocument();
  });

  it('freccia su dal campo parte dall\'ultima voce', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Harness onChange={onChange} />);

    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{ArrowUp}{Enter}');

    expect(onChange).toHaveBeenLastCalledWith('IP');
  });

  it('Esc chiude la lista', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('combobox'));
    expect(screen.getAllByRole('option').length).toBeGreaterThan(0);
    await user.keyboard('{Escape}');

    expect(screen.queryByRole('option')).not.toBeInTheDocument();
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-expanded', 'false');
  });

  it('il tap su una voce la sceglie e il campo tiene il focus', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Harness onChange={onChange} />);

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: 'Q8 Via Roma' }));

    expect(onChange).toHaveBeenLastCalledWith('Q8 Via Roma');
    expect(screen.getByRole('combobox')).toHaveFocus();
  });

  it('collega la lista al campo con aria-controls', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('combobox'));

    const listbox = screen.getByRole('listbox');
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-controls', listbox.id);
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-autocomplete', 'list');
  });
});
