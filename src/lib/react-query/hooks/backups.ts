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

const IN_PROGRESS_RESTORATION_STATUSES = [
  'pending',
  'downloading',
  'processing',
] as const

const RECENT_RESTORATION_STATUSES = [
  ...IN_PROGRESS_RESTORATION_STATUSES,
  'completed',
  'failed',
] as const

/** Keep completed/failed restores visible across reload for this long. */
const RECENT_RESTORATION_WINDOW_MS = 24 * 60 * 60 * 1000

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

function restorationBelongsToDatabase(
  restoration: Models.BackupRestoration,
  databaseId: string,
  databaseArchiveIds: Set<string>,
): boolean {
  if (restoration.archiveId && databaseArchiveIds.has(restoration.archiveId)) {
    return true
  }
  if (
    Array.isArray(restoration.resources) &&
    restoration.resources.includes(databaseId)
  ) {
    return true
  }
  // options may include the source/new resource id as a JSON string or object
  try {
    const raw = restoration.options as unknown
    const options =
      typeof raw === 'string'
        ? (JSON.parse(raw) as Record<string, unknown>)
        : raw && typeof raw === 'object'
          ? (raw as Record<string, unknown>)
          : null
    if (!options) return false
    const candidates = [
      options.resourceId,
      options.newResourceId,
      options.databaseId,
    ]
    return candidates.some(
      (value) => typeof value === 'string' && value === databaseId,
    )
  } catch {
    return false
  }
}

function isRecentRestoration(restoration: Models.BackupRestoration): boolean {
  if (
    IN_PROGRESS_RESTORATION_STATUSES.includes(
      restoration.status as (typeof IN_PROGRESS_RESTORATION_STATUSES)[number],
    )
  ) {
    return true
  }
  const createdAt = Date.parse(restoration.$createdAt || restoration.$updatedAt)
  if (Number.isNaN(createdAt)) return false
  return Date.now() - createdAt <= RECENT_RESTORATION_WINDOW_MS
}

/**
 * List in-progress and recently completed/failed restorations for a database.
 * Returns restorations that have a migrationId (for migrations.get progress).
 */
export async function fetchDatabaseRestoreMigrations(
  projectId: string,
  databaseId: string,
): Promise<Models.BackupRestoration[]> {
  if (!projectId || !databaseId) return []

  const projectSdk = sdk.forProject(projectId)

  const [restorationsResponse, archivesResponse] = await Promise.all([
    projectSdk.backups.listRestorations({
      queries: [
        Query.equal('status', [...RECENT_RESTORATION_STATUSES]),
        Query.orderDesc('$createdAt'),
        Query.limit(50),
      ],
    }),
    projectSdk.backups.listArchives({
      queries: [
        Query.equal('resourceType', 'database'),
        Query.equal('resourceId', databaseId),
        Query.orderDesc('$createdAt'),
        Query.limit(100),
      ],
    }),
  ])

  const databaseArchiveIds = new Set(
    (archivesResponse.archives || []).map((a) => a.$id),
  )
  const restorations = restorationsResponse.restorations || []

  return restorations.filter(
    (restoration) =>
      !!restoration.migrationId &&
      isRecentRestoration(restoration) &&
      restorationBelongsToDatabase(
        restoration,
        databaseId,
        databaseArchiveIds,
      ),
  )
}

/**
 * Query options for recent restore migrations for a database.
 */
export function databaseRestoreMigrationsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'restorations',
      'project',
      projectId,
      'database',
      databaseId,
      'recent-migrations',
    ],
    queryFn: () => fetchDatabaseRestoreMigrations(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: 15 * 1000,
    refetchInterval: (query) => {
      const items = query.state.data
      if (
        items?.some((r) =>
          IN_PROGRESS_RESTORATION_STATUSES.includes(
            r.status as (typeof IN_PROGRESS_RESTORATION_STATUSES)[number],
          ),
        )
      ) {
        return 5000
      }
      return false
    },
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Hook for in-progress and recently completed/failed database restores.
 */
export function useDatabaseRestoreMigrations(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  options?: { enabled?: boolean },
) {
  const queryOpts = databaseRestoreMigrationsQueryOptions(projectId, databaseId)
  return useQuery({
    ...queryOpts,
    enabled: queryOpts.enabled && (options?.enabled ?? true),
  })
}
