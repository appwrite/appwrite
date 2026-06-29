# Development Guidelines

**Stack**: TanStack Start, Tailwind CSS, ShadCN UI. Prefer existing stack over new libraries.

---

## Core Principles

### 1. SDK Usage

- **Client-side only**: Use `src/lib/appwrite/sdk.ts` for all Appwrite operations
- **Console SDK**: `sdk.forConsole.*` for projects, account, organizations
- **Project SDK**: `sdk.forProject(projectId).*` for databases, functions, storage, etc.
- **Always use Models types**: Import `Models.*` from `@appwrite.io/console` - never guess response structure
- **Service references**: Check `node_modules/@appwrite.io/console/src/services/*.ts` for method signatures

**Quick Reference:**

```typescript
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'

// Console operations
const projects = await sdk.forConsole.projects.list()

// Project operations
const projectSdk = sdk.forProject(projectId)
const databases = await projectSdk.tablesDB.list() // Returns Models.DatabaseList
```

### 2. File Organization

- **Route-aligned structure**: Components match route paths
- **Global components**: `components/global/` (layout, auth, shared, providers)
- **Page components**: `components/pages/` organized by route
- **Naming** (see below for full page naming conventions):
  - **List and detail pages**: Always `View.tsx`; export the page component as `View`. Detail pages live in `$resourceId/View.tsx` (e.g. `storage/$bucketId/View.tsx`), not in separate `*Detail.tsx` files.
  - **Dialogs**: `CreateResource.tsx` or `UploadResource.tsx` (no "Dialog" suffix); export the component with the same name as the file (e.g. `CreateBucket`, `UploadFile`).
  - **Settings/tabs**: `Settings.tsx`, `Security.tsx`, or `ResourceSettings.tsx` (e.g. `BucketSettings.tsx`, `TopicSettings.tsx`); export as `View` or a descriptive name.
- **No namespace repetition**: Never repeat a namespace in the name itself for paths, variable names, or any names. For example, use `/sites/create` not `/sites/create-site`, since we're already in the `sites` namespace.

**Import patterns:**

```typescript
// Global
import { DateTooltip } from '@/components/global/shared/DateTooltip'

// Page components (View.tsx always exports View)
import { View } from '@/components/pages/projects/$projectId/storage/View'

// Sibling components (same feature)
import { BucketSettings } from '../BucketSettings'
```

### 3. React Query Patterns

- **Extract query functions**: Export async functions from hooks for reuse in route loaders
- **Prefetch at route level**: All crucial data must be prefetched to prevent layout shifts
- **Query keys**: Consistent structure `['resource', 'scope', id, ...params]`

**Pattern:**

```typescript
// Extract function
export async function fetchProjectUsers(
  projectId: string,
  page: number,
  limit: number,
) {
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.users.list([
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ])
  return { users: response.users || [], total: response.total || 0 }
}

// Hook uses function
export function useProjectUsers(
  projectId: string,
  page: number,
  limit: number,
) {
  return useQuery({
    queryKey: ['users', 'project', projectId, page, limit],
    queryFn: () => fetchProjectUsers(projectId, page, limit),
    enabled: !!projectId,
  })
}

// Route loader uses same function
loader: async ({ params, context }) => {
  await queryClient.prefetchQuery({
    queryKey: ['users', 'project', projectId, 0, 25],
    queryFn: () => fetchProjectUsers(projectId, 0, 25),
  })
}
```

**Delete mutations and list updates:**

List queries use `refetchOnMount: false` (for prefetch). After a delete, **use `refetchQueries`** (not just `invalidateQueries`) so the list cache is updated and the UI reflects the change without a full reload.

- **After delete**: Call `await queryClient.refetchQueries({ queryKey: [...] })` in the mutation's `onSuccess`.
- **When navigating after delete**: Await refetch before navigating so the list view has fresh data when it mounts.
- **Bulk delete**: Same pattern - refetch the list query so the current view or the next view shows the updated list.

```typescript
// Delete mutation (single or bulk)
const deleteMutation = useMutation({
  mutationFn: (id: string) => projectSdk.resource.delete({ id }),
  onSuccess: async () => {
    await queryClient.refetchQueries({
      queryKey: ['resources', 'project', projectId],
    })
    toast.success('Deleted')
    navigate({ to: '/projects/$projectId/resources', params: { projectId } })
  },
})
```

### 4. Authentication

Use `RequireAuth` component wrapper:

```typescript
import { RequireAuth } from '@/components/global/auth/RequireAuth'

// Simple wrapper
<RequireAuth><MyContent /></RequireAuth>

// With auth data
<RequireAuth>
  {({ account }) => <div>Welcome {account.name}</div>}
</RequireAuth>

// Hook
const { account, isAuthenticated } = useAuth()
```

---

## UI Patterns

### Modal/Dialog Structure

```tsx
<DialogContent className="sm:max-w-md p-0">
  <DialogHeader className="px-6 pt-6 pb-4 text-left">
    <DialogTitle>Title in sentence case</DialogTitle>
    <DialogDescription className="text-[13px] mt-2">
      Description above separator
    </DialogDescription>
  </DialogHeader>
  <div className="border-t border-border" />
  <div className="px-6 pb-4 pt-0">{/* Content */}</div>
  <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
    <Button variant="outline">Cancel</Button>
    <Button>Confirm</Button>
  </div>
</DialogContent>
```

**Rules**:

- **Title**: Use sentence case (only first letter capitalized)
- **Header spacing**: Include `pb-4` for proper spacing before separator
- **CTA buttons**: No icons in primary action buttons
- **Exception**: No-content modals skip content section, go directly from header to footer

### Settings Card Structure

```tsx
<div className="rounded-xl border border-border bg-card/50 overflow-hidden">
  <div className="px-6 py-4">
    <h3 className="text-[15px] font-semibold text-foreground">Section Title</h3>
    <p className="text-[13px] text-muted-foreground mt-2">
      Optional description
    </p>
  </div>
  <div className="border-t border-border" />
  <div className="px-6 py-4">{/* Form fields, switches, etc. */}</div>
  {/* Footer only if has update button */}
  <div className="px-6 py-4 border-t border-border bg-muted/30">
    <Button size="sm" className="h-9 text-[13px]">
      Update
    </Button>
  </div>
</div>
```

**Rules**: Never use Card/CardHeader/CardContent components. Always include separators. Footer only for update buttons.

### Table Structure

All tables must use consistent styling matching the users table pattern for visual consistency across the application.

**Header Row Styling:**

```tsx
<TableHeader>
  <TableRow className="hover:bg-transparent border-b border-border">
    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
      Column Name
    </TableHead>
    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
      Another Column
    </TableHead>
    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px]" />
  </TableRow>
</TableHeader>
```

**Body Row Styling:**

```tsx
<TableBody>
  {items.map((item) => (
    <TableRow key={item.$id}>
      <TableCell className="px-4 py-3">{/* Content */}</TableCell>
      <TableCell className="px-4 py-3">{/* Content */}</TableCell>
      <TableCell className="px-4 py-3 text-right">{/* Actions */}</TableCell>
    </TableRow>
  ))}
</TableBody>
```

**Rules:**

- **Header Row**: Always use `hover:bg-transparent border-b border-border` on `TableRow` in `TableHeader`
- **TableHead**: Always include `px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider`
- **Column Names**: Use uppercase/capitalized text (e.g., "User ID", "Created At"). Never use "Actions" as a column title - leave the actions column header empty.
- **TableCell**: Always use `px-4 py-3` for consistent padding
- **Actions column**: Never title it "Actions" - use an empty `TableHead` (e.g. `<TableHead className="... text-right w-[100px]" />`).
- **Special Cases**:
  - First column with checkbox: Keep `w-[40px] px-4` on `TableHead`, add `py-3` to `TableCell`
  - Columns with `pl-6 sm:pl-8`: Add `py-3` to maintain vertical consistency
  - Right-aligned columns: Add `text-right` to both `TableHead` and `TableCell` if needed
  - Width constraints: Preserve `w-[...]`, `min-w-[...]`, `max-w-[...]` classes on `TableHead`

**Complete Example:**

```tsx
<Table>
  <TableHeader>
    <TableRow className="hover:bg-transparent border-b border-border">
      <TableHead className="w-[40px] px-4">
        <Checkbox checked={allSelected} onCheckedChange={toggleAll} />
      </TableHead>
      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
        Name
      </TableHead>
      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
        Status
      </TableHead>
      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
        Created
      </TableHead>
    </TableRow>
  </TableHeader>
  <TableBody>
    {items.map((item) => (
      <TableRow key={item.$id}>
        <TableCell className="px-4 py-3">
          <Checkbox
            checked={selectedItems.has(item.$id)}
            onCheckedChange={() => toggleItem(item.$id)}
          />
        </TableCell>
        <TableCell className="px-4 py-3">
          <span className="text-[13px] font-medium">{item.name}</span>
        </TableCell>
        <TableCell className="px-4 py-3">
          <Badge variant="success">{item.status}</Badge>
        </TableCell>
        <TableCell className="px-4 py-3 text-right">
          <DateTooltip date={item.$createdAt} />
        </TableCell>
      </TableRow>
    ))}
  </TableBody>
</Table>
```

