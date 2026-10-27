import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RequireRegistrationOpen } from './RequireRegistrationOpen';
import { authFetch } from '@/api/client';

vi.mock('@/api/client', () => ({ authFetch: vi.fn() }));
const mockedAuthFetch = vi.mocked(authFetch);

function renderAt(path: string, ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/register" element={ui} />
          <Route path="/login" element={<div>PAGINA LOGIN</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('RequireRegistrationOpen', () => {
  beforeEach(() => {
    mockedAuthFetch.mockReset();
  });

  it('istanza vuota (registrazione aperta): mostra la pagina di registrazione', async () => {
    mockedAuthFetch.mockResolvedValue({ open: true });
    renderAt('/register', <RequireRegistrationOpen><div>FORM REGISTRAZIONE</div></RequireRegistrationOpen>);

    expect(await screen.findByText('FORM REGISTRAZIONE')).toBeInTheDocument();
    expect(mockedAuthFetch).toHaveBeenCalledWith('/api/auth/registration', { anonymous: true });
  });

  it('istanza con utenti (registrazione chiusa): la pagina sparisce, redirect al login', async () => {
    mockedAuthFetch.mockResolvedValue({ open: false });
    renderAt('/register', <RequireRegistrationOpen><div>FORM REGISTRAZIONE</div></RequireRegistrationOpen>);

    expect(await screen.findByText('PAGINA LOGIN')).toBeInTheDocument();
    expect(screen.queryByText('FORM REGISTRAZIONE')).not.toBeInTheDocument();
  });

  it('stato non verificabile (errore di rete): nel dubbio resta chiusa', async () => {
    mockedAuthFetch.mockRejectedValue(new Error('rete'));
    renderAt('/register', <RequireRegistrationOpen><div>FORM REGISTRAZIONE</div></RequireRegistrationOpen>);

    expect(await screen.findByText('PAGINA LOGIN')).toBeInTheDocument();
    expect(screen.queryByText('FORM REGISTRAZIONE')).not.toBeInTheDocument();
  });

  it('durante il controllo non mostra il form (niente flash della pagina)', () => {
    mockedAuthFetch.mockReturnValue(new Promise(() => {}));
    renderAt('/register', <RequireRegistrationOpen><div>FORM REGISTRAZIONE</div></RequireRegistrationOpen>);

    expect(screen.queryByText('FORM REGISTRAZIONE')).not.toBeInTheDocument();
    expect(screen.queryByText('PAGINA LOGIN')).not.toBeInTheDocument();
  });
});
