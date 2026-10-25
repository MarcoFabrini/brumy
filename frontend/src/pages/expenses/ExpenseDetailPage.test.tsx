import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import i18n from '@/i18n';
import { authFetch } from '@/api/client';
import type { ExpenseResponse } from '@/api/types/expense';
import { ExpenseDetailPage } from './ExpenseDetailPage';

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/client')>()),
  authFetch: vi.fn(),
}));
const mockedAuthFetch = vi.mocked(authFetch);

const response = (overrides: Partial<ExpenseResponse>): ExpenseResponse => ({
  id: 3,
  vehicle: { id: 7, name: 'Golf', brand: 'VW', model: 'Golf' },
  occurredAt: '2026-01-15T00:00:00+00:00',
  section: 'other',
  category: { id: 3, name: 'Abbonamento' },
  insurer: null,
  description: 'Telepass',
  amount: '50.00',
  recurring: false,
  recurringPeriod: null,
  recurringUntil: null,
  notes: null,
  ...overrides,
});

function renderPage(expense: ExpenseResponse) {
  mockedAuthFetch.mockImplementation(async (path: string) => {
    if (path === '/api/expenses/3') return expense;
    if (path === '/api/vehicles/7') {
      return { id: 7, permissions: { canEdit: false, canDelete: false, canShare: false } };
    }
    return [];
  });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/expenses/3']}>
        <Routes>
          <Route path="/expenses/:id" element={<ExpenseDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ExpenseDetailPage — spese ricorrenti', () => {
  beforeEach(async () => {
    mockedAuthFetch.mockReset();
    await i18n.changeLanguage('it');
  });

  it('mostra periodicità, data di fine e che la spesa conta a ogni addebito', async () => {
    renderPage(response({ recurring: true, recurringPeriod: 'monthly', recurringUntil: '2026-06-15T00:00:00+00:00' }));

    expect(await screen.findByText('Mensile')).toBeInTheDocument();
    // Riga "Fino al" (dt) con la data (dd).
    expect(screen.getByText('Fino al', { selector: 'dt' }).nextElementSibling).toHaveTextContent('15 giu 2026');
    expect(screen.getByText(/conteggiata a ogni addebito già avvenuto/)).toBeInTheDocument();
  });

  it('una ricorrente in corso non mostra la data di fine', async () => {
    renderPage(response({ recurring: true, recurringPeriod: 'yearly' }));

    expect(await screen.findByText('Annuale')).toBeInTheDocument();
    expect(screen.queryByText('Fino al', { selector: 'dt' })).not.toBeInTheDocument();
    expect(screen.getByText(/conteggiata a ogni addebito già avvenuto/)).toBeInTheDocument();
  });

  it('una spesa singola non mostra nulla della ricorrenza', async () => {
    renderPage(response({}));

    expect(await screen.findByText('Telepass')).toBeInTheDocument();
    expect(screen.queryByText(/conteggiata a ogni addebito/)).not.toBeInTheDocument();
    expect(screen.queryByText('Fino al', { selector: 'dt' })).not.toBeInTheDocument();
  });
});