**Reference Implementation:**

See `src/components/pages/projects/$projectId/auth/View.tsx` for the canonical users table implementation.

### Context menu and row actions menus

Context menus (right-click) and list row actions menus (⋯ `RowActionsMenuTrigger` + `DropdownMenu`) share the same action set, ordering, icons, and destructive styling rules.

**Standard ordering (top to bottom):**

- **Tabs** (list the resource tabs: `Overview`, `Deployments`, `Settings`, `Security`, etc.). No verbs here.
  Tabs must be first-level context-menu items (no nested "Tabs" submenu), followed by a separator.
- **Copy** (submenu with `Copy ID`, `Copy value` (only for row columns), `Copy link`, `Copy as JSON`). Include `Duplicate` here only where it makes sense (single resources; never for container resources like tables, buckets, databases, etc).
- **Links** (`Open in new tab`, `Open in new window`) for all context menus.
- **Delete** last. No special styling in the menu itself (see destructive styling below).

**Copy submenu must include (when applicable):**

- `Copy ID`
- `Copy name` (if resource has a name)
- `Copy link` (console route URL)
- `Copy as JSON` (full resource JSON from the API via `copyResourceAsJson`; fetch when a get endpoint exists)

**Menu item icons (required on all menus):**

Every menu item must have a leading Lucide icon with consistent spacing. Use the shared helpers from `@/components/global/shared/ContextMenuIcon`:

- **Context menus**: `<ContextMenuIcon icon={Pencil} />` before the label (or inline in the item).
- **Row actions dropdowns**: wrap the label in `<MenuItemContent icon={Pencil}>Update</MenuItemContent>`.
- **Submenu triggers**: `<MenuItemIcon icon={Copy} />` before the label (context menu or dropdown).

When a resource has both a context menu and a row ⋯ menu, **use the same icons** in both (e.g. `ApiKeyContextMenu` and `ApiKeysList`, `ProxyRuleContextMenu` and domain list dropdowns, `DeploymentListRowContextMenu` and deployment list dropdowns).

Common icon mapping:

| Action | Icon |
| ------ | ---- |
| Update | `Pencil` |
| Delete / Remove | `Trash2` |
| Copy (submenu or item) | `Copy` |
| Copy link | `Link2` |
| Copy as JSON | `FileJson` |
| Open in new tab | `ExternalLink` |
| Open in new window | `Square` |
| Settings | `Settings` |
| Logs | `FileText` or `ScrollText` |
| Retry / Redeploy | `RefreshCw` |
| DNS Records | `Globe` |
| Activate | `Play` |
| Cancel build | `XCircle` |
| Secret | `Lock` |
| Unmark secret | `Eye` |

**Destructive action styling:**

Delete and remove actions must **not** use red text in menus, inline links, or icon buttons. Never use `variant="destructive"`, `className="text-destructive"`, `hover:text-destructive`, or `text-red-*` on menu items or row-level delete/remove controls.

Red delete styling is allowed **only** in these three places:

1. **Dedicated delete cards** (danger zone sections: `bg-destructive/5`, `border-destructive/20`, or equivalent delete-card chrome). The trigger button inside the card may use `variant="destructive"`.
2. **Dialog confirm buttons** (`Button variant="destructive"` in the dialog footer after the user chose Delete from a menu).
3. **Bulk delete action bars** (fixed bottom bar with selection count and a red `Delete` button).

Do not put a red destructive button on a form footer or list row outside those contexts (e.g. use a default/outline button that opens a confirm dialog instead).

Icon-only remove controls (dismiss preset, remove tag, remove role chip, etc.) use neutral styling (`text-muted-foreground`, `hover:text-foreground`), not red hover.

**Other rules:**

- Use `Update` label (not `Update user`, `Update team`, etc.)
- Keep action sets aligned to actual capabilities (no “View activity” if the tab doesn’t exist)
- Include a confirm dialog for destructive actions

**Reference implementations:**

- Context menu: `src/components/pages/projects/$projectId/api-keys/_components/ApiKeyContextMenu.tsx`
- Row ⋯ menu with icons: `src/components/pages/projects/$projectId/settings/Webhooks.tsx`, `src/components/global/shared/VariablesSettingsCard.tsx`
- Delete card + dialog confirm: `src/components/pages/projects/$projectId/sites/settings/DangerZoneCard.tsx`
- Bulk delete bar: `src/components/pages/organizations/$orgId/domains/View.tsx`

### Long-running task progress

Use one unified progress panel per scope (e.g. on project routes: file uploads, CSV export, CSV import together). All task types share the same card style; there is no wrapper or group header around a type.

**Structure:**

- **Single panel**: One fixed area (e.g. bottom-right) that contains all progress items. On project routes use a single panel with `GlobalUploadProgress embedded`, `CsvImportBox`, and `CsvExportBox` (or equivalent) stacked with `gap-2`. Outside that scope (e.g. root), show only the relevant progress (e.g. uploads) without the project-specific items.
- **Flat list of cards**: Do not wrap one task type in its own box (no “CSV export” / “CSV import” header, no collapse, no group dismiss). Each task is one card in a flat list. Use `w-full max-w-sm space-y-2` for the list container; each item is a standalone card.
- **Card style** (same for uploads, CSV export, CSV import): `rounded-lg border border-border bg-background p-3`. Inside: icon (status-based) + label row, then `ProgressBarRow` from `@/components/global/shared/ProgressBarRow`, then optional action (e.g. Download, View details). Provide a per-card dismiss (X button) so users can remove individual items; no “dismiss all” in a group header.
- **Icons**: `Loader2` with `animate-spin` for in-progress, `CheckCircle2` (e.g. `text-green-600`) for completed, `AlertCircle` with `text-destructive` for failed.
- **Auto-actions on completion**: When a task completes (e.g. export ready to download), trigger the action (e.g. open download URL) only when the status **transitions** to completed (track previous status in a ref). Do not run the action when the task is already completed on initial load (avoids re-downloads on reload).

**Reference:** `UploadProgress`, `CsvExportBox`, `CsvImportBox` in `components/global/`; project layout panel in `src/routes/_public/projects.$projectId.tsx`.

### Service View Pattern

Standard structure for service pages (Storage, Functions, Databases, etc.):

**Structure:**

```tsx
<div className="flex h-full flex-col">
  <ServiceHeader
    title="Service Name"
    tabs={tabs}
    activeTab={activeTab}
    searchPlaceholder="Search..."
    searchValue={searchValue}
    onSearchChange={handleSearchChange}
    createLabel="Create resource"
    onCreate={handleCreate}
    fullWidthBorder
    rightContent={<ViewToggle />}
  />
  <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
    {/* List/Grid view with pagination */}
  </div>
  <CreateDialog open={createDialogOpen} onOpenChange={setCreateDialogOpen} />
</div>
```

**Required features**: Route-based tabs, search, pagination (1-indexed UI, 0-indexed API), list/grid toggle, bulk selection/delete, plan limit warnings, empty states, loading states.

**State management pattern:**

```typescript
const [searchValue, setSearchValue] = useState('')
const [pageSize, setPageSize] = useState(25)
const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
const [selectedItems, setSelectedItems] = useState<Set<string>>(new Set())

// Pagination: requested page (user intent) vs displayed page (what we show)
const [requestedPage, setRequestedPage] = useState(1) // 1-indexed for UI
const [displayedPage, setDisplayedPage] = useState(1)
```

**Pagination (no-flash):** Keep the current page visible until the next page's data has loaded. Use two page values and two queries:

- **requestedPage** – Page the user asked for (e.g. clicked "Next"); drives the fetch for the new page.
- **displayedPage** – Page whose data is actually shown; only update when that page's fetch has finished (`!isFetching && requestedPage !== displayedPage`).

Fetch the **requested** page (to get `isFetching` and trigger load) and the **displayed** page (to get the list and total to render). Use the **displayed** query's data and total for the list and for `<Pagination>` (e.g. `currentPage={displayedPage}`, `totalItems={displayedTotal ?? total}`). Only show full loading when there is no data to show (`displayedLoading && items.length === 0`). On page change, update only `requestedPage`; on search or page-size change, set both to 1.

