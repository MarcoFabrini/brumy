import { Suspense, type ReactNode } from 'react';
import {
  createBrowserRouter,
  Navigate,
  Outlet,
  useLocation,
  useParams,
  useSearchParams,
  type RouteObject,
} from 'react-router-dom';
import { safeNextPath } from '@/lib/safeNext';
import { LoginPage } from './pages/auth/LoginPage';
import { DashboardPage } from './pages/DashboardPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { AppLayout, RequireRegistrationOpen, RouteFallback } from './components/layout';
import { useAuthStore } from './stores/useAuthStore';
import { lazyNamed } from './lib/lazyNamed';

// Login, shell, guard, dashboard e 404 sono nel bundle iniziale; il resto si scarica alla prima
// visita (un chunk per gruppo: i barrel di pagine finiscono insieme). Il cambio rotta aspetta il
// chunk senza svuotare la pagina corrente (transition di React Router); al primo ingresso e nelle
// rotte pubbliche compare il fallback di Suspense.
const RegisterPage = lazyNamed(() => import('./pages/auth/RegisterPage'), 'RegisterPage');
const ForgotPasswordPage = lazyNamed(() => import('./pages/auth/ForgotPasswordPage'), 'ForgotPasswordPage');
const ResetPasswordPage = lazyNamed(() => import('./pages/auth/ResetPasswordPage'), 'ResetPasswordPage');
const VerifyEmailPage = lazyNamed(() => import('./pages/auth/VerifyEmailPage'), 'VerifyEmailPage');
const AcceptInvitePage = lazyNamed(() => import('./pages/auth/AcceptInvitePage'), 'AcceptInvitePage');
const SettingsPage = lazyNamed(() => import('./pages/SettingsPage'), 'SettingsPage');
const UserValuesPage = lazyNamed(() => import('./pages/settings'), 'UserValuesPage');
const UserValueListPage = lazyNamed(() => import('./pages/settings'), 'UserValueListPage');
const VehicleListPage = lazyNamed(() => import('./pages/vehicles'), 'VehicleListPage');
const VehicleNewPage = lazyNamed(() => import('./pages/vehicles'), 'VehicleNewPage');
const VehicleDetailPage = lazyNamed(() => import('./pages/vehicles'), 'VehicleDetailPage');
const VehicleEditPage = lazyNamed(() => import('./pages/vehicles'), 'VehicleEditPage');
const VehicleSharingPage = lazyNamed(() => import('./pages/vehicles'), 'VehicleSharingPage');
const MaintenanceListPage = lazyNamed(() => import('./pages/maintenance'), 'MaintenanceListPage');
const ComponentListPage = lazyNamed(() => import('./pages/components'), 'ComponentListPage');
const ComponentNewPage = lazyNamed(() => import('./pages/components'), 'ComponentNewPage');
const ComponentDetailPage = lazyNamed(() => import('./pages/components'), 'ComponentDetailPage');
const ComponentEditPage = lazyNamed(() => import('./pages/components'), 'ComponentEditPage');
const MaintenanceNewPage = lazyNamed(() => import('./pages/maintenance'), 'MaintenanceNewPage');
const MaintenanceDetailPage = lazyNamed(() => import('./pages/maintenance'), 'MaintenanceDetailPage');
const MaintenanceEditPage = lazyNamed(() => import('./pages/maintenance'), 'MaintenanceEditPage');
const RefuelingListPage = lazyNamed(() => import('./pages/refueling'), 'RefuelingListPage');
const RefuelingNewPage = lazyNamed(() => import('./pages/refueling'), 'RefuelingNewPage');
const RefuelingDetailPage = lazyNamed(() => import('./pages/refueling'), 'RefuelingDetailPage');
const RefuelingEditPage = lazyNamed(() => import('./pages/refueling'), 'RefuelingEditPage');
const ExpenseListPage = lazyNamed(() => import('./pages/expenses'), 'ExpenseListPage');
const ExpenseNewPage = lazyNamed(() => import('./pages/expenses'), 'ExpenseNewPage');
const ExpenseDetailPage = lazyNamed(() => import('./pages/expenses'), 'ExpenseDetailPage');
const ExpenseEditPage = lazyNamed(() => import('./pages/expenses'), 'ExpenseEditPage');
const ReminderListPage = lazyNamed(() => import('./pages/reminders'), 'ReminderListPage');
const ReminderNewPage = lazyNamed(() => import('./pages/reminders'), 'ReminderNewPage');
const ReminderDetailPage = lazyNamed(() => import('./pages/reminders'), 'ReminderDetailPage');
const ReminderEditPage = lazyNamed(() => import('./pages/reminders'), 'ReminderEditPage');

