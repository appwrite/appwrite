/**
 * React Query hooks for Backups
 *
 * Handles backup policies and archives for databases.
 */

import { useQuery, queryOptions } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, TINY_PAGE_SIZE } from './constants'

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
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching backup policies for a database
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function backupPoliciesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['backup-policies', 'project', projectId, 'database', databaseId],
    queryFn: () => fetchBackupPolicies(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching backup archives for a database
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function backupArchivesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  page: number = 0,
  limit: number = TINY_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: [
      'backup-archives',
      'project',
      projectId,
      'database',
      databaseId,
      page,
      limit,
    ],
    queryFn: () => fetchBackupArchives(projectId!, databaseId!, page, limit),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
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
  options?: { enabled?: boolean },
) {
  const queryOpts = backupPoliciesQueryOptions(projectId, databaseId)
  return useQuery({
    ...queryOpts,
    enabled: queryOpts.enabled && (options?.enabled ?? true),
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
  options?: { enabled?: boolean },
) {
  const queryOpts = backupArchivesQueryOptions(
    projectId,
    databaseId,
    page,
    limit,
  )
  return useQuery({
    ...queryOpts,
    enabled: queryOpts.enabled && (options?.enabled ?? true),
  })
}
