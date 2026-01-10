We use TanStack Start with Tailwind and ShadCN, always prefer using our current stack and theme before adding new libraries.

## Appwrite Client SDK

**IMPORTANT: Client-side only!** Use `src/lib/appwrite/sdk.ts` for all Appwrite operations. All operations are handled client-side.

### SDK Usage

**Console SDK** - For console-level resources (projects, account, organizations):
```typescript
import { sdk } from '@/lib/appwrite/sdk';

// List projects
const projects = await sdk.forConsole.projects.list();

// Get current account
const account = await sdk.forConsole.account.get();
```

**Project SDK** - For project-specific resources (databases, functions, storage):
```typescript
import { sdk } from '@/lib/appwrite/sdk';

// Get project SDK instance
const projectSdk = sdk.forProject('project-id');

// List databases, functions, storage buckets
const databases = await projectSdk.tablesDB.list();
const functions = await projectSdk.functions.list();
const buckets = await projectSdk.storage.listBuckets();
```

### SDK Service References

When you need to access SDK methods, refer to these service files to find the exact method signatures and parameters:

**Console SDK Services** (via `sdk.forConsole`):
- `account` - User account management, authentication, sessions, MFA, billing addresses, payment methods
  - Reference: `node_modules/@appwrite.io/console/src/services/account.ts`
- `assistant` - AI assistant chat functionality
  - Reference: `node_modules/@appwrite.io/console/src/services/assistant.ts`
- `avatars` - Avatar generation (browser, credit card, flag, initials, QR codes, screenshots)
  - Reference: `node_modules/@appwrite.io/console/src/services/avatars.ts`
- `backups` - Backup archives, policies, and restorations
  - Reference: `node_modules/@appwrite.io/console/src/services/backups.ts`
- `console` - Console-level operations (campaigns, coupons, plans, regions, resources, sources, suggestions)
  - Reference: `node_modules/@appwrite.io/console/src/services/console.ts`
- `domains` - Domain management, DNS records, presets (Google Workspace, iCloud, Mailgun, Outlook, ProtonMail, Zoho)
  - Reference: `node_modules/@appwrite.io/console/src/services/domains.ts`
- `organizations` - Organization management, billing, credits, invoices, payment methods, plans, usage
  - Reference: `node_modules/@appwrite.io/console/src/services/organizations.ts`
- `projects` - Project management, API keys, platforms, webhooks, email/SMS templates, OAuth2, SMTP
  - Reference: `node_modules/@appwrite.io/console/src/services/projects.ts`
- `teams` - Team management, memberships, preferences
  - Reference: `node_modules/@appwrite.io/console/src/services/teams.ts`
- `vcs` - Version control system integrations (repositories, installations, branches, deployments)
  - Reference: `node_modules/@appwrite.io/console/src/services/vcs.ts`

**Project SDK Services** (via `sdk.forProject(projectId)`):
- `databases` - Database management, collections, documents, attributes, indexes (legacy document-based)
  - Reference: `node_modules/@appwrite.io/console/src/services/databases.ts`
- `tablesDB` - Table-based database management, tables, columns, rows, indexes (new table-based)
  - Reference: `node_modules/@appwrite.io/console/src/services/tables-db.ts`
- `functions` - Function management, deployments, executions, variables, runtimes, templates
  - Reference: `node_modules/@appwrite.io/console/src/services/functions.ts`
- `storage` - Storage bucket and file management, file previews/downloads
  - Reference: `node_modules/@appwrite.io/console/src/services/storage.ts`
- `sites` - Site management, deployments, logs, variables, frameworks, templates
  - Reference: `node_modules/@appwrite.io/console/src/services/sites.ts`
- `users` - User management, identities, sessions, MFA, targets, tokens, verification
  - Reference: `node_modules/@appwrite.io/console/src/services/users.ts`
- `messaging` - Messaging system (email, push, SMS), providers, topics, subscribers, targets
  - Reference: `node_modules/@appwrite.io/console/src/services/messaging.ts`
- `migrations` - Data migrations (Appwrite, Firebase, Supabase, NHost, CSV)
  - Reference: `node_modules/@appwrite.io/console/src/services/migrations.ts`
- `proxy` - Proxy rules (API, function, redirect, site rules)
  - Reference: `node_modules/@appwrite.io/console/src/services/proxy.ts`
- `tokens` - Resource token management for file access
  - Reference: `node_modules/@appwrite.io/console/src/services/tokens.ts`
- `graphql` - GraphQL query and mutation support
  - Reference: `node_modules/@appwrite.io/console/src/services/graphql.ts`
- `realtime` - Real-time subscriptions and WebSocket connections
  - Reference: `node_modules/@appwrite.io/console/src/services/realtime.ts`
- `health` - Health checks for various services and queues
  - Reference: `node_modules/@appwrite.io/console/src/services/health.ts`
- `locale` - Locale information (codes, countries, currencies, languages, phones)
  - Reference: `node_modules/@appwrite.io/console/src/services/locale.ts`
- `project` - Project-specific operations (usage, variables, Imagine usage)
  - Reference: `node_modules/@appwrite.io/console/src/services/project.ts`

**Usage Tips:**
- Always check the service file for exact method signatures and parameter types
- Methods often support both object parameter style and positional arguments
- Most list methods support `queries`, `search`, and `total` parameters
- Create/update methods typically require specific IDs and data objects
- Check return types in the service files to understand what data structure you'll receive

### SDK Response Models

**IMPORTANT: Always use the official SDK response models from `@appwrite.io/console`. Never guess the structure of API responses.**

All SDK methods return typed responses defined in `node_modules/@appwrite.io/console/src/models.ts`. These models are exported from the `Models` namespace and provide complete TypeScript type definitions for all API responses.

**Importing Models:**
```typescript
import type { Models } from '@appwrite.io/console'
```

**Common Model Types:**

1. **List Responses** - All `list()` methods return a `*List` type:
   - `Models.DatabaseList` - `{ total: number, databases: Database[] }`
   - `Models.TableList` - `{ total: number, tables: Table[] }`
   - `Models.UserList` - `{ total: number, users: User[] }`
   - `Models.RowList` - `{ total: number, rows: Row[] }`
   - `Models.FunctionList` - `{ total: number, functions: Function[] }`
   - `Models.BucketList` - `{ total: number, buckets: Bucket[] }`
   - `Models.InvoiceList` - `{ total: number, invoices: Invoice[] }`
   - And many more - check `models.ts` for the complete list

