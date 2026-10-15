# Components — Convenzioni Brumy

Tutta la UI è composta da componenti **piccoli, riusabili, single-responsibility**.
Mai duplicare markup Tailwind tra pagine: estrai sempre.

## Struttura cartelle (atomic-ish design)

```
src/components/
├── ui/           # Atoms + molecules — primitivi senza logica business
│   ├── Alert.tsx, Badge.tsx, Button.tsx
│   ├── Card.tsx (+ Header/Title/Description/Content/Footer)
│   ├── Dialog.tsx (+ Trigger/Content/Header/Title/Description/Footer/Close)
│   ├── DropdownMenu.tsx (+ Trigger/Content/Item/CheckboxItem/RadioItem/Separator)
│   ├── FormField.tsx, Heading.tsx, Input.tsx, Label.tsx
│   ├── Select.tsx (+ Trigger/Value/Content/Item/Group/Label)
│   ├── Separator.tsx, Skeleton.tsx, Spinner.tsx
│   ├── Sheet.tsx (+ Trigger/Content/Header/Title/Description/Footer/Close)
│   ├── Tabs.tsx (+ List/Trigger/Content)
│   ├── Text.tsx
│   ├── Toast.tsx (+ Provider/Viewport/Title/Description/Action/Close)
│   ├── Toaster.tsx (mounted in main.tsx)
│   └── index.ts (barrel)
│
├── layout/       # Structural — layout + navigation
│   ├── AppLayout.tsx     (auth-protected)
│   ├── AuthLayout.tsx    (login/register)
│   ├── Sidebar.tsx       (desktop nav)
│   ├── Topbar.tsx        (mobile topbar)
│   ├── BottomNav.tsx     (mobile tab bar)
│   ├── NavItem.tsx       (riusato Sidebar + BottomNav)
│   ├── PageHeader.tsx    (title + description + action)
│   ├── navigation.ts     (NAVIGATION array — single source of truth)
│   └── index.ts
│
└── features/     # Domain — UI + minima logica di presentazione
    ├── StatCard.tsx
    ├── EmptyState.tsx
    ├── AuthBrand.tsx
    └── index.ts
```

## Regole d'oro