Reference: `src/components/pages/projects/$projectId/storage/View.tsx`, `SiteLogs.tsx`, `Deployments.tsx`.

**Filters (table/list):** Use the global filters component (`FiltersPopover`) with URL-backed state (`query` search param). Do **not** show a loading state when filters (or page) change - keep showing the previous list until the new data is ready. Use `placeholderData: keepPreviousData` on the list query so React Query keeps the previous result while the new request is in flight. Use different empty-state copy when there are no items at all vs when filters return no results.

**How to add filters to a list:**

1. **Filter config** – In `src/lib/table-filters/filter-configs/` add a column config (e.g. `dns-records.ts`) and export from `@/lib/table-filters`. For enum columns set `optional: false` when the attribute is always set (so "is null" / "is not null" are hidden).
2. **URL state** – Parse `query` from the route search (e.g. `getQueryParam`, `queryParamToMap`). Derive `filterQueries = filterMap.size > 0 ? Array.from(filterMap.values()) : undefined` and pass to the list fetch/hook.
3. **Query options** – Add `placeholderData: keepPreviousData` to the list `queryOptions` so the UI keeps showing the previous list until the filtered (or paginated) request completes. Do **not** show a loading spinner or empty table when the query key changes (e.g. after applying a filter).
4. **Loader** – When the route has a `query` param (filters), skip prefetching the unfiltered list so only one request runs (the filtered one). Prefetch unfiltered first page only when there is no `query` param.
5. **View** – Render `<FiltersPopover>` with `columns`, `filterMap`, `onRemoveFilter`, `onClearAll`, `onApplyFilter`. On apply/remove/clear, navigate with updated `search.query` (and reset page to 1). Never use loader `initialData` for the list when filters are active (so the table never shows unfiltered data).
6. **Empty state** – When the list is empty: if filters are active show e.g. "No records match your filters" / "Try adjusting or clearing filters"; if no filters show e.g. "No DNS records" / "Add your first DNS record to get started".

Example (DNS records on domain detail): `src/lib/table-filters/filter-configs/dns-records.ts`, `src/components/pages/organizations/$orgId/domains/$domainId/View.tsx`, `src/routes/_public/organizations.$orgId.domains.$domainId.index.tsx`, and `domainRecordsQueryOptions` in `src/lib/react-query/hooks/domains.ts`.

---

## UX Guidelines

### Button Behavior

- **Never change button text during actions** - Keep text consistent, use `disabled` state
- **Use "Update" not "Edit"** - Align with API terminology
- **Disable with tooltip, don't hide** - Always prefer disabling buttons with a tooltip explaining why they are disabled over hiding them completely. This helps users understand what actions exist and why they can't perform them (e.g., "Upgrade your plan to access this feature", "Complete the form to continue"). Only hide buttons if explicitly requested.
- **No tooltip on text buttons** - Buttons that already have a text label (not just an icon) don't need a tooltip in addition to the label.

### Loading & Navigation

- **No individual loaders** - Rely on fullscreen loader for initial load
- **Stay on current page** - Until next page is ready to prevent layout shifts
- **Prefetch crucial data** - All critical API calls at route level
- **List pages: same default limit in loader and View** - Route and View must use the same default limit (same constant) so prefetched query key matches and no loading flash occurs. See "Route Prefetching" → "List pages: loader and View must use the same default limit".
- **Never define `pendingComponent` on list / tab / detail routes that prefetch data** - TanStack Router shows the pending UI as soon as navigation starts, replacing the previous page with "Loading…" even when the loader is fast or the cache is warm. This breaks the "stay on current page" rule and creates a flash of loading state. Omit `pendingComponent` so the **previous page stays visible** until the loader resolves, then the new page mounts with data already in the React Query cache. Only use `pendingComponent` for **standalone wizards** (e.g. `sites/create`, `functions/create`) where there is no previous page to keep visible.

#### Why "Loading rows…" (or any loader flash) appears on a tab/list route

Symptoms: clicking into a row/document/tab shows a "Loading rows…" / "Loading…" message instead of staying on the current page until data is ready.

Checklist (in this order):

1. **`pendingComponent` is defined on the route.** Remove it. The route should rely on the loader + cached data for the next view's first paint. (See rule above.)
2. **Loader and View use different default page sizes.** The loader prefetches `tableRowsQueryOptions(..., page-1, LIMIT_A, ...)` but the View calls `useProjectTableRows(..., page-1, LIMIT_B, ...)` with a different default. Different limits → different query keys → cache miss → loading spinner. **Fix:** import the **same shared constant** from `@/lib/react-query/hooks/constants` (e.g. `ROWS_DEFAULT_PAGE_SIZE`, `DEFAULT_PAGE_SIZE`) in both the route file and the View - never re-declare a local `const ROWS_PER_PAGE = 25` in the route; that's how route and View silently drift out of sync.
3. **Loader and View pass different sort/filter/search defaults.** The query key includes `search`, `order`, `sortBy`, and `filterQueries`. If the loader passes `'desc' / '$createdAt' / undefined` but the View first reads URL state and passes something else (or `null` vs `undefined`, or an empty array vs `undefined`), the keys won't match. **Fix:** normalize on both sides (e.g. `search?.trim() || undefined`, `filterMap.size > 0 ? Array.from(filterMap.values()) : undefined`) and use the **same default sort constants** in both the route and the View.
4. **Loader uses `fetchQuery` instead of `ensureQueryData`.** `fetchQuery` always re-fetches and ignores the cache, which can race with the View's hook. Use `ensureQueryData` (or `prefetchQuery`) so warm cache hits return instantly.
5. **Confirm with React Query devtools** that the query key the **View** subscribes to is **byte-identical** to the key the loader prefetched. If they differ, fix whichever side is wrong.

### Form Behavior

- **Reset after submission** - All creation forms reset and close dialog on success
- **Row ID input** - Show `IdInput` only during creation, optional custom ID or auto-generate

### Copy

- **No em dashes** - Do not use em dashes (`—`) in user-facing copy, labels, descriptions, or empty states. Use a period, comma, colon, or parentheses instead.

### Visual Design

- **Service avatars**: Always neutral (`bg-muted text-muted-foreground`), never colored
- **Button icon spacing**: Use `mr-1.5` or `gap-1.5`, not `mr-2` or larger
- **Date tooltips**: Always include when showing dates for timezone clarity

### Viewport units (CRITICAL - fixes mobile/iPad layout bugs)

**Never use `vh`** (or `h-screen`, `min-h-screen`, `max-h-screen`). On iOS Safari, iPadOS, Chrome on Android, and any browser with a dynamic toolbar, `100vh` refers to the _layout viewport_, which is taller than the actually visible viewport when browser chrome (URL bar, toolbar) is on screen. Anything sized via `vh` extends below the visible area, hiding sticky footers, CTAs, and dialog actions.

**Always use the dynamic viewport units instead:**

- **`dvh` / `dvw`** - dynamic viewport (resizes when chrome appears/hides). Default choice for most layouts.
- **`svh` / `svw`** - small viewport (smallest possible). Use for `min-h-svh` on full-page auth-style screens where you never want the layout to jump.
- **`lvh` / `lvw`** - large viewport (largest possible). Rarely needed.

**Tailwind tokens:**

```tsx
// Correct
<div className="h-dvh">          {/* fullscreen overlay */}
<div className="min-h-svh">      {/* full-page screen */}
<div className="h-[100dvh]">     {/* fullscreen wizard / dialog */}
<div className="max-h-[90dvh]"> {/* tall dialog content */}
<div className="max-h-[min(40dvh,300px)]"> {/* responsive cap */}

// Wrong - will be cut off on iPad/mobile
<div className="h-screen">
<div className="max-h-[90vh]">
<div style={{ height: 'calc(100vh - 4rem)' }}>
```

**Inline styles** must use the same units (`'100dvh'`, `'calc(85dvh - 52px)'`, etc.).

**Reference**: `src/components/global/shared/WizardLayout.tsx` - the fullscreen wizard container uses `h-[100dvh] max-h-[100dvh] overflow-hidden` so the inner flex layout (`shrink-0` header + `flex-1 min-h-0 overflow-y-auto` content + `shrink-0` footer) always reserves space for the sticky footer.

### Badge style

Use the status-style badge variants so all badges share the same design (tinted background, colored text, subtle border) and differ only by color. Do not mix in solid variants like `destructive` or `secondary` for status/labels when a status variant exists.

- **Same design**: Use `variant="error"` | `variant="warning"` | `variant="success"` | `variant="info"` (and other status variants in `badge.tsx`) so the visual treatment is consistent.
- **Semantics**: Use `error` for negative/expired/failed, `warning` for expiring soon/pending, `success` for positive/completed/verified, `info` for neutral/informational (e.g. scope count).
- **Inline with text**: When badges sit next to labels or in tight rows, use `className="text-[10px] shrink-0"` for consistent size.