2. **Individual Resource Models** - All `get()`, `create()`, `update()` methods return individual model types:
   - `Models.Database` - Database resource
   - `Models.Table` - Table resource
   - `Models.User` - User resource
   - `Models.Row` - Row resource
   - `Models.Function` - Function resource
   - `Models.Invoice` - Invoice resource
   - And many more - check `models.ts` for the complete list

**Usage Examples:**

```typescript
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

// ✅ CORRECT: Type the response using Models
async function fetchDatabases(projectId: string): Promise<Models.DatabaseList> {
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.tablesDB.list()
  return response // TypeScript knows this is Models.DatabaseList
}

// ✅ CORRECT: Type individual resources
function mapInvoice(apiInvoice: Models.Invoice): Invoice {
  return {
    $id: apiInvoice.$id,
    invoiceNumber: `INV-${apiInvoice.$id.slice(-8)}`,
    amount: apiInvoice.amount,
    status: apiInvoice.status,
    // ... map other fields
  }
}

// ✅ CORRECT: Use Models in function parameters
export async function fetchProjectUsers(
  projectId: string,
): Promise<Models.UserList> {
  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.list()
}

// ✅ CORRECT: Extend Models for custom types
import type { Models } from '@appwrite.io/console'

export type Activities = Models.Row & {
  createdBy: string
  userId: string
  action: string
  // ... additional fields
}
```

**Finding the Right Model:**

1. **Check the service file return type** - Service files in `node_modules/@appwrite.io/console/src/services/` show the return type for each method
2. **Search `models.ts`** - Use your IDE to search for the model name in `node_modules/@appwrite.io/console/src/models.ts`
3. **Common patterns:**
   - List methods return `Models.*List` (e.g., `Models.DatabaseList`)
   - Get methods return individual models (e.g., `Models.Database`)
   - Create/Update methods return the created/updated resource (e.g., `Models.Database`)

**❌ WRONG: Guessing response structure**

```typescript
// ❌ DON'T: Guess the response structure
const response = await projectSdk.tablesDB.list()
const databases = response.data // ❌ Wrong - it's response.databases
const total = response.count // ❌ Wrong - it's response.total

// ❌ DON'T: Use `any` or untyped responses
async function fetchDatabases(projectId: string) {
  const response: any = await projectSdk.tablesDB.list() // ❌ No type safety
  return response
}

// ❌ DON'T: Create custom interfaces that duplicate Models
interface Database { // ❌ Use Models.Database instead
  id: string
  name: string
}
```

**✅ CORRECT: Use Models types**

```typescript
// ✅ DO: Use Models types from the SDK
import type { Models } from '@appwrite.io/console'

async function fetchDatabases(projectId: string): Promise<Models.DatabaseList> {
  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.tablesDB.list()
  // TypeScript autocomplete will show: response.databases, response.total
  return response
}
```

**Key Benefits:**
- **Type Safety** - TypeScript will catch errors at compile time
- **Autocomplete** - IDE will suggest correct property names
- **Documentation** - Models include JSDoc comments explaining each field
- **Consistency** - All code uses the same type definitions
- **Future-proof** - Models update automatically when SDK updates

**Reference:**
- Full model definitions: `node_modules/@appwrite.io/console/src/models.ts`
- Service method return types: `node_modules/@appwrite.io/console/src/services/*.ts`

### Environment Setup

Set `VITE_APPWRITE_ENDPOINT` in your `.env` file (default: `https://cloud.appwrite.io/v1`).

### Client-Side Authentication

Use the `RequireAuth` component wrapper to protect pages that need authentication. It supports two patterns:

**1. Simple wrapper (most common):**
```typescript
import { RequireAuth } from '@/components/global/auth/RequireAuth'

function MyProtectedPage() {
  return (
    <RequireAuth>
      <MyProtectedContent />
    </RequireAuth>
  )
}
```

**2. Render prop (when you need auth data):**
```typescript
import { RequireAuth } from '@/components/global/auth/RequireAuth'

function MyProtectedPage() {
  return (
    <RequireAuth>
      {({ account, isAuthenticated }) => (
        <div>Welcome {account.name}</div>
      )}
    </RequireAuth>
  )
}
```

**3. Hook (for advanced cases):**
```typescript
import { useAuth } from '@/components/global/auth/RequireAuth'
// or (backward compatible): import { useAuth } from '@/hooks/use-auth'

function MyComponent() {
  const { account, isLoading, isAuthenticated } = useAuth()
  // ... your logic
}
```

The `RequireAuth` component:
- Automatically checks authentication using the Console SDK
- Redirects to `/sign-in` on 401 errors
- Shows loading state while checking auth
- Only renders children if authenticated 

## File Structure

Our codebase uses a **route-aligned organization** where components are organized by their corresponding routes, with clear separation between global and page-specific components.

### Key Principles

1. **Folder as Namespace**: Use folder structure to provide context, enabling shorter file names
   - `ConsoleHeader.tsx` → `global/layout/Header.tsx`
   - `DatabasesView.tsx` → `pages/projects/$projectId/databases/View.tsx`

2. **Route Alignment**: Components are organized to match the application's routing structure
   - Route: `/projects/$projectId/databases` → `components/pages/projects/$projectId/databases/`
   - Route: `/organizations/$orgId/billing` → `components/pages/organizations/$orgId/billing/`

3. **Clear Separation**: 
   - `global/` - Components used across the entire application
   - `pages/` - Components specific to individual pages/routes
   - `ui/` - ShadCN UI components (unchanged)

### Directory Structure

