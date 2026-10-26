import { Suspense, useRef } from 'react';
import { Outlet } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { BottomNav } from './BottomNav';
import { PullToRefresh } from './PullToRefresh';
import { RouteFallback } from './RouteFallback';

/**
 * AppLayout — layout authenticated:
 * - mobile: come un'app iOS, niente barra fissa in alto (ogni pagina ha la sua navigation bar con titolo grande,
 *   PageHeader) + content scrollabile + tab bar fissa in basso; il content parte sotto la status bar/notch
 * - desktop (md+): Sidebar a sinistra + content
 *
 * Le pagine sono in lazy: il fallback occupa solo l'area contenuto, la shell resta a vista.
 * Radice `fixed inset-0`: segue il viewport reale anche in PWA iOS, dove 100dvh può restare più corto dello schermo.
 * Padding bottom contenuto = `pb-nav-clearance` (altezza della BottomNav, inset iOS compreso).
 * `<main>` è il contenitore che scrolla (non window): è anche quello del pull-to-refresh della PWA installata.
 */
export function AppLayout() {
  const scrollRef = useRef<HTMLElement>(null);
  return (
    <div className="fixed inset-0 flex flex-col overflow-hidden md:flex-row">
      <Sidebar />
      <main
        ref={scrollRef}
        // overflow-x-hidden: overflow-y-auto da solo renderebbe scorrevole anche l'asse x, e su iOS un elemento
        // appena più largo dello schermo farebbe spostare la pagina di lato mostrando una fascia vuota.
        className="relative flex-1 overflow-y-auto overflow-x-hidden overscroll-contain px-4 pb-nav-clearance pt-safe-content md:px-8 md:pb-8 md:pt-8"
      >
        <PullToRefresh scrollRef={scrollRef}>
          {/* Da md il contenuto ha una larghezza di lettura (come le app iPad/Mac), non tutto lo schermo. */}
          <div className="mx-auto w-full md:max-w-3xl xl:max-w-5xl">
            <Suspense fallback={<RouteFallback />}>
              <Outlet />
            </Suspense>
          </div>
        </PullToRefresh>
      </main>
      <BottomNav />
    </div>
  );
}