**Reference:** `src/components/pages/projects/$projectId/shared/ApiKeysList.tsx` (Expired, Expires soon, scopes badges).

---

## SDK Service Reference

**Console SDK** (`sdk.forConsole`):

- `account` - Account, auth, sessions, MFA, billing, payments → `services/account.ts`
- `organizations` - Org management, billing, credits, invoices, plans → `services/organizations.ts`
- `projects` - Project management, API keys, platforms, webhooks → `services/projects.ts`
- `domains` - Domain management, DNS records, presets → `services/domains.ts`
- `console` - Campaigns, coupons, plans, regions, resources → `services/console.ts`
- `teams`, `vcs`, `backups`, `assistant`, `avatars` → See service files

**Project SDK** (`sdk.forProject(projectId)`):

- `tablesDB` - Table-based databases (new) → `services/tables-db.ts`
- `databases` - Document-based databases (legacy) → `services/databases.ts`
- `functions` - Functions, deployments, executions, variables → `services/functions.ts`
- `storage` - Buckets, files, previews → `services/storage.ts`
- `users` - User management, identities, sessions, MFA → `services/users.ts`
- `messaging` - Email, push, SMS, providers, topics → `services/messaging.ts`
- `sites`, `migrations`, `proxy`, `tokens`, `graphql`, `realtime`, `health`, `locale`, `project` → See service files

**Usage**: Always check service files for exact method signatures. Methods support object/positional args. List methods support `queries`, `search`, `total`.

---

## Models Types

**Always use `Models.*` from `@appwrite.io/console`** - Never guess response structure.

**Common patterns:**

- List methods → `Models.*List` (e.g., `Models.DatabaseList`, `Models.UserList`)
- Get/Create/Update → Individual models (e.g., `Models.Database`, `Models.User`)

**Example:**

```typescript
import type { Models } from '@appwrite.io/console'

async function fetchDatabases(projectId: string): Promise<Models.DatabaseList> {
  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.tablesDB.list() // TypeScript knows structure
}
```

**Reference**: `node_modules/@appwrite.io/console/src/models.ts`

---

## Route Prefetching

**Critical data must be prefetched** to prevent layout shifts.

### loader for Data Loading

**loader:**

- **Blocks navigation** until all awaited promises complete
- Runs for each route, ensuring data is ready before component renders
- Best for: All critical data (layout data, page-specific data)
- Use `await` on all critical data fetches to ensure proper blocking

**beforeLoad:**

- Only use for redirects or validation (not data loading)
- Runs before loader, but should not be used for data fetching

**When to use each:**