```
src/
├── components/
│   ├── global/                    # Truly global components
│   │   ├── layout/               # Layout components (Header, Sidebar, Footer)
│   │   ├── auth/                 # Authentication components (RequireAuth, LoginForm, etc.)
│   │   ├── shared/               # Shared utilities (DateTooltip, CopyableId, Pagination, etc.)
│   │   └── providers/            # Global context providers (AIChat, DebugMode, etc.)
│   │
│   ├── pages/                     # Page-specific components organized by route
│   │   ├── onboarding/           # Onboarding flow components
│   │   ├── projects/
│   │   │   └── $projectId/       # Project-specific pages
│   │   │       ├── overview/     # Dashboard/Overview page
│   │   │       ├── databases/    # Databases page
│   │   │       ├── auth/         # Auth/Users page
│   │   │       ├── storage/      # Storage page
│   │   │       ├── functions/    # Functions page
│   │   │       ├── analytics/    # Analytics page
│   │   │       ├── activity/     # Activity page
│   │   │       ├── usage/        # Usage page
│   │   │       ├── settings/     # Settings page
│   │   │       ├── imagine/      # Imagine page
│   │   │       └── shared/       # Shared components for project pages
│   │   │
│   │   └── organizations/
│   │       └── $orgId/           # Organization-specific pages
│   │           ├── overview/     # Organization overview
│   │           └── billing/      # Billing page
│   │
│   └── ui/                        # ShadCN UI components (do not modify)
│
├── routes/                        # TanStack Router route files
├── lib/                           # Utilities, hooks, SDK wrappers
└── server/                        # Server-side code
```

### Where to Put New Components

**Global Components** (`components/global/`):
- Used across multiple pages/routes
- Layout components (Header, Sidebar, Footer)
- Authentication components
- Shared utilities (DateTooltip, CopyableId, Pagination, etc.)
- Global context providers

**Page Components** (`components/pages/`):
- Specific to a single page/route
- Organized by route path
- Example: Component for `/projects/$projectId/databases` → `pages/projects/$projectId/databases/`

**Shared Page Components** (`components/pages/.../shared/`):
- Used within a specific route group (e.g., all project pages)
- Example: `ProjectSelector` used across project pages → `pages/projects/$projectId/shared/`

### Import Patterns

**Global Components:**
```typescript
// ✅ CORRECT: Use @/components/global/... for global components
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { useDebugMode } from '@/components/global/providers/DebugMode'
```

**Page Components:**
```typescript
// ✅ CORRECT: Use @/components/pages/... for page components
import { DatabasesView } from '@/components/pages/projects/$projectId/databases/View'
import { ProjectSelector } from '@/components/pages/projects/$projectId/shared/ProjectSelector'
```

**Relative Imports (within same feature):**
```typescript
// ✅ CORRECT: Use relative imports for sibling components in the same feature
// In databases/View.tsx:
import { ColumnFormDialog } from './tables/Column'
import { IndexFormDialog } from './tables/Index'
import { PointEditor } from './tables/spatial'
```

**❌ WRONG:**
```typescript
// ❌ DON'T: Import from old console/auth/onboarding paths
import { DateTooltip } from '@/components/console/DateTooltip'
import { RequireAuth } from '@/components/auth/RequireAuth'

// ❌ DON'T: Use relative imports for global components
import { DateTooltip } from '../shared/DateTooltip'  // If you're in a page component

// ❌ DON'T: Import from wrong relative paths
import { PermissionsEditor } from './PermissionsEditor'  // If it's in a different folder
```

### Naming Conventions

1. **File Names**: Use single-word names when context is clear from folder structure
   - `Header.tsx` (not `ConsoleHeader.tsx`) in `global/layout/`
   - `View.tsx` (not `DatabasesView.tsx`) in `pages/projects/$projectId/databases/`
   - `Column.tsx` (not `ColumnFormDialog.tsx`) in `pages/projects/$projectId/databases/tables/`

2. **Component Names**: Keep descriptive component names in code
   - File: `Header.tsx` → Component: `ConsoleHeader` or `Header` (depending on context)
   - File: `View.tsx` → Component: `DatabasesView` or `View` (depending on context)

3. **Folder Names**: Match route segments
   - Route: `/projects/$projectId/databases` → Folder: `pages/projects/$projectId/databases/`
   - Route: `/organizations/$orgId/billing` → Folder: `pages/organizations/$orgId/billing/`

### Data Files

- **Mock data**: `@/lib/utils/mock-data.ts` (not in components)
- **Page-specific data**: Co-located with page components
  - `pages/projects/$projectId/usage/data.ts`
  - `pages/onboarding/data.ts`

### Quick Reference

| Component Type | Location | Import Pattern |
|---------------|---------|----------------|
| Global layout | `global/layout/` | `@/components/global/layout/Header` |
| Global shared | `global/shared/` | `@/components/global/shared/DateTooltip` |
| Global providers | `global/providers/` | `@/components/global/providers/DebugMode` |
| Global auth | `global/auth/` | `@/components/global/auth/RequireAuth` |
| Project page | `pages/projects/$projectId/[page]/` | `@/components/pages/projects/$projectId/databases/View` |
| Project shared | `pages/projects/$projectId/shared/` | `@/components/pages/projects/$projectId/shared/ProjectSelector` |
| Org page | `pages/organizations/$orgId/[page]/` | `@/components/pages/organizations/$orgId/billing/BillingTab` |
| Sibling component | Same folder | `./ComponentName` |
| Child component | Subfolder | `./subfolder/ComponentName` |

## Formats 

- Always use consistent formats for dates and numbers.
- When presenting a date as part of our project data, always include our date tooltip to help user easily understand the date across timezones.

User experience
- Don't have loaders on individual components rely on the fullscreen loader for the intial load. When navigating between pages use consistent methods to stay on the current page until the next page is ready to be loaded.
- Always make sure we load crucial API calls / data with prefetchQuery on the route level to prevent layout shifts.
- **Never change button text during actions** - Keep button text consistent (e.g., "Save changes", "Submit", "Delete", etc.) and use the `disabled` state to indicate the action is in progress. Changing text (e.g., "Delete" → "Deleting..." or "Save" → "Saving...") causes layout shifts and poor UX. This applies to all buttons including form submissions, delete actions, and modal buttons.
- **Use "Update" instead of "Edit"** - Always use "Update" terminology in UI labels, titles, and button text (e.g., "Update Column", "Update Row", "Update address") as it aligns with our API terminology. Avoid using "Edit" in user-facing text.