/** Route guard: richiede sessione autenticata, altrimenti redirect login. */
function RequireAuth() {
  const status = useAuthStore((s) => s.status);
  const location = useLocation();
  const { id } = useParams();
  if (status !== 'authenticated') {
    // Ricorda dove voleva andare (deep link, sessione scaduta): il login riporta lì.
    const here = location.pathname + location.search;
    const to = here === '/' ? '/login' : `/login?next=${encodeURIComponent(here)}`;
    return <Navigate to={to} replace />;
  }
  // `/expenses/abc` o `/vehicles/0/edit`: la query resterebbe disabilitata e la pagina bianca.
  if (id !== undefined && !/^[1-9]\d*$/.test(id)) return <NotFoundPage />;
  return <Outlet />;
}

/** Route guard inverso: se già autenticato, redirect home (login/register). */
function RedirectIfAuthed({ children }: { children: ReactNode }) {
  const status = useAuthStore((s) => s.status);
  const [params] = useSearchParams();
  // Appena autenticato, il login può essere in corso con un `next`: rispettarlo invece di andare sempre a "/".
  if (status === 'authenticated') return <Navigate to={safeNextPath(params.get('next'))} replace />;
  return <>{children}</>;
}

/** Rotte pubbliche fuori dalla shell: il fallback occupa la pagina intera. */
function PublicRoutes() {
  return (
    <Suspense fallback={<RouteFallback fullScreen />}>
      <Outlet />
    </Suspense>
  );
}

/** Definizione delle rotte, esportata per poterla montare nei test con `createMemoryRouter`. */
export const routes: RouteObject[] = [
  {
    element: <PublicRoutes />,
    children: [
      {
        path: '/login',
        element: (
          <RedirectIfAuthed>
            <LoginPage />
          </RedirectIfAuthed>
        ),
      },
      {
        path: '/register',
        element: (
          <RedirectIfAuthed>
            <RequireRegistrationOpen>
              <RegisterPage />
            </RequireRegistrationOpen>
          </RedirectIfAuthed>
        ),
      },
      {
        path: '/forgot-password',
        element: (
          <RedirectIfAuthed>
            <ForgotPasswordPage />
          </RedirectIfAuthed>
        ),
      },
      {
        path: '/reset-password',
        element: (
          <RedirectIfAuthed>
            <ResetPasswordPage />
          </RedirectIfAuthed>
        ),
      },
      // Pubbliche e accessibili anche da loggati: il token arriva dall'email.
      { path: '/verify-email', element: <VerifyEmailPage /> },
      { path: '/accept-invite', element: <AcceptInvitePage /> },
    ],
  },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <DashboardPage /> },
          { path: '/vehicles', element: <VehicleListPage /> },
          { path: '/vehicles/new', element: <VehicleNewPage /> },
          { path: '/vehicles/:id', element: <VehicleDetailPage /> },
          { path: '/vehicles/:id/edit', element: <VehicleEditPage /> },
          { path: '/vehicles/:id/sharing', element: <VehicleSharingPage /> },
          { path: '/maintenance', element: <MaintenanceListPage /> },
          { path: '/maintenance/new', element: <MaintenanceNewPage /> },
          { path: '/maintenance/:id', element: <MaintenanceDetailPage /> },
          { path: '/maintenance/:id/edit', element: <MaintenanceEditPage /> },
          { path: '/components', element: <ComponentListPage /> },
          { path: '/components/new', element: <ComponentNewPage /> },
          { path: '/components/:id', element: <ComponentDetailPage /> },
          { path: '/components/:id/edit', element: <ComponentEditPage /> },
          { path: '/refueling', element: <RefuelingListPage /> },
          { path: '/refueling/new', element: <RefuelingNewPage /> },
          { path: '/refueling/:id', element: <RefuelingDetailPage /> },
          { path: '/refueling/:id/edit', element: <RefuelingEditPage /> },
          { path: '/expenses', element: <ExpenseListPage /> },
          { path: '/expenses/new', element: <ExpenseNewPage /> },
          { path: '/expenses/:id', element: <ExpenseDetailPage /> },
          { path: '/expenses/:id/edit', element: <ExpenseEditPage /> },
          { path: '/reminders', element: <ReminderListPage /> },
          { path: '/reminders/new', element: <ReminderNewPage /> },
          { path: '/reminders/:id', element: <ReminderDetailPage /> },
          { path: '/reminders/:id/edit', element: <ReminderEditPage /> },
          { path: '/settings', element: <SettingsPage /> },
          { path: '/settings/values', element: <UserValuesPage /> },
          { path: '/settings/values/:kind', element: <UserValueListPage /> },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
];

export const router = createBrowserRouter(routes);
