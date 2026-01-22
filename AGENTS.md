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
- **Naming**: Main views = `View.tsx`, dialogs = `CreateResource.tsx` (no "Dialog" suffix), detail views in `$resourceId/View.tsx`

**Import patterns:**

```typescript
// Global
import { DateTooltip } from '@/components/global/shared/DateTooltip'

// Page components
import { StorageView } from '@/components/pages/projects/$projectId/storage/View'

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
const [currentPage, setCurrentPage] = useState(1) // 1-indexed
const [pageSize, setPageSize] = useState(25)
const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
const [selectedItems, setSelectedItems] = useState<Set<string>>(new Set())

// Convert for API
const pageIndexed = currentPage - 1
```

---

## UX Guidelines

### Button Behavior

- **Never change button text during actions** - Keep text consistent, use `disabled` state
- **Use "Update" not "Edit"** - Align with API terminology

### Loading & Navigation

- **No individual loaders** - Rely on fullscreen loader for initial load
- **Stay on current page** - Until next page is ready to prevent layout shifts
- **Prefetch crucial data** - All critical API calls at route level

### Form Behavior

- **Reset after submission** - All creation forms reset and close dialog on success
- **Row ID input** - Show `IdInput` only during creation, optional custom ID or auto-generate

### Visual Design

- **Service avatars**: Always neutral (`bg-muted text-muted-foreground`), never colored
- **Button icon spacing**: Use `mr-1.5` or `gap-1.5`, not `mr-2` or larger
- **Date tooltips**: Always include when showing dates for timezone clarity

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
- `fetchQuery`: Always fetches (ignores cache)
- `ensureQueryData`: Uses cache if fresh, fetches if stale/missing
- Use for: Critical data that must be available before rendering
- **Always await** these calls to ensure navigation blocks until data is ready

**prefetchQuery** (use for optional data):

- **Does NOT block** navigation
- Fetches in background, doesn't wait
- Use for: Optional data, supporting data, non-critical prefetching

**Pattern:**

```typescript
// Route with loader for all critical data
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

    // Page-specific critical data - blocks navigation until ready
    await queryClient.fetchQuery({
      queryKey: ['buckets', 'project', projectId, 0, 25, ''],
      queryFn: () => fetchProjectBuckets(projectId, 0, 25, ''),
      staleTime: 30 * 1000,
    })

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
│   │   └── shared/    # Project-specific shared
│   └── organizations/$orgId/
│
└── ui/                 # ShadCN (do not modify)
```

### Naming Conventions

- **Main views**: `View.tsx` (e.g., `storage/View.tsx`)
- **Detail views**: `$resourceId/View.tsx` (e.g., `storage/$bucketId/View.tsx`)
- **Dialogs**: `CreateResource.tsx` (no "Dialog" suffix)
- **Tabs**: `Settings.tsx`, `Security.tsx` (no "View" suffix)
- **Other**: Descriptive names (`BucketSettings.tsx`, `FileSecurity.tsx`)

### Folder-Route Alignment

- Route: `/projects/$projectId/storage` → `pages/projects/$projectId/storage/`
- Route: `/projects/$projectId/storage/$bucketId` → `pages/projects/$projectId/storage/$bucketId/`
- Route: `/projects/$projectId/storage/$bucketId/files/$fileId` → `pages/projects/$projectId/storage/files/$fileId/`

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

| Task                 | Pattern                                            |
| -------------------- | -------------------------------------------------- |
| Fetch data           | Extract query function, use in hook + route loader |
| Create resource      | Form resets and closes dialog on success           |
| Update resource      | Use "Update" terminology, not "Edit"               |
| Button during action | Keep text, use `disabled` state                    |
| Service avatar       | `bg-muted text-muted-foreground` (never colored)   |
| Icon spacing         | `mr-1.5` or `gap-1.5`                              |
| Date display         | Always include DateTooltip                         |
| Route prefetch       | All crucial data at route level                    |
| Models types         | Always `Models.*` from `@appwrite.io/console`      |

---

## Environment

Set `VITE_APPWRITE_ENDPOINT` in `.env` (default: `https://cloud.appwrite.io/v1`).
