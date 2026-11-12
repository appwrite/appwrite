# React Query Hooks Organization

This directory contains React Query hooks organized by domain/resource type for better maintainability and consistency.

## Current Structure

All hooks have been successfully migrated from the monolithic `hooks.ts` file (5000+ lines) into domain-specific modules:

### ✅ Completed Modules

- **`organizations.ts`** - Organizations, plans, and invoices
- **`teams.ts`** - Teams and memberships
- **`dependencies.ts`** - Query key dependencies for cache invalidation
- **`constants.ts`** - Shared constants (stale times, page sizes)
- **`projects.ts`** - Projects, project variables, and API keys
- **`users.ts`** - Project users and project teams
- **`databases.ts`** - Databases, tables, rows, columns, and indexes
- **`storage.ts`** - Buckets, files, and file tokens
- **`functions.ts`** - Functions, deployments, executions, templates, and variables
- **`sites.ts`** - Sites, deployments, logs, variables, frameworks, and specifications
- **`auth.ts`** - Auth security features, OAuth providers, MFA, and account sessions
- **`email.ts`** - Email templates
- **`webhooks.ts`** - Webhooks
- **`migrations.ts`** - Migrations and migration API keys
- **`smtp.ts`** - SMTP settings
- **`domains.ts`** - Domains/proxy rules
- **`backups.ts`** - Backup policies and archives
- **`locale.ts`** - Locale codes

## Module Structure Pattern

Each module follows this consistent structure:

```typescript
/**
 * React Query hooks for [Domain]
 *
 * Handles [description of what this module covers].
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
// ... other imports

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch [resource]
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetch[Resource](...) {
  // Implementation
}

// ============================================================================
// MUTATION FUNCTIONS
// ============================================================================

/**
 * Mutation function to create/update/delete [resource]
 */
export async function [action][Resource](...) {
  // Implementation
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch [resource]
 */
export function use[Resource](...) {
  // Implementation using useQuery/useMutation
}
```

## Key Principles

1. **Query Function Extraction** - All query functions are extracted and exported so they can be reused in both hooks and route loaders
2. **Consistent Naming** - Functions follow the pattern: `fetch[Resource]`, `create[Resource]`, `update[Resource]`, `delete[Resource]`
3. **Hooks Pattern** - Hooks use the pattern: `use[Resource]`, `useCreate[Resource]`, etc.
4. **Type Safety** - All functions are properly typed using Models from `@appwrite.io/console`
5. **Error Handling** - Consistent error handling and validation
6. **Cache Management** - Proper query invalidation using `Dependencies` constants
7. **Constants Usage** - All magic numbers replaced with constants from `constants.ts`
8. **Consistent Imports** - Standardized import order across all modules

## Constants

All hooks use centralized constants from `constants.ts`:

- **`DEFAULT_STALE_TIME`** (30 seconds) - For frequently-changing data
- **`LONG_STALE_TIME`** (5 minutes) - For rarely-changing data
- **`DEFAULT_PAGE_SIZE`** (25) - Standard pagination
- **`SMALL_PAGE_SIZE`** (10) - For smaller lists
- **`TINY_PAGE_SIZE`** (6) - For very small lists

See `STANDARDIZATION.md` for detailed patterns and guidelines.

## Benefits

- **Better Organization** - Related hooks are grouped together
- **Easier Navigation** - Find hooks by domain/resource type
- **Reduced File Size** - Each module is manageable (200-500 lines)
- **Clearer Dependencies** - See what each module depends on
- **Better Maintainability** - Changes to one domain don't affect others
