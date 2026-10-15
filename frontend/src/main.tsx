import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router-dom';
import './index.css';
import './i18n';
import './schemas/errorMap';
import { router } from './router';
import { Toaster } from '@/components/ui';
import { AuthGate, UpdatePrompt } from '@/components/layout';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { clearQueryCacheOnSessionChange } from '@/lib/sessionCache';
import { shouldRetryQuery } from '@/lib/queryRetry';
import { installChunkErrorRecovery } from '@/lib/chunkRecovery';
import { startServiceWorker } from '@/lib/pwaUpdate';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      retry: shouldRetryQuery,
    },
  },
});

// Cache di un altro utente o di un'altra organizzazione mai visibile: si svuota quando la sessione
// finisce o cambia utente, e quando l'org attiva del token cambia (vedi lib/sessionCache).
clearQueryCacheOnSessionChange(queryClient);

// Chunk spariti dopo un deploy (scheda rimasta aperta): un reload, poi ci pensa l'ErrorBoundary.
installChunkErrorRecovery();

// Service worker (build di produzione) e controllo periodico delle nuove versioni, vedi lib/pwaUpdate.
startServiceWorker();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <AuthGate>
          <RouterProvider router={router} />
        </AuthGate>
        <Toaster />
        <UpdatePrompt />
      </QueryClientProvider>
    </ErrorBoundary>
  </StrictMode>,
);