### 1. Atoms (`ui/`) NON contengono logica business
- Solo props + Tailwind + `forwardRef`
- Niente `useQuery`, `useTranslation`, `useNavigate`
- Variant tramite [class-variance-authority](https://cva.style/)
- Forniscono `ref` con `forwardRef` per integrazione RHF

```tsx
// ✅ OK — atom puro
export const Button = forwardRef<HTMLButtonElement, ButtonProps>((props, ref) => ...);

// ❌ NO — atom con logica business
export function VehicleButton() {
  const { data } = useVehicles();
  return <Button>...</Button>;
}
```

### 2. Variants via CVA, non `if` annidati

```tsx
// ✅ OK
const variants = cva('base', { variants: { intent: { ... } } });
<Button variant="destructive">Delete</Button>

// ❌ NO
<Button className={isDanger ? 'bg-red-500' : 'bg-blue-500'}>...</Button>
```

### 3. Composizione > Props enormi

```tsx
// ✅ OK — composable
<Card>
  <CardHeader>
    <CardTitle>Tagliando</CardTitle>
    <CardDescription>50.000 km</CardDescription>
  </CardHeader>
  <CardContent>Olio + filtri</CardContent>
</Card>

// ❌ NO — props monolitiche
<Card title="Tagliando" subtitle="50.000 km" body="Olio + filtri" footer={...} />
```

### 4. Una card = un componente

Tutte le card della stessa **categoria visiva** usano lo stesso componente.
Categorie diverse (es. VehicleCard vs MaintenanceCard) sono **componenti
distinti** in `features/`, ognuno compone `Card` + `CardHeader` + ecc.

### 5. Bottoni: SEMPRE `<Button>` di `ui/`, mai `<button>` raw

Eccezione: `<button>` interno a un atom (es. dentro un picker custom).

### 6. Heading + Text al posto di `<h1>` / `<p>` raw

Garantisce typography consistente. Per override semantico usa `as`:

```tsx
<Heading level={2} as="h1">Page title displayed as h2</Heading>
```

### 7. Forms: FormField + Input + Button

```tsx
<FormField label={t('vehicle.name')} error={errors.name?.message} required>
  {(id) => <Input id={id} {...register('name')} />}
</FormField>
<Button type="submit" fullWidth disabled={isPending}>
  {isPending ? <Spinner size="sm" /> : t('actions.save')}
</Button>
```

### 8. Loading + Errori: usa atom dedicati

- **Loading inline**: `<Spinner size="sm" />`
- **Loading pagina full**: `<Spinner size="lg" />` centrato
- **Errori user-facing**: `<Alert variant="error">` con messaggio i18n
- **Errori toast**: vedi `useToast` hook (TODO)

### 9. Hooks single-purpose

```
src/hooks/
├── useLogin.ts              # 1 mutation login
├── useRegister.ts           # 1 mutation register
├── useVehicles.ts           # 1 query lista
├── useVehicle.ts            # 1 query detail
├── useCreateVehicle.ts      # 1 mutation create
├── useUpdateVehicle.ts      # 1 mutation update
├── useDeleteVehicle.ts      # 1 mutation delete
├── useApiErrorMessage.ts    # helper i18n errors
└── useDebounce.ts           # utility
```

Niente "useVehiclesAndStats" che fa 3 cose. Un hook = una responsabilità.

### 10. Pagine sono ORCHESTRATORI, non implementatori

Pagina ideale: 40-100 LOC.
- Hook per dati
- Composizione componenti
- Eventi UI handler
- Niente Tailwind massive class strings
- Niente logica API/business

```tsx
// ✅ OK — pagina come orchestratore
export function VehicleListPage() {
  const { t } = useTranslation();
  const { data, isLoading } = useVehicles();

  if (isLoading) return <Spinner size="lg" />;
  if (!data?.length) return <EmptyState title="..." />;

  return (
    <div className="space-y-4">
      <PageHeader title={t('nav.vehicles')} action={<Button asChild>...</Button>} />
      <VehicleList vehicles={data} />
    </div>
  );
}
```

### 11. Naming

- File: PascalCase (`Button.tsx`, `VehicleCard.tsx`)
- Hook: `useXxx` camelCase
- Type/Interface props: `XxxProps`
- Variant CVA export: `xxxVariants`

### 12. Barrel export

Ogni cartella espone `index.ts` con re-export pubblici. Import puliti:

```tsx
import { Button, Card, FormField } from '@/components/ui';
import { PageHeader, AppLayout } from '@/components/layout';
import { StatCard, EmptyState } from '@/components/features';
```

### 13. Mobile-first SEMPRE

- Classi base = mobile (< 768px)
- Breakpoint `md:` overridano per desktop
- Touch target minimo `min-h-touch min-w-touch` (44px)
- Safe area iOS via utility `pb-safe pt-safe`

### 14. Accessibility built-in

- Atom hanno role/aria default appropriati
- Form sempre con `<label htmlFor>` (FormField gestisce)
- Bottoni `<button type="...">` esplicito (default submit dentro form!)
- `aria-current="page"` su nav active (NavItem)

### 15. Test (in arrivo)

Vitest + Testing Library. Test su:
- Atom: rendering varianti, ref forwarding, disabled state
- Hook: mock authFetch, asserzioni su store updates
- Page: integration con MSW

---

## Anti-pattern da rifiutare in code review

- ❌ Tailwind class string duplicate in 3+ punti (estrai componente)
- ❌ `<input className="..." />` raw in una pagina (usa `<Input>`)
- ❌ `<h1 className="text-2xl font-bold">` (usa `<Heading level={1}>`)
- ❌ `<div className="rounded-lg border bg-card ...">` (usa `<Card>`)
- ❌ Pagina > 200 LOC (estrai sub-component o hook)
- ❌ Hook che fa 2+ network call (split)
- ❌ Logic business dentro componente UI (sposta in hook)
- ❌ Stato server in `useState` invece di TanStack Query
- ❌ Hardcoded stringhe UI (usa i18n key)