- **loader**: All critical data (project, organization plan, lists, detail views)
- **prefetchQuery**: Only for truly optional/non-critical data (doesn't block)

### React Query Methods

**fetchQuery / ensureQueryData** (use in loaders for critical data):

- **Blocks navigation** until data is ready when properly awaited
- `fetchQuery`: Always fetches (ignores cache) - **AVOID** for prefetched data
- `ensureQueryData`: Uses cache if fresh, fetches if stale/missing - **USE THIS** for prefetched data
- Use for: Critical data that must be available before rendering
- **Always await** these calls to ensure navigation blocks until data is ready

**prefetchQuery** (use for optional data):

- **Does NOT block** navigation
- Fetches in background, doesn't wait
- Use for: Optional data, supporting data, non-critical prefetching

### QueryOptions Pattern (CRITICAL - Prevents Duplicate API Calls)

**Always use `queryOptions` pattern for hooks that are prefetched in route loaders.** This ensures the route loader and component hook share the exact same query configuration, preventing duplicate API calls.

**Step 1: Create `queryOptions` function in hooks file**

```typescript
import { queryOptions } from '@tanstack/react-query'

// In hooks file (e.g., src/lib/react-query/hooks/storage.ts)

/**
 * Query options for fetching paginated buckets for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function bucketsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: ['buckets', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectBuckets(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}
```

**Step 2: Update hook to use `queryOptions`**

```typescript
export function useProjectBuckets(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: bucketsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(bucketsQueryOptions(projectId, page, limit, search))

  // ... rest of hook implementation
}
```

**Step 3: Use `queryOptions` in route loader**

```typescript
// Route loader (e.g., src/routes/_public/projects.$projectId.storage.index.tsx)
import {
  bucketsQueryOptions,
  fetchProject,
  fetchOrganizationPlan,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/projects/$projectId/storage/')({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    // Critical layout data - blocks navigation until ready
    const projectData = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    // Organization plan - blocks navigation until ready
    if (projectData?.teamId) {
      await queryClient
        .ensureQueryData({
          queryKey: ['organization', 'plan', projectData.teamId],
          queryFn: () => fetchOrganizationPlan(projectData.teamId),
          staleTime: 5 * 60 * 1000,
        })
        .catch(() => {
          // Ignore errors for optional data
        })
    }

    // Page-specific critical data - USE queryOptions to prevent duplicate calls
    await queryClient.ensureQueryData(
      bucketsQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, ''),
    )

    // Optional data - doesn't block
    queryClient
      .prefetchQuery({
        queryKey: ['optional-data', projectId],
        queryFn: () => fetchOptional(projectId),
      })
      .catch(() => {
        // Don't block navigation on optional data errors
      })
  },
})
```

**Why This Works:**

1. **Shared Configuration**: Route loader and hook use the exact same `queryOptions`, ensuring identical query keys and settings
2. **Cache Matching**: React Query recognizes prefetched data because query keys match exactly
3. **No Duplicates**: `ensureQueryData` uses cached data if fresh, and hook reads from same cache
4. **Type Safety**: TypeScript ensures query keys and functions match between loader and hook

**List pages: loader and View must use the same default limit (CRITICAL – prevents layout shift)**

- The route loader prefetches list data using **URL-derived params** (page, limit, search, filters). The View reads the same params from the URL (or from route-passed search) and calls the same hook with the same arguments.
- **If the default limit differs** (e.g. loader uses `25`, View uses `DEFAULT_PAGE_SIZE` = `10`), the **query keys will not match**. The View will get a cache miss, show a loading state, and cause a layout shift even though the loader already fetched data.
- **Rule:** Use the **same constant** for the default limit in both the route and the View. Import from `@/lib/react-query/hooks/constants` (e.g. `DEFAULT_PAGE_SIZE`, `ROWS_DEFAULT_PAGE_SIZE`) and use it in:
  - **Route:** `getLimit(url, DEFAULT_PAGE_SIZE)` (or the chosen constant) and in `queryOptions(projectId, page - 1, limit, ...)`.
  - **View:** `getLimit(url, DEFAULT_PAGE_SIZE)` (or same constant) and `urlLimit = listParams?.limit ?? DEFAULT_PAGE_SIZE`, and pass that `urlLimit` into the hook.
- **Do not** define a local constant in the route (e.g. `const SITES_PER_PAGE = 25`) unless the View uses the exact same value for its default limit; otherwise prefer a shared constant from `constants.ts` so route and View cannot drift.
- **Required:** Do **not** define `pendingComponent` on list/tab routes. With prefetching in place, the previous page must stay visible until the loader completes; the new list then renders with data already in cache and no intermediate "Loading…" UI. `pendingComponent` defeats this and forces a loader flash on every navigation. (See "Loading & Navigation" → "Why 'Loading rows…' appears" for the full troubleshooting checklist.)

**When to Use QueryOptions:**

- ✅ **DO**: Use for all hooks that are prefetched in route loaders (buckets, functions, databases, users, providers, etc.)
- ✅ **DO**: Use for list views that are loaded on initial page load
- ❌ **DON'T**: Use for hooks that are only called conditionally or on user interaction
- ❌ **DON'T**: Use for single resource queries that aren't prefetched (unless you want to prefetch them)

**Critical data to prefetch:**

- Initial page of list data (page 0, no search)
- Total count for limit checking
- Project data (for plan limits)
- Organization plan (for limit checking)
- Detail page data

**Don't prefetch:**

- Search results (user hasn't searched)
- Filtered data (no filters applied)
- Subsequent pages (only page 1)
- Optional/conditional data

### Detail page: no loading flash (initialData pattern)

**Goal:** When navigating to a detail page (e.g. domain detail, bucket detail), the user must never see "Loading…", "Not found", or a loading placeholder. The current page stays visible until the new page is fully ready, then we transition with data already rendered.

**Why cache-only isn’t enough:** Even with `fetchQuery` in the loader (same keys as hooks), the View can mount before the cache is visible to React Query, causing a brief "Not found" or "Loading…" flash. Passing **loader data as `initialData`** into the View guarantees the first paint has data.

**Checklist for a new detail page:**

1. **Parent layouts:** No loader on the detail layout (e.g. `$domainId`, `$bucketId`). Only the **leaf** route that renders the detail page has a loader. The **list** layout must not fetch the list when navigating to a detail route (or have no loader at all so only the list index fetches the list).
2. **Detail route loader:**
   - Use `queryClient.fetchQuery({ queryKey, queryFn, staleTime })` with **exact same keys** as the hooks (so cache is populated).
   - **Return** the fetched data from the loader (e.g. `return { resource, listData }`).
3. **Route component:** Call `Route.useLoaderData()` and pass it to the View as `initialData`.
4. **View component:**
   - Accept optional `initialData?: { resource; listData? }` (or whatever the page needs).
   - Use `resource = dataFromHook ?? initialData?.resource` (and same for list data on first page) so the first paint uses loader data.
   - Show "Not found" only when `!resource && !initialData?.resource && !loading` (never while loading or when initialData is present).
   - For list/table content, use initialData for the first page when the hook hasn’t returned yet (e.g. `isFirstPage && initialData?.listData ? … : dataFromHook`), and only show "Loading…" when you truly have no data and are loading.

**Reference implementation:**

- **Route (loader returns data, component passes initialData):** `src/routes/_public/organizations.$orgId.domains.$domainId.index.tsx`
- **View (initialData prop, no flash):** `src/components/pages/organizations/$orgId/domains/$domainId/View.tsx` (see `DomainDetailInitialData`, `initialData` prop, and use of `domainFromHook ?? initialData?.domain` and first-page records from `initialData.records`).

**Route pattern (detail index):**

```typescript
// 1. Loader: fetchQuery (same keys as hooks) AND return data
loader: async ({ params, context }) => {
  if (typeof window === 'undefined') return undefined
  const { resourceId } = params
  const { queryClient } = context
  if (!resourceId) return undefined

  const [resource, listData] = await Promise.all([
    queryClient.fetchQuery({
      queryKey: ['resource', resourceId],
      queryFn: () => fetchResource(resourceId),
      staleTime: 30 * 1000,
    }),
    queryClient.fetchQuery({
      queryKey: ['list', 'resource', resourceId, 0, PAGE_SIZE],
      queryFn: () => fetchList(resourceId, 0, PAGE_SIZE),
      staleTime: 30 * 1000,
    }),
  ])
  return { resource, listData }
},
component: DetailPage,
})

function DetailPage() {
  const { resourceId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View
      key={`resource-${resourceId}`}
      initialData={loaderData ? { resource: loaderData.resource, listData: loaderData.listData } : undefined}
    />
  )
}
```

**View pattern (optional initialData, no flash):**

```typescript
type DetailInitialData = { resource: Resource; listData?: { items: Item[]; total: number } }
type ViewProps = { initialData?: DetailInitialData }

export function View({ initialData }: ViewProps = {}) {
  const { data: resourceFromHook, isLoading: resourceLoading } = useResource(resourceId)
  const resource = resourceFromHook ?? initialData?.resource

  const { items: itemsFromHook, total: totalFromHook, isLoading: listLoading } = useList(resourceId, pageIndexed, pageSize)
  const isFirstPage = currentPage === 1
  const items = isFirstPage && initialData?.listData && !itemsFromHook?.length
    ? initialData.listData.items
    : itemsFromHook ?? []
  const total = isFirstPage && initialData?.listData ? (totalFromHook ?? initialData.listData.total) : totalFromHook ?? 0

  // Never show "Not found" while loading or when we have initialData
  if (!resource && !initialData?.resource && !resourceLoading) {
    return <NotFound />
  }

  // Only show "Loading list…" when we have no items and we're loading (not when initialData supplied first page)
  if (listLoading && items.length === 0) {
    return <LoadingList />
  }

  return ( /* full page with resource and items */ )
}
```

---

## File Structure Details

### Directory Organization

```
src/components/
├── global/              # Truly global components
│   ├── layout/         # Header, Sidebar, Footer
│   ├── auth/           # RequireAuth, LoginForm
│   ├── shared/         # DateTooltip, CopyableId, Pagination
│   └── providers/      # AIChat, DebugMode
│
├── pages/              # Page-specific, route-aligned
│   ├── projects/$projectId/
│   │   ├── storage/View.tsx
│   │   ├── storage/$bucketId/View.tsx
│   │   ├── storage/files/$fileId/View.tsx
│   │   ├── storage/_components/     # non-page UI (dialogs, cards)
│   │   │   ├── CreateBucket.tsx
│   │   │   ├── BucketSettings.tsx
│   │   │   └── ...
│   │   ├── sites/View.tsx           # list
│   │   ├── sites/usage/View.tsx      # sub-route = nested folder
│   │   ├── sites/Layout.tsx          # layout for $siteId (not a page)
│   │   ├── sites/Deployments.tsx     # tab pages (export View)
│   │   ├── sites/deployments/$deploymentId/View.tsx
│   │   └── shared/    # Project-specific shared
│   └── organizations/$orgId/
│
└── ui/                 # ShadCN (do not modify)
```

### Naming Conventions

Use these consistently so page and component names are predictable:

- **List and detail pages**: File = `View.tsx`; export the page component as `View`. Use one `View.tsx` per route segment (e.g. `storage/View.tsx` for list, `storage/$bucketId/View.tsx` for bucket detail). Do not add separate `*Detail.tsx` files for detail pages.
- **Detail routes**: Put the detail page in `$resourceId/View.tsx` (e.g. `storage/$bucketId/View.tsx`, `messaging/$messageId/View.tsx`), not in a sibling `*Detail.tsx`.
- **Dialogs**: File = `CreateResource.tsx` or `UploadResource.tsx` (no "Dialog" in the filename); export the component with the same name as the file (e.g. `CreateBucket`, `UploadFile`).
- **Settings / tabs**: File = `Settings.tsx`, `Security.tsx`, or `ResourceSettings.tsx` (e.g. `BucketSettings.tsx`, `TopicSettings.tsx`); export as `View` or a descriptive name.
- **Other shared UI**: Descriptive names (`BucketSettings.tsx`, `FileSecurity.tsx`).

### Folder-Route Alignment

- Route: `/projects/$projectId/storage` → `pages/projects/$projectId/storage/`
- Route: `/projects/$projectId/storage/$bucketId` → `pages/projects/$projectId/storage/$bucketId/`
- Route: `/projects/$projectId/storage/$bucketId/files/$fileId` → `pages/projects/$projectId/storage/files/$fileId/`

### When to nest vs keep flat

**Mirror the URL with folders.** Each **route segment** (path part) should usually get a folder, and the **page** for that segment is `View.tsx` inside it.

- **Nest** when the route has another segment:  
  `/sites/usage` → `sites/usage/View.tsx`  
  `/sites/$siteId/deployments` → `sites/$siteId/deployments/View.tsx`  
  So: list = `service/View.tsx`, sub-page = `service/segment/View.tsx`, detail = `service/$id/View.tsx` or `service/$id/segment/View.tsx`.

- **Keep flat** only when a segment has a single page and no children (e.g. one-off wizards or modals). Then one file like `CreateSiteView.tsx` or `AddDomain.tsx` in the parent folder is fine.

- **No namespace repetition:** We're already under `sites/`, so avoid repeating "Site" in file or component names. Use `Deployments.tsx` (or `deployments/View.tsx`), not `SiteDeployments.tsx`; use `usage/View.tsx`, not `SitesUsageView.tsx`.

### When to use View.tsx vs a specific name

- **Use `View.tsx`** for every **page** (a route that renders a full screen or tab content). The file is always `View.tsx`; the exported component is always `View`. So: list page = `View.tsx`, detail page = `$resourceId/View.tsx`, tab page = `segment/View.tsx` (e.g. `deployments/View.tsx`, `settings/View.tsx`).

- **Use a specific name** only for **non-page** UI: layout wrappers (`Layout.tsx`), dialogs (`CreateBucket.tsx`), cards (`BucketSettings.tsx`), wizards (e.g. `CreateSiteView.tsx` for a multi-step flow). Those are not "the page for a segment"; they're shared components or flows, so a descriptive name is correct.

**Summary:** If it's the main component for a route segment → put it in a folder that matches the segment and name the file `View.tsx` (export `View`). If it's a layout, dialog, or shared card → use a descriptive filename.

### Non-page UI: `_components/` folder

Keep **non-page UI** (dialogs, cards, drawers, editors, modals) in a dedicated subfolder so they’re easy to spot and don’t mix with route-aligned pages.

- **Folder name:** `_components/` (underscore = “internal to this feature”, not a route segment).
- **What goes in:** Dialogs (`CreateBucket.tsx`, `UploadFile.tsx`), settings/security cards (`BucketSettings.tsx`, `BucketSecurity.tsx`), drawers (`ApiKeyDrawer.tsx`), editors (`PermissionsEditor.tsx`, `CronScheduleEditor.tsx`), and any other UI that is **not** the main component for a route segment.
- **What stays at top level:** Only route-aligned pages and layout: `View.tsx`, `Layout.tsx`, segment folders (`$bucketId/`, `usage/`, `deployments/`), and segment page files (`Deployments.tsx`, `Settings.tsx` that export `View`).

**Example:**

```
storage/
├── View.tsx                    # list page
├── $bucketId/
│   └── View.tsx                # bucket detail page
├── files/$fileId/View.tsx      # file detail page
└── _components/                # non-page UI for this feature
    ├── CreateBucket.tsx
    ├── UploadFile.tsx
    ├── BucketSettings.tsx
    ├── BucketSecurity.tsx
    └── FileSecurity.tsx
```

**Import from pages:** `import { CreateBucket } from './_components/CreateBucket'` (or `from '../_components/...'` when inside a subfolder like `$bucketId/`).

---

## Service View Implementation

### Tab Navigation (Route-Based)

```typescript
const activeTab = useMemo(() => {
  const pathParts = location.pathname.split('/').filter(Boolean)
  const serviceIndex = pathParts.findIndex((part) => part === 'service-name')
  if (serviceIndex >= 0 && pathParts[serviceIndex + 1]) {
    const tab = pathParts[serviceIndex + 1]
    if (['tab1', 'tab2'].includes(tab)) return tab
  }
  return 'main-tab'
}, [location.pathname])

const tabs = [
  {
    id: 'main-tab',
    label: 'Main',
    count: total,
    to: '/projects/$projectId/service/',
    params: { projectId },
  },
  {
    id: 'other-tab',
    label: 'Other',
    to: '/projects/$projectId/service/other',
    params: { projectId },
  },
]
```

### Data Fetching

```typescript
// Main query (with search)
const { resources, total, isLoading } = useProjectResources(
  projectId,
  pageIndexed,
  pageSize,
  searchValue,
)

// Total count for limit checking (without search)
const { data: totalData } = useQuery({
  queryKey: ['resources', 'project', projectId, 'total'],
  queryFn: () => fetchProjectResources(projectId!, 0, 1, ''),
  enabled: !!projectId,
})
const totalCount = totalData?.total || 0
```

### Plan Limit Checking

```typescript
const { project } = useProject(projectId)
const { plan } = useOrganizationPlan(project?.teamId)
const limit = plan?.resources ?? 0
const isCreateDisabled = limit > 0 && totalCount >= limit
```

### List/Grid Views

- **List**: Table with checkboxes, bulk selection, pagination
- **Grid**: ResourceCard components in responsive grid
- **Empty states**: Different for empty vs. no search results
- **Loading**: Show message while fetching

### Bulk Operations

- **Selection**: Checkboxes in list view, clear on navigation/search
- **Action bar**: Fixed bottom, shows count and delete button
- **Confirmation**: Dialog with item count, destructive action

---

## Code Examples

### Complete Service View

See `src/components/pages/projects/$projectId/storage/View.tsx` for full implementation reference.

### Settings Cards

See these files for card structure examples:

- `src/components/pages/projects/$projectId/storage/BucketSettings.tsx`
- `src/components/pages/projects/$projectId/settings/Overview.tsx`
- `src/components/pages/organizations/$orgId/domains/$domainId/View.tsx`

### Modal Patterns

Follow the modal structure pattern above. For no-content modals, skip content section.

---

## Quick Reference

| Task                   | Pattern                                                                                                                                                                                                                                                                                                                 |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fetch data             | Extract query function, use in hook + route loader                                                                                                                                                                                                                                                                      |
| Pagination             | requestedPage + displayedPage; use displayed data/total for list and Pagination until new page loads                                                                                                                                                                                                                    |
| Filters                | FiltersPopover + URL `query`; `placeholderData: keepPreviousData` on list query; no loading state when filters change; different empty state for "no items" vs "no results for filters" (see "Filters (table/list)")                                                                                                    |
| Delete resource        | Use `refetchQueries` (not `invalidateQueries`) in onSuccess so list updates without reload                                                                                                                                                                                                                              |
| Create resource        | Form resets and closes dialog on success                                                                                                                                                                                                                                                                                |
| Update resource        | Use "Update" terminology, not "Edit"                                                                                                                                                                                                                                                                                    |
| Button during action   | Keep text, use `disabled` state                                                                                                                                                                                                                                                                                         |
| Unavailable action     | Disable button with tooltip, don't hide                                                                                                                                                                                                                                                                                 |
| Text buttons           | No tooltip when button has a text label (tooltips only for icon-only buttons)                                                                                                                                                                                                                                           |
| Em dashes              | Never use `—` in user-facing copy; use a period, comma, colon, or parentheses instead                                                                                                                                                                                                                                   |
| Service avatar         | `bg-muted text-muted-foreground` (never colored)                                                                                                                                                                                                                                                                        |
| Badge style            | Use status variants (`error`, `warning`, `success`, `info`) for same design; `text-[10px] shrink-0` when inline with text                                                                                                                                                                                               |
| Icon spacing           | `mr-1.5` or `gap-1.5`                                                                                                                                                                                                                                                                                                   |
| Date display           | Always include DateTooltip                                                                                                                                                                                                                                                                                              |
| Route prefetch         | All crucial data at route level                                                                                                                                                                                                                                                                                         |
| List/tab loading flash | Never set `pendingComponent` on list/tab/detail routes that prefetch (it shows "Loading…" instead of keeping the current page). Match query keys exactly between loader and View - same shared constant for default limit/sort/search/filter normalization. See "Loading & Navigation" → "Why 'Loading rows…' appears". |
| Detail page (no flash) | Loader returns data; route passes `initialData` to View; View uses `initialData` for first paint (see "Detail page: no loading flash")                                                                                                                                                                                  |
| Models types           | Always `Models.*` from `@appwrite.io/console`                                                                                                                                                                                                                                                                           |
| Table header           | `hover:bg-transparent border-b border-border` on row, `px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider` on head                                                                                                                                                                      |
| Actions column         | Never use "Actions" as title - use empty `TableHead`                                                                                                                                                                                                                                                                    |
| Table cells            | `px-4 py-3` on all cells (preserve special padding like `pl-6 sm:pl-8` where needed)                                                                                                                                                                                                                                    |
| Long-running progress  | One panel per scope; flat list of cards (no wrapper per type); same card style + ProgressBarRow; per-card dismiss; auto-action only on status transition to completed                                                                                                                                                   |
| Row actions menu (⋯)   | `RowActionsMenuTrigger` + `DropdownMenuItem` with `MenuItemContent` / `MenuItemIcon`; same icons and ordering as the resource context menu. See "Context menu and row actions menus".                                                                                                                                   |
| Delete styling         | No red text on menu/row delete actions. Red `variant="destructive"` only in delete cards, dialog confirm buttons, and bulk delete bars. See "Context menu and row actions menus" → "Destructive action styling".                                                                                                        |
| RBAC (roles)           | Use **feature check methods** from `@/lib/console-access-checks` only; never check `access.isOwner` or `access.canWrite*` directly. Use `canAccess*` from `console-rbac-loader` in route loaders. See "Role-based access control (RBAC)".                                                                               |

---

## Console Profiles

Profiles control which features are available based on deployment type (cloud vs self-hosted).

**Profiles:**

- **Cloud** (default): Full feature set – billing, domains, usage stats, activity, org roles, system status, account MFA, account identities, **user verification** (redirect to verify-email page after signup)
- **Self-hosted**: Cloud-only features disabled (user verification off; signup redirects directly to console)

**Env var:** `VITE_CONSOLE_PROFILE=cloud` or `VITE_CONSOLE_PROFILE=self-hosted`

**Debug mode:** When debug menu is open (press `.`), use Console profile submenu to override the env-selected profile. Override is stored in localStorage and takes precedence until "Use env var" is selected.

**Feature flags:** Use `useConsoleProfile()` or `getActiveProfileFeatures()` to check feature flags (e.g. `features.billing`, `features.domains`, `features.compliance`, `features.databaseBackups`).

**Feature-driven keys:** Each flag must map to a single, specific feature. Do not use generic or grouped flags (e.g. `orgCloudSettings`, `databaseCloudFeatures`). Split into explicit flags per feature (e.g. `compliance`, `oauthApps`, `orgApiKeys` for org settings; `databaseBackups`, `databaseInsights` for database).

---

## Role-based access control (RBAC)

Access is driven by **organization roles and scopes** when the Console profile has `orgRoles: true` (e.g. cloud). When `orgRoles` is false (e.g. self-hosted), all users are treated as having full access. Use the same patterns everywhere so screens and components stay consistent.

### When RBAC applies

- **Profile**: Check `features.orgRoles` from `useConsoleProfile()` or `getActiveProfileFeatures()`. If `false`, do not gate by role/scope; show everything.
- **Context**: For **project** screens use the project’s organization (`project.teamId`). For **organization** screens use `orgId`. Resolve scopes for that organization via `useOrganizationScopes(orgIdOrTeamId)` or loader helpers in `console-rbac-loader.ts`.

### Key files and hooks

| File / hook                             | Purpose                                                                                                                                                          |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/lib/console-roles.ts`              | `ConsoleAccess` type, `deriveAccessFromRolesScopes`, `FULL_ACCESS` when roles disabled                                                                           |
| `src/lib/console-access-checks.ts`      | **Single source of truth** for all permission checks. Use these functions in UI and routes; **never** check `access.isOwner`, `access.canWrite*`, etc. directly. |
| `src/lib/console-rbac-loader.ts`        | Async `canAccess*` helpers for **route loaders** (delegate to `console-access-checks` internally)                                                                |
| `useOrganizationScopes(organizationId)` | Returns `{ access: ConsoleAccess, ... }`; pass `access` and `features` into functions from `console-access-checks`                                               |
| `useProject(projectId)`                 | Returns `project` (includes `teamId`) so you can pass `project?.teamId` to `useOrganizationScopes`                                                               |
| `useConsoleProfile()`                   | Returns `features`; pass to `console-access-checks` functions that need `orgRoles` or other flags                                                                |

### Rules

1. **Use feature check methods only**  
   **Never** read `access.isOwner`, `access.isDeveloper`, `access.canWrite*`, or `access.canSee*` directly in components or routes. Always use a function from `@/lib/console-access-checks` (e.g. `canShowBucketSecuritySettings(access, features)`, `canCreateProject(access, features)`). This keeps rules reusable, consistent, and maintainable.

2. **Components (UI)**  
   Use `useOrganizationScopes(project?.teamId)` or `useOrganizationScopes(orgId)` and `useConsoleProfile()`. Call the appropriate check from `console-access-checks` (e.g. `showSecuritySettings = canShowBucketSecuritySettings(access, features)`), then:
   - Filter **tabs** (e.g. hide Security/Settings/Variables when `!showSecuritySettings`)
   - **Disable** create buttons with a tooltip using `!canCreateX(access, features)` and a permission message
   - Hide or show **sidebar** items (e.g. `canSeeProjectNavItem`, `canShowProjectSettings`, `canShowConnectSection`), **Command Center** commands, org **tabs** (e.g. `canShowOrgDomainsTab`, `canShowOrgSettingsTab`)

3. **Route loaders (blocking access)**  
   For Security, Settings, Variables, or other write-only pages, use the **loader** so unauthorized users never see the page. Import the matching helper from `@/lib/console-rbac-loader` and redirect when `!canAccess`:
   - Project resources: `canAccessDatabaseSecuritySettings`, `canAccessProjectSettings`, `canAccessAuthSecuritySettings`, `canAccessBucketSecuritySettings`, `canAccessFunctionSecuritySettings`, `canAccessSiteSettings`, `canAccessTopicSettings`
   - Organization: `canAccessOrganizationSettings` (owner-only org settings), `canAccessOrganizationDomains` (owner or developer)

4. **Redirect from disallowed tabs**  
   In the same view that builds the tabs, add a `useEffect`: if the user is on a tab they’re not allowed (e.g. `activeTab === 'security' && !showSecuritySettings`), navigate to a safe tab (e.g. first tab or list).

5. **Command Center and global nav**  
   Use the same `console-access-checks` functions (e.g. `canShowProjectSettings`, `canShowConnectSection`, `canCreateBucket`) to hide or disable navigation/create commands and filter the keyboard-shortcuts reference.

6. **Adding new checks**  
   When you need a new permission rule, add a single function in `console-access-checks.ts` (and use it in loaders via `console-rbac-loader` if a route must be blocked). Do not scatter role/scope logic in components.

### Examples

**1. Hiding tabs on a detail page (e.g. bucket Security/Settings)**

```tsx
import { canShowBucketSecuritySettings } from '@/lib/console-access-checks'

const { project } = useProject(projectId)
const { features } = useConsoleProfile()
const { access } = useOrganizationScopes(project?.teamId)
const showSecuritySettings = canShowBucketSecuritySettings(access, features)

const tabs = useMemo(() => [
  { id: 'files', label: 'Files', to: '...', params: { ... } },
  ...(showSecuritySettings
    ? [
        { id: 'security', label: 'Security', to: '...', params: { ... } },
        { id: 'settings', label: 'Settings', to: '...', params: { ... } },
      ]
    : []),
], [projectId, bucketId, showSecuritySettings])
```

**2. Redirect when user is on a tab they can’t access**

```tsx
useEffect(() => {
  if (showSecuritySettings || !projectId || !bucketId) return
  if (activeTab === 'security' || activeTab === 'settings') {
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId, bucketId },
      replace: true,
    })
  }
}, [showSecuritySettings, activeTab, projectId, bucketId, navigate])
```

**3. Route loader: block Security/Settings page**

```tsx
import { canAccessBucketSecuritySettings } from '@/lib/console-rbac-loader'

