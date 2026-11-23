# Hooks Standardization Guide

This document outlines the standardization patterns for all React Query hooks files.

## Import Order

All files should follow this consistent import order:

```typescript
// 1. React Query hooks
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'

// 2. React hooks
import { useMemo } from 'react'

// 3. Appwrite SDK types and utilities
import { Query, ID } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'

// 4. Type imports (local types)
import type { Organization, Project } from '@/lib/utils/mock-data'

// 5. Local utilities and SDK
import { sdk } from '@/lib/appwrite/sdk'
import { someUtility } from '@/lib/utils/some-utility'

// 6. Constants and shared hooks
import {
  DEFAULT_STALE_TIME,
  LONG_STALE_TIME,
  DEFAULT_PAGE_SIZE,
  keepPreviousData,
} from './constants'
import { Dependencies } from './dependencies'
import { useSomeHook } from './other-module'
```

## Constants Usage

Always use constants from `./constants` instead of magic numbers:

```typescript
// ❌ WRONG
staleTime: 30 * 1000, // 30 seconds
limit: number = 25

// ✅ CORRECT
staleTime: DEFAULT_STALE_TIME,
limit: number = DEFAULT_PAGE_SIZE
```

## Stale Time Values

- **`DEFAULT_STALE_TIME`** (30 seconds) - For most queries that change frequently
- **`LONG_STALE_TIME`** (5 minutes) - For rarely-changing data (organizations, projects, frameworks, locale codes)

## Page Sizes

- **`DEFAULT_PAGE_SIZE`** (10) - Default for list pagination (sites, functions, storage, auth, etc.)
- **`ROWS_DEFAULT_PAGE_SIZE`** (25) - Default for database rows, indexes, and columns
- **`ACTIVITY_DEFAULT_PAGE_SIZE`** (25) - Default for activity logs
- **`SMALL_PAGE_SIZE`** (10) - For smaller lists (variables, API keys)
- **`TINY_PAGE_SIZE`** (6) - For very small lists (backup archives)

## Placeholder Data

**Note:** We no longer use `placeholderData` or `initialData` in React Query hooks. The cache is used automatically when query keys match exactly between route loaders and hooks.

## Query Function Patterns

### Error Handling

For list queries, return empty arrays instead of throwing:

```typescript
export async function fetchResources(projectId: string) {
  if (!projectId) {
    return { resources: [], total: 0 }
  }
  // ... implementation
}
```

For single resource queries, throw errors:

```typescript
export async function fetchResource(projectId: string, resourceId: string) {
  if (!projectId || !resourceId) {
    throw new Error('Project ID and Resource ID are required')
  }
  // ... implementation
}
```

### Default Parameters

Always provide sensible defaults:

```typescript
export async function fetchResources(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
)
```

## Hook Patterns

### Paginated List Hooks

```typescript
export function useResources(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: resourcesData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery({
    queryKey: ['resources', 'project', projectId, page, limit, search],
    queryFn: () => fetchResources(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
  })

  const resources = useMemo(() => {
    if (!resourcesData?.resources) return []
    return resourcesData.resources
  }, [resourcesData])

  const totalPages = useMemo(() => {
    if (!resourcesData?.total) return 0
    return Math.ceil(resourcesData.total / limit)
  }, [resourcesData?.total, limit])

  return {
    resources,
    total: resourcesData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}
```

### Single Resource Hooks

```typescript
export function useResource(
  projectId: string | null | undefined,
  resourceId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['resource', 'project', projectId, resourceId],
    queryFn: () => fetchResource(projectId!, resourceId!),
    enabled: !!projectId && !!resourceId,
    staleTime: DEFAULT_STALE_TIME,
  })
}
```

### Mutation Hooks

```typescript
export function useCreateResource(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: ResourceData) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await createResource(projectId, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['resources', 'project', projectId],
      })
    },
  })
}
```

## Documentation

All exported functions should have JSDoc comments:

```typescript
/**
 * Query function to fetch resources for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated resources with total count
 */
```

## Remaining Standardization Tasks

1. ✅ Created constants file
2. ✅ Updated organizations.ts
3. ✅ Updated projects.ts
4. ✅ Updated teams.ts
5. ⏳ Update remaining files:
   - users.ts
   - databases.ts
   - storage.ts
   - functions.ts
   - sites.ts
   - auth.ts
   - email.ts
   - webhooks.ts
   - migrations.ts
   - smtp.ts
   - domains.ts
   - backups.ts
   - locale.ts