## Modal/Dialog Patterns

When creating modals or dialogs with action buttons, follow this consistent pattern:

1. **Header Section**: Use `DialogHeader` with `px-6 pt-6 text-left` padding containing:
   - `DialogTitle` - Use normal text color (avoid red/scary colors)
   - `DialogDescription` - Place the description in the header, above the separator, with `mt-2` class to add spacing between title and description
2. **Full-width Separator**: Add a `border-t border-border` divider below the header
3. **Content Section**: Use `px-6 pb-4 pt-0` padding for the main content (no top padding to minimize spacing below separator)
4. **Footer Section**: 
   - Add a `border-t border-border` separator above the footer
   - Use `px-6 py-4 border-t border-border bg-muted/30` for the footer container
   - Use `flex flex-col-reverse gap-2 sm:flex-row sm:justify-end` for button layout (buttons stack on mobile, align right on desktop)
   - Remove default padding from `DialogContent` by adding `p-0` class

**Example (Best Practice):**
```tsx
<DialogContent className="sm:max-w-md p-0">
  <DialogHeader className="px-6 pt-6 text-left">
    <DialogTitle>Modal Title</DialogTitle>
    <DialogDescription className="text-[13px] mt-2">
      Description text goes here, above the separator. This provides context before the user sees the main content.
    </DialogDescription>
  </DialogHeader>
  <div className="border-t border-border" />
  
  <div className="px-6 pb-4 pt-0">
    {/* Main content here */}
  </div>
  
  <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
    <Button variant="outline">Cancel</Button>
    <Button>Confirm</Button>
  </div>
</DialogContent>
```

**Key Points:**
- The `DialogDescription` should be placed in the `DialogHeader` above the separator
- Use normal text colors for titles (avoid red/scary colors unless absolutely necessary)
- This pattern matches the styling used in organization settings cards for visual consistency

**Exception: No Content Modals**
- When a modal has **only** a title and description with no additional content, **do not include a separator below the description**
- Skip the content section entirely and go directly from the header to the footer
- The footer separator should remain to separate the action buttons from the header

**Example (No Content Modal):**
```tsx
<DialogContent className="sm:max-w-md p-0">
  <DialogHeader className="px-6 pt-6 text-left">
    <DialogTitle>Delete Rows</DialogTitle>
    <DialogDescription className="text-[13px] mt-2">
      Are you sure you want to delete 2 rows? This action cannot be undone.
    </DialogDescription>
  </DialogHeader>
  
  {/* No separator or content section - go directly to footer */}
  
  <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
    <Button variant="outline">Cancel</Button>
    <Button variant="destructive">Delete</Button>
  </div>
</DialogContent>
```

## React Query Patterns

### Query Function Extraction Pattern

**IMPORTANT: Always extract query functions from hooks to avoid duplication between hooks and route loaders.**

When creating React Query hooks in `src/lib/react-query/hooks.ts`, follow this pattern:

1. **Extract the query function first** - Create an exported async function that performs the API call
2. **Create the hook** - Use the extracted function in the hook's `queryFn`
3. **Use in route loaders** - Import and use the same function in route loaders for prefetching

**Example:**

```typescript
// ✅ CORRECT: Extract query function
export async function fetchProjectUsers(
  projectId: string,
  page: number = 0,
  limit: number = 25,
  search?: string,
) {
  if (!projectId) {
    return { users: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.users.list(queries, search?.trim() || undefined)

  return {
    users: response.users || [],
    total: response.total || 0,
  }
}

// Hook uses the extracted function
export function useProjectUsers(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = 25,
  search?: string,
) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['users', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectUsers(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: 30 * 1000,
  })
  // ... rest of hook logic
}
```

**In route loaders:**

```typescript
// ✅ CORRECT: Use extracted function in route loader
import { fetchProjectUsers } from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/projects/$projectId/auth')({
  loader: async ({ params, context }) => {
    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      await queryClient.prefetchQuery({
        queryKey: ['users', 'project', projectId, 0, USERS_PER_PAGE, ''],
        queryFn: () => fetchProjectUsers(projectId, 0, USERS_PER_PAGE, ''),
        staleTime: 30 * 1000,
      })
    }
  },
})
```

**❌ WRONG: Duplicating query logic**

```typescript
// ❌ DON'T: Duplicate the API call logic in route loader
export const Route = createFileRoute('/_public/projects/$projectId/auth')({
  loader: async ({ params, context }) => {
    // ❌ This duplicates the logic from useProjectUsers hook
    await queryClient.prefetchQuery({
      queryKey: ['users', 'project', projectId, 0, USERS_PER_PAGE, ''],
      queryFn: async () => {
        const projectSdk = sdk.forProject(projectId)
        const queries = [Query.orderDesc('$createdAt'), ...]
        const response = await projectSdk.users.list(queries)
        return { users: response.users || [], total: response.total || 0 }
      },
    })
  },
})
```

### Benefits of This Pattern

- **Single source of truth** - API call logic exists in one place
- **No duplication** - Route loaders and hooks share the same implementation
- **Easier maintenance** - Change sorting, filtering, or API calls in one place
- **Consistent behavior** - Hooks and route loaders always use the same query logic
- **Type safety** - Shared functions ensure consistent return types

### When to Extract Query Functions

Extract query functions for:
- ✅ All hooks that make API calls
- ✅ Any query that might be prefetched in route loaders
- ✅ Queries that are used in multiple places

**Note:** The SDK version we use has all required methods (like `.get()`, `.list()`, etc.), so no fallback logic is needed. Keep query functions simple and direct.

### Route-Level Prefetching

**IMPORTANT: All crucial HTTP calls using the SDK must be prefetched at the route level to prevent layout shifts and improve user experience.**

Route loaders run before the component renders, allowing data to be available immediately when the page loads. This eliminates loading spinners on initial render and prevents content from jumping around.

**What to Prefetch:**
- ✅ Initial page of list data (first page, no search)
- ✅ Total count for limit checking
- ✅ Project data (if needed for plan limits)
- ✅ Organization plan (if needed for limit checking)
- ✅ Detail page data (when viewing a specific resource)
- ✅ Related data needed for the initial view