loader: async ({ params, context }) => {
  if (typeof window === 'undefined') return
  const { projectId, bucketId } = params
  const { queryClient } = context
  if (!projectId || !bucketId) return
  const canAccess = await canAccessBucketSecuritySettings(queryClient, projectId)
  if (!canAccess) {
    throw redirect({ to: '/projects/$projectId/storage/$bucketId', params: { projectId, bucketId }, replace: true })
  }
},
```

**4. Disable create button with tooltip (ServiceHeader / list view)**

```tsx
import { canCreateBucket } from '@/lib/console-access-checks'

const noCreatePermission = !canCreateBucket(access, features)
const createPermissionTooltip = noCreatePermission ? "You don't have permission to create buckets." : undefined

<ServiceHeader
  createLabel="Create bucket"
  onCreate={handleCreate}
  createDisabled={noCreatePermission}
  createDisabledTooltip={createPermissionTooltip}
  ...
/>
```

**5. Command Center: hide or disable by access**

Use `console-access-checks` (e.g. `canShowProjectSettings`, `canShowConnectSection`, `canShowOrgDomainsTab`, `canCreateBucket`) so navigation and create commands are filtered by the same rules as the rest of the app.

### Mapping: screen/component type → what to use

| Screen / component type                                 | Where to gate                            | What to use                                                                                                                                                                       |
| ------------------------------------------------------- | ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Service detail tabs (Security, Settings, Variables)     | View that renders `ServiceHeader` + tabs | `canShowXSecuritySettings(access, features)` or `canShowTopicSettingsTab` etc.; filter tabs, add redirect `useEffect`                                                             |
| Security/Settings/Variables **route** (URL)             | Route file                               | `canAccess*` from `console-rbac-loader` in **loader**; `throw redirect(...)` if `!canAccess`                                                                                      |
| Create buttons (list, header, dialogs)                  | Same view as list/header                 | `!canCreateX(access, features)`; set `createDisabled` and `createDisabledTooltip` (prefer disable + tooltip over hiding)                                                          |
| Sidebar (project Settings, Connect, Get started)        | Layout/sidebar component                 | `canShowProjectSettings`, `canShowConnectSection`, `canShowGetStartedSection`, `canSeeProjectNavItem` from `console-access-checks`                                                |
| Command Center (nav, create, org)                       | CommandCenter.tsx                        | Same `console-access-checks` functions; hide or disable commands and shortcuts accordingly                                                                                        |
| Org overview (tabs, invite, Domains, Settings sub-tabs) | Org overview View                        | `canShowOrgDomainsTab`, `canShowOrgSettingsTab`, `canAccessOrgSettings*`, `getFirstAllowedOrgSettingsPath`, `canInviteOrgMember`, `canCreateProject` from `console-access-checks` |

### Adding a new gated area

1. **New project resource (e.g. “X” with Security/Settings)**
   - In **console-access-checks.ts**: add e.g. `canShowXSecuritySettings(access, features)` using `whenOrgRoles(access, features, access.canWriteX)`. In **console-rbac-loader.ts**: add `canAccessXSecuritySettings(queryClient, projectId)` that gets access and returns `canShowXSecuritySettings(access, getActiveProfileFeatures())`.
   - In **route loaders** for `.../security` and `.../settings`: call that helper and `throw redirect` when `!canAccess`.
   - In the **detail View**: use `showSecuritySettings = canShowXSecuritySettings(access, features)`; filter tabs and add redirect `useEffect`.

2. **New org-level area (e.g. “Y” for owner and developer)**
   - In **console-access-checks.ts**: add e.g. `canShowOrgYTab(access, features)` or `canAccessOrgY(access, features)`.
   - If a route must be blocked: add `canAccessOrganizationY` in console-rbac-loader that uses the new check; use it in the route loader.
   - In the org View: show the tab or nav item using the new check; redirect from the sub-route when the user lands without access (e.g. `getFirstAllowedOrgSettingsPath` for settings).

---

## Product Analytics

Product analytics uses Plausible and must stay privacy-friendly. Most interaction tracking is automatic through `useGlobalAnalyticsTracker` in the root.

### Rules

- **Use the shared helper**: Import `useAnalytics` from `@/hooks/use-analytics` in components, or use `trackEvent` / `trackPageView` from `@/lib/analytics` outside React.
- **Use automatic tracking by default**: `useGlobalAnalyticsTracker` in the root tracks links, buttons, menu items, tabs, non-text controls, form submits, and dialog open/close events across the app.
- **Prefer dynamic event names**: Do not add explicit analytics metadata attributes to buttons. The root tracker builds click and control event names from the element label, tooltip, or icon when available, with a generic fallback such as `Button Clicked`.
- **Never send raw IDs or user-entered values**: Do not send project IDs, organization IDs, resource IDs, names, emails, domains, search terms, query strings, or full URLs.
- **Use route templates**: Page views and events should use sanitized routes such as `/projects/$projectId/databases/$databaseId`, not concrete paths.
- **Keep manual tracking low-cardinality**: Manual event props should be booleans, counts, fixed enums, route templates, resource types, steps, results, or error names. Avoid labels or arbitrary strings from the UI.
- **Track important outcomes manually**: For create/update/delete flows, track success and API failure when automatic form/click tracking is not enough.
- **Avoid duplicates**: When a component has richer explicit tracking for a click or form, add `data-analytics-track="manual"` to the generic DOM element so the root tracker skips it.

### Automatic event names

Click and control events are named from the best safe descriptor available:

- `aria-label` / `aria-labelledby`
- visible text
- tooltip text (`title` / `aria-describedby`)
- icon name, including Lucide icon classes

Examples: `Create Project Button Clicked`, `Trash Menu Item Clicked`, `Docs External Link Opened`. If no safe descriptor exists, the tracker falls back to generic names such as `Button Clicked`, `Menu Item Clicked`, `Control Changed`, `Navigation Clicked`, or `External Link Opened`.

### Manual tracking

Use manual tracking only for outcomes and flows that generic interaction tracking cannot explain. Common event names:

- `Form Validation Failed`
- `Resource Created`
- `Resource Creation Failed`
- `Error Shown`
- `Wizard Opened`
- `Wizard Option Selected`

If you add a new reusable manual event name, update `AnalyticsEventName` in `src/lib/analytics.ts` and this section.

### Tracking opt-outs

Use attributes only to opt out of automatic tracking:

- `data-analytics-track="manual"`: skip automatic tracking because the component tracks explicitly.
- `data-analytics-track="false"`: skip tracking entirely for this element subtree.

---

## Team and user preferences (key-value format)

Console uses **team** (organization) and **user** (account) preferences to store small key-value settings. Use a consistent, extendable format so new features can add keys without collisions.

### Key format

- **Pattern**: `console.<feature>.<optionalSubKey>`
- **Examples**: `console.pinnedProjectIds`, `console.sidebarCollapsed`, `account.organization` (user-level).
- **Scope**: Team prefs are per organization (`sdk.forConsole.teams.get/updatePrefs` with `teamId`). User/account prefs are per user (`sdk.forConsole.account.updatePrefs`).

### Value format

- **Type**: String. For complex data (e.g. arrays, objects), store a **JSON string** and parse when reading.
- **Example**: Pinned project IDs → key `console.pinnedProjectIds`, value `["projectId1","projectId2"]`.

### Reading and writing

- **Team**: `const team = await sdk.forConsole.teams.get({ teamId })` → `team.prefs` is `Record<string, unknown>`. Merge your key into `prefs` and call `sdk.forConsole.teams.updatePrefs({ teamId, prefs })`.
- **User**: Same idea with `sdk.forConsole.account.get()` / `account.prefs` and `sdk.forConsole.account.updatePrefs({ prefs })`.

### Adding a new setting

1. Define the key (and max length/format) in code (e.g. `src/lib/team-prefs-keys.ts`).
2. Provide `parse*` / `build*` helpers that read from `prefs[key]` and return a merged `prefs` object for updates.
3. Document the key in this section if it is a shared convention (e.g. `console.pinnedProjectIds`).

### User prefs: saved filter presets

- **Key**: `console.savedFilters.<scope>` (e.g. `console.savedFilters.sites`, `console.savedFilters.storage.files.<bucketId>`). Scope identifies the list view and, when the list structure is unique per resource, includes resource type and id.
- **Value**: JSON string of `SavedFilter[]` (`{ id, name, query }`). `query` is the same encoded format as the URL `query` param (compact filter keys).
- **Storage**: Account (user) prefs via `sdk.forConsole.account.updatePrefs`. See `src/lib/user-prefs-keys.ts` and `useSavedFilters` in `src/lib/react-query/hooks/auth.ts`.

**Scope convention:**

- **Shared** (same structure for all items in the view): use a fixed scope so filters are shared across the list.
  - Examples: `sites`, `auth.users`, `auth.teams`, `storage.buckets`, `functions`, `databases`, `organizations.domains`, `organizations.domains.records`, `sites.deployments`, `sites.logs`, `sites.domains`, `functions.deployments`, `functions.executions`, `functions.domains`.
- **Per resource** (unique structure per resource): include resource type and id in the scope so each resource has its own saved filters.
  - Examples: `databases.rows.<databaseId>.<tableId>`, `databases.columns.<databaseId>.<tableId>`, `databases.indexes.<databaseId>.<tableId>` (each table has its own columns/attributes).
  - Files use shared scope `storage.files` (same filter structure across buckets).

---

## Environment

Set `VITE_APPWRITE_ENDPOINT` in `.env` (default: `https://cloud.appwrite.io/v1`). Project endpoints are dynamic (per-project region); use `getApiEndpoint(region)` and `getProjectApiEndpoint(projectId)` from `@/lib/appwrite/sdk` for URL construction.

