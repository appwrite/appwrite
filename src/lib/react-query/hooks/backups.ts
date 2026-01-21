/**
 * React Query hooks for Backups
 * 
 * Handles backup policies and archives for databases.
 */

import { useQuery } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, TINY_PAGE_SIZE, keepPreviousData } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch backup policies for a database
 * 
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchBackupPolicies(
  projectId: string,
  databaseId: string,
): Promise<Models.BackupPolicyList> {
  if (!projectId || !databaseId) {
    return { policies: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.equal('resourceType', 'database'),
    Query.equal('resourceId', databaseId),
  ]

  const response = await projectSdk.backups.listPolicies({ queries })
  return response
}

/**
 * Query function to fetch backup archives for a database
 * 
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchBackupArchives(
  projectId: string,
  databaseId: string,
  page: number = 0,
  limit: number = TINY_PAGE_SIZE,
): Promise<Models.BackupArchiveList> {
  if (!projectId || !databaseId) {
    return { archives: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.limit(limit),
    Query.offset(page * limit),
    Query.orderDesc('$createdAt'),
    Query.equal('resourceType', 'database'),
    Query.equal('resourceId', databaseId),
  ]

  const response = await projectSdk.backups.listArchives({ queries })
  return response
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch backup policies for a database
 */
export function useBackupPolicies(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  options?: { enabled?: boolean }
) {
  return useQuery({
    queryKey: ['backup-policies', 'project', projectId, 'database', databaseId],
    queryFn: () => fetchBackupPolicies(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId && (options?.enabled ?? true),
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })
}

/**
 * Hook to fetch backup archives for a database
 */
export function useBackupArchives(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  page: number = 0,
  limit: number = TINY_PAGE_SIZE,
  options?: { enabled?: boolean }
) {
  return useQuery({
    queryKey: ['backup-archives', 'project', projectId, 'database', databaseId, page, limit],
    queryFn: () => fetchBackupArchives(projectId!, databaseId!, page, limit),
    enabled: !!projectId && !!databaseId && (options?.enabled ?? true),
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })
}

