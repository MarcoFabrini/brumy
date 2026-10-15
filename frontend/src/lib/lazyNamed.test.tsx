import { Suspense } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import i18n from '@/i18n';
import { mockLocationReload } from '@/test/mockReload';
import { clearChunkReloadFlag } from './chunkRecovery';
import { lazyNamed } from './lazyNamed';

const KEY = 'brumy:chunk-reload-at';

function Hello() {
  return <p>Ciao dal chunk</p>;
}

const chunkError = () => new TypeError('Failed to fetch dynamically imported module: /assets/Hello-old.js');

function renderLazy(load: () => Promise<{ Hello: typeof Hello }>) {
  const Lazy = lazyNamed(load, 'Hello');
  return render(
    <ErrorBoundary>
      <Suspense fallback={<p>Carico…</p>}>
        <Lazy />
      </Suspense>
    </ErrorBoundary>,
  );
}

describe('lazyNamed', () => {
  let reload: ReturnType<typeof mockLocationReload>;

  beforeEach(async () => {
    clearChunkReloadFlag();
    reload = mockLocationReload();
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    await i18n.changeLanguage('it');
  });

  it('mostra il fallback e poi il componente con nome', async () => {
    renderLazy(async () => ({ Hello }));

    expect(screen.getByText('Carico…')).toBeInTheDocument();
    expect(await screen.findByText('Ciao dal chunk')).toBeInTheDocument();
    expect(reload).not.toHaveBeenCalled();
  });

  it('chunk sparito: ricarica una volta e tiene il fallback (niente schermata di errore)', async () => {
    renderLazy(() => Promise.reject(chunkError()));

    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
    expect(screen.getByText('Carico…')).toBeInTheDocument();
    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });

  it('import risolto con undefined (vite:preloadError gestito) non rompe: aspetta il reload', async () => {
    renderLazy(vi.fn().mockResolvedValue(undefined));

    // dà tempo al microtask dell'import senza che compaia l'errore
    await Promise.resolve();
    expect(screen.getByText('Carico…')).toBeInTheDocument();
    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });

  it("se ha già ricaricato, mostra l'ErrorBoundary con il pulsante per riprovare", async () => {
    window.sessionStorage.setItem(KEY, String(Date.now()));
    renderLazy(() => Promise.reject(chunkError()));

    expect(await screen.findByRole('heading', { name: 'Qualcosa è andato storto' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Ricarica/ })).toBeInTheDocument();
    expect(reload).not.toHaveBeenCalled();
  });

  it('un errore qualsiasi dell\'import non ricarica: va all\'ErrorBoundary', async () => {
    renderLazy(() => Promise.reject(new Error('syntax error nel modulo')));

    expect(await screen.findByRole('heading', { name: 'Qualcosa è andato storto' })).toBeInTheDocument();
    expect(reload).not.toHaveBeenCalled();
  });

  it('un import riuscito riarma il recupero per un deploy successivo', async () => {
    window.sessionStorage.setItem(KEY, String(Date.now()));
    renderLazy(async () => ({ Hello }));

    await screen.findByText('Ciao dal chunk');
    expect(window.sessionStorage.getItem(KEY)).toBeNull();
  });
});