---

## Cursor Cloud specific instructions

- **Runtime/package manager**: This project uses **Bun** (not npm/pnpm, even though a `pnpm-lock.yaml` exists). Use `bun run <script>` for all scripts in `package.json`. Bun is installed at `~/.bun/bin/bun`; the update script runs `bun install`.
- **No local backend**: There is no local backend server and no `docker-compose`. The console is a client-side app that talks to a **remote backend** whose endpoint is set via the `VITE_*` endpoint variable documented in the `## Environment` section above. Copy `.env` from `.env.example` (`.env` is gitignored). In Cloud Agent VMs, the endpoint, the console fingerprint key, and other `VITE_*` values are injected as secrets and take precedence over the placeholder values in `.env.example`.
- **Standard commands** (see README "Scripts" and `package.json`): `bun run dev` (Vite dev server on port 3000), `bun run lint` (ESLint), `bun run check` (`tsc --noEmit`), `bun run test` (Vitest unit tests), `bun run e2e` (Playwright; needs `bun run install-browsers` first plus a reachable backend and `E2E_TEST_EMAIL`/`E2E_TEST_PASSWORD` or `E2E_TEST_SESSION_SECRET`).
- **Pre-existing lint/type issues**: `bun run lint` and `bun run check` currently report many pre-existing errors in the repo (e.g. unused imports, and config-file type mismatches from the `rolldown-vite` alias in `vite.config.ts`/`vitest.config.ts`). These are not caused by environment setup; do not treat them as setup failures.
- **Login for manual testing**: To exercise authenticated flows, log into the dev server (`http://localhost:3000/sign-in`) with the injected `E2E_TEST_EMAIL` / `E2E_TEST_PASSWORD` secrets. The account's project creation may be blocked by org permissions/plan limits on some orgs; project-scoped write actions (e.g. creating an Auth user, storage bucket, or database inside an existing project) work for hello-world verification.
- **Vite alias**: `vite` is aliased to `npm:rolldown-vite` (Rolldown), so dev/build logs mention `ROLLDOWN-VITE`; this is expected.
- **`remotion/` subfolder** is an independent package (launch video) with its own deps and no lockfile; it is not needed to run or test the console.