**What NOT to Prefetch:**
- ❌ Search results (user hasn't searched yet)
- ❌ Filtered data (user hasn't applied filters yet)
- ❌ Subsequent pages (only prefetch page 1)
- ❌ Optional/conditional data that may not be needed

**Example Route File Structure:**

```typescript
// src/routes/_public/projects/$projectId/storage/index.tsx
import { createFileRoute } from '@tanstack/react-router'
import { StorageView } from '@/components/pages/projects/$projectId/storage/View'
import { fetchProjectBuckets, fetchProject, fetchOrganizationPlan } from '@/lib/react-query/hooks'

const BUCKETS_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/storage/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // 1. Prefetch project to get teamId (needed for plan limits)
      const projectData = await queryClient.fetchQuery({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })

      // 2. Prefetch organization plan if we have a teamId (for limit checking)
      if (projectData?.teamId) {
        await queryClient.prefetchQuery({
          queryKey: ['organization', 'plan', projectData.teamId],
          queryFn: () => fetchOrganizationPlan(projectData.teamId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        })
      }

      // 3. Prefetch initial page of resources (page 0, no search)
      await queryClient.ensureQueryData({
        queryKey: ['buckets', 'project', projectId, 0, BUCKETS_PER_PAGE, ''],
        queryFn: () => fetchProjectBuckets(projectId, 0, BUCKETS_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })

      // 4. Prefetch total count for limit checking (separate from search query)
      await queryClient.prefetchQuery({
        queryKey: ['buckets', 'project', projectId, 'total'],
        queryFn: () => fetchProjectBuckets(projectId, 0, 1, ''),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: StorageIndexPage,
})

function StorageIndexPage() {
  return <StorageView />
}
```

**Key Points:**
- Use `ensureQueryData` for data that must be available (will wait for it)
- Use `prefetchQuery` for data that's nice to have (won't block rendering)
- Use `fetchQuery` when you need the result to make decisions (like getting teamId)
- Always check `typeof window === 'undefined'` since SDK is client-side only
- Wrap in try-catch for non-critical prefetches to avoid blocking navigation
- Use consistent query keys matching your hooks

**Error Handling:**
```typescript
// For critical data - let it fail and show error boundary
await queryClient.ensureQueryData({
  queryKey: ['resource', projectId, resourceId],
  queryFn: () => fetchResource(projectId, resourceId),
  staleTime: 30 * 1000,
})

// For non-critical data - catch errors to avoid blocking navigation
try {
  await queryClient.prefetchQuery({
    queryKey: ['optional-data', projectId],
    queryFn: () => fetchOptionalData(projectId),
    staleTime: 30 * 1000,
  })
} catch (error) {
  // Log error but don't block rendering - let component handle error state
  console.error('Error prefetching optional data:', error)
}
```

**Benefits:**
- **No layout shifts** - Data is ready when component renders
- **Faster perceived load** - Users see content immediately
- **Better UX** - No loading spinners on initial render
- **Consistent behavior** - All routes follow the same pattern

## Row Creation

When creating rows in tables, the ID input component (`IdInput`) is shown in the creation form (not during updates). Users can optionally specify a custom Row ID, or leave it blank to auto-generate using `ID.unique()` from the Appwrite SDK. The ID input is only visible during row creation, never during row updates.

## Form Reset After Submission

**IMPORTANT: All creation forms must reset after successful submission and close the dialog.**

After a successful creation:
1. **Reset all form fields** to their initial/empty state
2. **Close the dialog/form** automatically after successful server response
3. **Show success feedback** via toast notification

**Implementation pattern:**
- Reset form state when the dialog closes (in `handleOpenChange` when `newOpen` is false)
- Close the dialog in the mutation's `onSuccess` callback
- Form will automatically reset when the dialog closes, preparing it for the next use

## UI Design Guidelines

### Service/Resource Avatars

**Never use colors for service/resource avatars or icons.** Always use neutral muted styling:
- Use `bg-muted` for icon backgrounds
- Use `text-muted-foreground` for icon colors
- Avoid colored backgrounds like `bg-violet-500/10` or colored text like `text-violet-600`

This ensures consistency across the interface and prevents visual clutter from arbitrary color choices.

### Button Icon Spacing

**Use tight spacing between icons and labels in buttons.** Avoid excessive spacing that creates visual gaps:
- Use `mr-1.5` (6px) for icon margin-right, not `mr-2` (8px) or larger
- Alternatively, use `gap-1.5` when using flex containers for button content
- This creates a more cohesive visual connection between the icon and label

**Example:**
```tsx
// ✅ CORRECT: Tight spacing
<Button>
  <Icon className="mr-1.5 h-4 w-4" />
  Label
</Button>

// ✅ CORRECT: Using flex gap
<Button className="flex items-center gap-1.5">
  <Icon className="h-4 w-4" />
  Label
</Button>

// ❌ WRONG: Too much spacing
<Button>
  <Icon className="mr-2 h-4 w-4" />
  Label
</Button>
```

## Standard Service Section/View Patterns

When creating a new service section/view (like Auth, Functions, Storage), follow these consistent patterns to ensure a uniform user experience across the application.

### Component Structure

**1. Main View Component** (`View.tsx`):
- Located in `components/pages/projects/$projectId/[service-name]/View.tsx`
- Uses `ServiceHeader` component for consistent header/tabs/toolbar
- Manages state for search, pagination, view modes (list/grid), and selections
- Handles tab navigation via URL pathname (route-based tabs)
- Renders tab-specific content conditionally

**2. Shared Components**:
- `ServiceHeader` - Standardized header with title, tabs, search, filters, and create button
- `ResourceCard` - Consistent card display for grid view
- `EmptyState` - Standardized empty states
- `Pagination` - Consistent pagination component

### Key Patterns

**1. Tab Navigation (Route-Based)**:
```typescript
// Derive active tab from pathname
const activeTab = useMemo(() => {
  const pathParts = location.pathname.split('/').filter(Boolean)
  const serviceIndex = pathParts.findIndex(part => part === 'service-name')
  
  if (serviceIndex >= 0) {
    if (pathParts[serviceIndex + 1]) {
      const tabFromPath = pathParts[serviceIndex + 1]
      if (['tab1', 'tab2', 'tab3'].includes(tabFromPath)) {
        return tabFromPath
      }
    }
  }
  
  // Default to main tab for index route
  return 'main-tab'
}, [location.pathname])

// Define tabs with route paths
const tabs: Tab[] = useMemo(() => [
  { 
    id: 'main-tab', 
    label: 'Main Tab', 
    count: totalCount,
    to: '/projects/$projectId/service-name/',
    params: { projectId: projectId as string },
  },
  { 
    id: 'other-tab', 
    label: 'Other Tab',
    to: '/projects/$projectId/service-name/other-tab',
    params: { projectId: projectId as string },
  },
], [totalCount, projectId])
```

**2. ServiceHeader Usage**:
```typescript
<ServiceHeader
  title="Service Name"
  tabs={tabs}
  activeTab={activeTab}
  searchPlaceholder={activeTab === 'main-tab' ? 'Search resources...' : undefined}
  searchValue={activeTab === 'main-tab' ? searchValue : undefined}
  onSearchChange={activeTab === 'main-tab' ? handleSearchChange : undefined}
  createLabel={getCreateLabel()}
  onCreate={handleCreateClick}
  createDisabled={isCreateDisabled}
  showFilters={activeTab === 'main-tab'} // Optional
  fullWidthBorder
  rightContent={activeTab === 'main-tab' ? <ViewToggle /> : undefined}
  contentAfterBorder={
    activeTab === 'main-tab' ? (
      <PlanLimitWarning
        currentCount={totalCount}
        limit={resourceLimit}
        planName={organizationPlan?.name}
        resourceName="resources"
        orgId={project?.teamId}
        isLoading={isLoading}
      />
    ) : undefined
  }
/>
```

**3. State Management**:
```typescript
// Search state
const [searchValue, setSearchValue] = useState('')

// Pagination state (1-indexed for UI)
const [currentPage, setCurrentPage] = useState(1)
const [pageSize, setPageSize] = useState(25)

// View mode (list/grid)
const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')

// Selection state for bulk operations
const [selectedItems, setSelectedItems] = useState<Set<string>>(new Set())

// Dialog states
const [createDialogOpen, setCreateDialogOpen] = useState(false)
const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

// Clear selection when navigating or searching
useEffect(() => {
  setSelectedItems(new Set())
  setDeleteDialogOpen(false)
}, [location.pathname, projectId, searchValue])
```

**4. Data Fetching**:
```typescript
// Convert 1-indexed page to 0-indexed for API
const pageIndexed = currentPage - 1

// Fetch resources
const {
  resources,
  total,
  isLoading,
} = useProjectResources(projectId, pageIndexed, pageSize, searchValue)

// Fetch total count without search (for limit checking)
const {
  data: totalResourcesData,
  isLoading: totalResourcesLoading,
} = useQuery({
  queryKey: ['resources', 'project', projectId, 'total'],
  queryFn: () => fetchProjectResources(projectId!, 0, 1, ''),
  enabled: !!projectId,
  staleTime: 30 * 1000,
})

const totalResourcesCount = totalResourcesData?.total || 0
```

**5. Plan Limit Checking**:
```typescript
// Get project to get teamId for organization plan
const { project, isLoading: projectLoading } = useProject(projectId)

// Get organization plan to check limits
const { plan: organizationPlan, isLoading: planLoading } = useOrganizationPlan(project?.teamId)

// Check if create button should be disabled
const resourcesLimit = organizationPlan?.resources ?? 0
const isCreateDisabled = resourcesLimit > 0 && totalResourcesCount >= resourcesLimit
```

**6. List View Pattern**:
```typescript
{viewMode === 'list' ? (
  isLoading ? (
    <div className="rounded-lg border border-border bg-card py-12 text-center">
      <p className="text-[13px] text-muted-foreground">Loading resources...</p>
    </div>
  ) : paginatedResources.length > 0 ? (
    <>
      <div className="rounded-lg border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead className="w-[40px]">
                <Checkbox
                  checked={
                    paginatedResources.length > 0 &&
                    selectedItems.size === paginatedResources.length
                  }
                  onCheckedChange={toggleAllItems}
                />
              </TableHead>
              <TableHead className="w-[180px]">Resource ID</TableHead>
              <TableHead className="w-[200px]">Name</TableHead>
              <TableHead className="w-[120px]">Created</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {paginatedResources.map((resource) => (
              <TableRow
                key={resource.$id}
                className={cn(
                  'cursor-pointer transition-colors',
                  selectedItems.has(resource.$id)
                    ? 'bg-sky-100 dark:bg-sky-950'
                    : 'hover:bg-muted/50',
                )}
              >
                <TableCell onClick={(e) => e.stopPropagation()}>
                  <Checkbox
                    checked={selectedItems.has(resource.$id)}
                    onCheckedChange={() => toggleItem(resource.$id)}
                  />
                </TableCell>
                <TableCell>
                  <Link
                    to="/projects/$projectId/service-name/$resourceId"
                    params={{ projectId: projectId!, resourceId: resource.$id }}
                    className="block"
                  >
                    <CopyableId id={resource.$id} size="xs" />
                  </Link>
                </TableCell>
                {/* More cells... */}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <Pagination
        currentPage={currentPage}
        totalItems={total}
        pageSize={pageSize}
        pageSizeOptions={[10, 25, 50, 100]}
        onPageChange={handlePageChange}
        onPageSizeChange={handlePageSizeChange}
        itemLabel="resources"
      />
    </>
  ) : (
    <EmptyState
      icon={Icon}
      title="No resources found"
      description="Try adjusting your search"
      isEmpty={!searchValue}
      hasFilters={!!searchValue}
      variant="card"
    />
  )
) : (
  // Grid view...
)}
```

**7. Grid View Pattern**:
```typescript
{viewMode === 'grid' ? (
  isLoading ? (
    <div className="rounded-lg border border-border bg-card py-12 text-center">
      <p className="text-[13px] text-muted-foreground">Loading resources...</p>
    </div>
  ) : paginatedResources.length > 0 ? (
    <>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {paginatedResources.map((resource) => (
          <Link
            key={resource.$id}
            to="/projects/$projectId/service-name/$resourceId"
            params={{ projectId, resourceId: resource.$id }}
          >
            <ResourceCard
              title={resource.name}
              resourceId={resource.$id}
              icon={Icon}
              iconColor="bg-muted text-muted-foreground"
              status={resource.enabled === false ? 'error' : undefined}
              statusLabel={resource.enabled === false ? 'Disabled' : undefined}
              metadata={[
                {
                  label: 'Created',
                  value: (
                    <DateTooltip
                      date={resource.$createdAt}
                      className="text-[11px] font-medium text-muted-foreground"
                    />
                  ),
                },
              ]}
            />
          </Link>
        ))}
      </div>
      {!isLoading && paginatedResources.length > 0 && (
        <Pagination
          currentPage={currentPage}
          totalItems={total}
          pageSize={pageSize}
          pageSizeOptions={[10, 25, 50, 100]}
          onPageChange={setCurrentPage}
          onPageSizeChange={(size) => {
            setPageSize(size)
            setCurrentPage(1)
          }}
          itemLabel="resources"
        />
      )}
    </>
  ) : (
    <EmptyState
      icon={Icon}
      title="No resources found"
      description="Create your first resource"
      isEmpty={!searchValue}
      hasFilters={!!searchValue}
      variant="card"
    />
  )
) : null}
```

**8. View Toggle Component**:
```typescript
const ViewToggle = () => (
  <div className="flex items-center gap-1 rounded-md border border-border bg-muted/30 p-0.5">
    <Button
      variant="ghost"
      size="sm"
      className={cn(
        'h-7 w-7 p-0',
        viewMode === 'list'
          ? 'bg-background shadow-sm'
          : 'hover:bg-transparent',
      )}
      onClick={() => setViewMode('list')}
    >
      <List className="h-4 w-4" />
    </Button>
    <Button
      variant="ghost"
      size="sm"
      className={cn(
        'h-7 w-7 p-0',
        viewMode === 'grid'
          ? 'bg-background shadow-sm'
          : 'hover:bg-transparent',
      )}
      onClick={() => setViewMode('grid')}
    >
      <LayoutGrid className="h-4 w-4" />
    </Button>
  </div>
)
```

**9. Bulk Delete Pattern**:
```typescript
// Bulk delete mutation
const bulkDeleteMutation = useMutation({
  mutationFn: async (itemIds: string[]) => {
    if (!projectId) {
      throw new Error('Project ID is required')
    }
    await Promise.all(
      itemIds.map((itemId) => deleteResource(projectId, itemId)),
    )
  },
  onSuccess: () => {
    queryClient.invalidateQueries({
      queryKey: ['resources', 'project', projectId],
    })
    toast.success(
      `Successfully deleted ${selectedItems.size} resource${selectedItems.size > 1 ? 's' : ''}`,
    )
    setSelectedItems(new Set())
    setDeleteDialogOpen(false)
  },
  onError: (error: Error) => {
    toast.error(error.message || 'Failed to delete resources')
  },
})

// Bulk Delete Action Bar
{selectedItems.size > 0 && (
  <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
    <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3 shadow-lg">
      <Badge variant="secondary" className="h-6 px-2.5">
        {selectedItems.size} resource{selectedItems.size > 1 ? 's' : ''} selected
      </Badge>
      <div className="flex items-center gap-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setSelectedItems(new Set())}
          className="h-8 text-xs"
        >
          Cancel
        </Button>
        <Button
          variant="destructive"
          size="sm"
          onClick={handleBulkDelete}
          disabled={bulkDeleteMutation.isPending}
          className="h-8 gap-2"
        >
          Delete
        </Button>
      </div>
    </div>
  </div>
)}

// Bulk Delete Confirmation Dialog
<Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
  <DialogContent className="sm:max-w-md p-0">
    <DialogHeader className="px-6 pt-6 text-left">
      <DialogTitle>Delete Resources</DialogTitle>
      <DialogDescription className="text-[13px] mt-2">
        Are you sure you want to delete {selectedItems.size} resource{selectedItems.size > 1 ? 's' : ''}? This action cannot be undone.
      </DialogDescription>
    </DialogHeader>
    
    <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
      <Button
        variant="outline"
        onClick={() => setDeleteDialogOpen(false)}
        disabled={bulkDeleteMutation.isPending}
      >
        Cancel
      </Button>
      <Button
        variant="destructive"
        onClick={confirmBulkDelete}
        disabled={bulkDeleteMutation.isPending}
      >
        Delete
      </Button>
    </div>
  </DialogContent>
</Dialog>
```

**10. Content Container**:
```typescript
<div className={cn(
  "mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6",
  (activeTab === 'settings' || activeTab === 'other-non-list-tab') && "pt-4 sm:pt-6"
)}>
  {/* Tab-specific content */}
</div>
```

**11. Complete Page Structure Example**:

To avoid inconsistent nesting of components or elements, follow this standard structure:

```typescript
// src/components/pages/projects/$projectId/storage/View.tsx
export function StorageView() {
  const { projectId } = useParams({ strict: false })
  const location = useLocation()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [selectedItems, setSelectedItems] = useState<Set<string>>(new Set())
  const [createDialogOpen, setCreateDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // ... state management, data fetching, handlers ...

  return (
    <div className="flex h-full flex-col">
      {/* ServiceHeader - Always at the top */}
      <ServiceHeader
        title="Storage"
        searchPlaceholder="Search buckets..."
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel="Create bucket"
        onCreate={() => setCreateDialogOpen(true)}
        createDisabled={isCreateDisabled}
        fullWidthBorder
        rightContent={<ViewToggle />}
        contentAfterBorder={
          <PlanLimitWarning
            currentCount={totalBucketsCount}
            limit={bucketsLimit}
            planName={organizationPlan?.name}
            resourceName="buckets"
            orgId={project?.teamId}
            isLoading={projectLoading || planLoading || totalBucketsLoading}
          />
        }
      />

      {/* Content Container - Always use this structure */}
      <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
        {/* Conditional rendering based on view mode */}
        {viewMode === 'list' ? (
          // List view content
          isLoading ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">Loading buckets...</p>
            </div>
          ) : paginatedBuckets.length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card">
                <Table>
                  {/* Table content */}
                </Table>
              </div>
              <Pagination
                currentPage={currentPage}
                totalItems={bucketsTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="buckets"
              />
            </>
          ) : (
            <EmptyState
              icon={HardDrive}
              title="No buckets found"
              description="Create your first bucket to start storing files"
              isEmpty={!searchValue}
              hasFilters={!!searchValue}
              variant="card"
            />
          )
        ) : (
          // Grid view content
          <>
            {isLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">Loading buckets...</p>
              </div>
            ) : paginatedBuckets.length > 0 ? (
              <>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {paginatedBuckets.map((bucket) => (
                    <Link
                      key={bucket.$id}
                      to="/projects/$projectId/storage/$bucketId/"
                      params={{ projectId, bucketId: bucket.$id }}
                    >
                      <ResourceCard
                        title={bucket.name}
                        resourceId={bucket.$id}
                        icon={HardDrive}
                        iconColor="bg-muted text-muted-foreground"
                        metadata={[...]}
                      />
                    </Link>
                  ))}
                </div>
                {!isLoading && paginatedBuckets.length > 0 && (
                  <Pagination
                    currentPage={currentPage}
                    totalItems={bucketsTotal}
                    pageSize={pageSize}
                    pageSizeOptions={[10, 25, 50, 100]}
                    onPageChange={setCurrentPage}
                    onPageSizeChange={(size) => {
                      setPageSize(size)
                      setCurrentPage(1)
                    }}
                    itemLabel="buckets"
                  />
                )}
              </>
            ) : (
              <EmptyState
                icon={HardDrive}
                title="No buckets found"
                description="Create your first bucket to start storing files"
                isEmpty={!searchValue}
                hasFilters={!!searchValue}
                variant="card"
              />
            )}
          </>
        )}

        {/* Bulk Delete Action Bar - Fixed position at bottom */}
        {selectedItems.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3 shadow-lg">
              {/* Action bar content */}
            </div>
          </div>
        )}

        {/* Bulk Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          {/* Dialog content */}
        </Dialog>
      </div>

      {/* Create Dialog - Outside content container */}
      <CreateBucketDialog
        open={createDialogOpen}
        onOpenChange={setCreateBucketDialogOpen}
        onCreate={(data) => createBucketMutation.mutate(data)}
        isLoading={createBucketMutation.isPending}
      />
    </div>
  )
}
```

**Key Structure Rules:**

1. **Outer Container**: Always use `<div className="flex h-full flex-col">` as the root
2. **ServiceHeader**: Always at the top, outside content container
3. **Content Container**: Use consistent padding and max-width:
   ```typescript
   <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
   ```
4. **Conditional Rendering**: Use ternary operators for loading/empty/data states
5. **Dialogs**: Place at the end, outside the content container
6. **Fixed Elements**: Action bars use `fixed` positioning with proper z-index
7. **Nesting**: Keep nesting consistent - don't add extra wrapper divs
8. **Spacing**: Use consistent gap classes (`gap-3` for grids, `gap-2` for flex items)

**❌ WRONG: Inconsistent Nesting**
```typescript
// ❌ DON'T: Add extra wrapper divs
<div className="flex h-full flex-col">
  <ServiceHeader />
  <div className="container">
    <div className="wrapper">
      <div className="content">
        {/* Content */}
      </div>
    </div>
  </div>
</div>

// ❌ DON'T: Mix different container patterns
<div className="max-w-7xl mx-auto">
  <div className="px-6">
    {/* Inconsistent with other views */}
  </div>
</div>
```

**✅ CORRECT: Standard Structure**
```typescript
// ✅ DO: Use the standard structure
<div className="flex h-full flex-col">
  <ServiceHeader />
  <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
    {/* Content */}
  </div>
  <Dialog />
</div>
```

### Required Features

1. **Route-based tab navigation** - Tabs use Link components with route paths
2. **Search functionality** - Search input in ServiceHeader, clears on tab change
3. **Pagination** - 1-indexed for UI, 0-indexed for API calls
4. **List/Grid view toggle** - Optional, shown in rightContent of ServiceHeader
5. **Bulk selection** - Checkboxes for selecting multiple items
6. **Bulk delete** - Action bar and confirmation dialog
7. **Plan limit warnings** - Show PlanLimitWarning component when applicable
8. **Empty states** - Different states for empty vs. no search results
9. **Loading states** - Show loading message while fetching
10. **Error handling** - Handle errors gracefully with user feedback

### Optional Features

1. **Filters** - Use `showFilters` prop on ServiceHeader
2. **Real-time updates** - Subscribe to realtime events for live updates
3. **Detail routes** - Create detail views for individual resources
4. **Custom alerts** - Use `contentAfterBorder` for service-specific alerts
5. **View mode persistence** - Store view mode preference in localStorage

### File Organization

```
components/pages/projects/$projectId/[service-name]/
├── View.tsx              # Main view component
├── CreateDialog.tsx      # Create resource dialog
├── [tab-name].tsx        # Tab-specific components (if complex)
└── [resource-id]/
    └── View.tsx          # Detail view for individual resource
```

### Example Route Structure

```
/projects/$projectId/service-name/              # Main tab (default)
/projects/$projectId/service-name/other-tab    # Other tab
/projects/$projectId/service-name/$resourceId   # Resource detail
```

### Best Practices

1. **Always use Models types** - Import and use `Models.*` from `@appwrite.io/console`
2. **Extract query functions** - Follow React Query patterns for hooks and route loaders
3. **Prefetch at route level** - All crucial HTTP calls must be prefetched in route loaders to prevent layout shifts
4. **Consistent page structure** - Follow the standard page structure pattern to avoid inconsistent nesting
5. **Consistent spacing** - Use the same padding/margin patterns as other service views
6. **Accessible** - Ensure proper ARIA labels and keyboard navigation
7. **Responsive** - Test on mobile and desktop viewports
8. **Performance** - Use pagination, avoid loading all resources at once
9. **Error boundaries** - Handle API errors gracefully with user-friendly messages