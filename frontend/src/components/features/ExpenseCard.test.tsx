import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import i18n from '@/i18n';
import type { Expense } from '@/api/types/expense';
import { ExpenseCard } from './ExpenseCard';

const expense = (overrides: Partial<Expense>): Expense => ({
  id: 1,
  vehicleId: 7,
  occurredAt: '2026-01-15',
  section: 'other',
  category: 'Abbonamento',
  insurer: null,
  description: 'Telepass',
  amount: '50.00',
  recurring: false,
  recurringPeriod: null,
  recurringUntil: null,
  notes: null,
  ...overrides,
});

function renderCard(e: Expense) {
  return render(
    <MemoryRouter>
      <ExpenseCard expense={e} />
    </MemoryRouter>,
  );
}

describe('ExpenseCard', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  it('una spesa ricorrente mostra periodicità e data di fine', () => {
    renderCard(expense({ recurring: true, recurringPeriod: 'monthly', recurringUntil: '2026-06-15' }));

    expect(screen.getByText('Mensile')).toBeInTheDocument();
    expect(screen.getByText('fino al 15 giu 2026')).toBeInTheDocument();
  });

  it('una ricorrente in corso mostra la periodicità senza data di fine', () => {
    renderCard(expense({ recurring: true, recurringPeriod: 'yearly' }));

    expect(screen.getByText('Annuale')).toBeInTheDocument();
    expect(screen.queryByText(/fino al/)).not.toBeInTheDocument();
  });

  it('una spesa singola non mostra né periodicità né fine', () => {
    renderCard(expense({}));

    expect(screen.queryByText('Mensile')).not.toBeInTheDocument();
    expect(screen.queryByText(/fino al/)).not.toBeInTheDocument();
  });
});
