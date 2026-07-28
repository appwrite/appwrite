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

type RestorationDatabaseMapping = {
  oldId?: string
  newId?: string
  newName?: string
}

export type RestoredDatabaseTarget = {
  databaseId: string
  /** True when restore created/targeted a new database id. */
  isNew: boolean
  name?: string
  dbKind: 'tablesdb' | 'documentsdb' | 'vectorsdb'
}

function parseRestorationOptions(
  restoration: Models.BackupRestoration,
): Record<string, unknown> | null {
  try {
    const raw = restoration.options as unknown
    if (typeof raw === 'string') {
      return JSON.parse(raw) as Record<string, unknown>
    }
    if (raw && typeof raw === 'object') {
      return raw as Record<string, unknown>
    }
  } catch {
    // ignore malformed options
  }
  return null
}

function getRestorationDatabaseMappings(
  restoration: Models.BackupRestoration,
): RestorationDatabaseMapping[] {
  const options = parseRestorationOptions(restoration)
  if (!options) return []

  const databases = options.databases
  if (!databases || typeof databases !== 'object') return []

  const mappings = (databases as { database?: RestorationDatabaseMapping[] })
    .database
  return Array.isArray(mappings) ? mappings : []
}

/** Source / target database IDs from restoration options.databases.database[]. */
function getRestorationDatabaseIds(
  restoration: Models.BackupRestoration,
): string[] {
  const ids: string[] = []
  for (const mapping of getRestorationDatabaseMappings(restoration)) {
    if (typeof mapping?.oldId === 'string' && mapping.oldId) {
      ids.push(mapping.oldId)
    }
    if (typeof mapping?.newId === 'string' && mapping.newId) {
      ids.push(mapping.newId)
    }
  }
  return ids
}

export function getRestorationDbKind(
  restoration: Models.BackupRestoration,
): RestoredDatabaseTarget['dbKind'] {
  const resources = restoration.resources || []
  if (resources.includes('vectorsdb')) return 'vectorsdb'
  if (resources.includes('documentsdb')) return 'documentsdb'
  return 'tablesdb'
}

/**
 * Database to open after a restore completes.
 * - New restore: prefer newId when it differs from oldId; otherwise resolve via newName.
 * - Same-DB restore: use oldId only.
 */
export function getRestoredDatabaseTarget(
  restoration: Models.BackupRestoration,
): RestoredDatabaseTarget | null {
  const mapping = getRestorationDatabaseMappings(restoration)[0]
  if (!mapping) return null

  const newId =
    typeof mapping.newId === 'string' && mapping.newId.trim()
      ? mapping.newId.trim()
      : ''
  const oldId =
    typeof mapping.oldId === 'string' && mapping.oldId.trim()
      ? mapping.oldId.trim()
      : ''
  const newName =
    typeof mapping.newName === 'string' && mapping.newName.trim()
      ? mapping.newName.trim()
      : ''

  const hasDistinctNewId = !!newId && newId !== oldId
  // API often leaves newId empty for new restores but still sets newName
  const isNew = hasDistinctNewId || !!newName

  if (isNew) {
    return {
      databaseId: hasDistinctNewId ? newId : '',
      isNew: true,
      name: newName || undefined,
      dbKind: getRestorationDbKind(restoration),
    }
  }

  if (!oldId) return null
  return {
    databaseId: oldId,
    isNew: false,
    name: newName || undefined,
    dbKind: getRestorationDbKind(restoration),
  }
}

/**
 * Merge known restore target fields into restoration.options when the API
 * response omits them (needed for the "Open database" CTA).
 */
export function enrichRestorationTargetOptions(
  restoration: Models.BackupRestoration,
  target: { oldId: string; newId?: string; newName?: string },
): Models.BackupRestoration {
  const options = parseRestorationOptions(restoration) ?? {}
  const databases =
    options.databases && typeof options.databases === 'object'
      ? { ...(options.databases as Record<string, unknown>) }
      : {}
  const existing = Array.isArray(
    (databases as { database?: RestorationDatabaseMapping[] }).database,
  )
    ? [
        ...((databases as { database: RestorationDatabaseMapping[] })
          .database),
      ]
    : []
  const current = existing[0] ?? {}
  const apiNewId =
    typeof current.newId === 'string' && current.newId.trim()
      ? current.newId.trim()
      : ''
  const targetNewId =
    typeof target.newId === 'string' && target.newId.trim()
      ? target.newId.trim()
      : ''
  // Prefer a distinct client/API newId over empty API placeholders
  const resolvedNewId =
    (apiNewId && apiNewId !== target.oldId ? apiNewId : '') ||
    (targetNewId && targetNewId !== target.oldId ? targetNewId : '') ||
    ''

  const nextMapping: RestorationDatabaseMapping = {
    ...current,
    oldId: current.oldId || target.oldId,
    newId: resolvedNewId,
    newName: current.newName || target.newName || '',
  }
  return {
    ...restoration,
    options: JSON.stringify({
      ...options,
      databases: {
        ...databases,
        database: [nextMapping, ...existing.slice(1)],
      },
    }) as unknown as string,
  }
}

/**
 * Resolve a restored database ID by name when options.newId is missing.
 */
export async function fetchRestoredDatabaseIdByName(
  projectId: string,
  dbKind: RestoredDatabaseTarget['dbKind'],
  name: string,
): Promise<string | null> {
  if (!projectId || !name.trim()) return null
  const projectSdk = sdk.forProject(projectId)
  const queries = [Query.equal('name', name.trim()), Query.limit(5)]
  const response =
    dbKind === 'documentsdb'
      ? await projectSdk.documentsDB.list({ queries })
      : dbKind === 'vectorsdb'
        ? await projectSdk.vectorsDB.list({ queries })
        : await projectSdk.tablesDB.list({ queries })
  const match = (response.databases || []).find((db) => db.name === name.trim())
  return match?.$id ?? null
}

function restorationBelongsToDatabase(
  restoration: Models.BackupRestoration,
  databaseId: string,
  databaseArchiveIds: Set<string>,
): boolean {
  // Preferred: options.databases.database[].oldId / newId
  if (getRestorationDatabaseIds(restoration).includes(databaseId)) {
    return true
  }
  // Fallback: archive belongs to this database
  if (restoration.archiveId && databaseArchiveIds.has(restoration.archiveId)) {
    return true
  }
  return false
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
