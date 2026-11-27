/**
 * React Query hooks for dedicated-database PITR windows and restorations.
 */

import { queryOptions, useQuery, type QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  dedicatedDatabaseService,
  dedicatedDatabaseSourceFromEngine,
  dedicatedDatabaseSourceKey,
  type DedicatedDatabaseSource,
} from '@/lib/databases/dedicated-database-source'
import { DEFAULT_STALE_TIME } from './constants'

function normalizeDedicatedEngine(engine: string | null | undefined): string {
  const normalized = (engine ?? '').toLowerCase().trim()
  if (normalized === 'mongodb' || normalized === 'mongo') return 'mongodb'
  if (normalized === 'mysql' || normalized === 'mariadb') return 'mysql'
  return 'postgresql'
}

/** Product sources pass through; engine aliases collapse so caches share a key. */
function normalizePitrSource(
  source: DedicatedDatabaseSource,
): DedicatedDatabaseSource {
  if (source.type === 'product') return source
  return { type: 'engine', engine: normalizeDedicatedEngine(source.engine) }
}

export const DEDICATED_PITR_RESTORATIONS_LIMIT = 10

export async function fetchDedicatedDatabasePitrWindows(
  projectId: string,
  databaseId: string,
  source: DedicatedDatabaseSource,
): Promise<Models.DedicatedDatabasePITRWindows | null> {
  if (!projectId || !databaseId) return null
  return dedicatedDatabaseService(
    sdk.forProject(projectId),
    normalizePitrSource(source),
  ).getPitr({ databaseId })
}

export async function fetchDedicatedDatabaseRestorations(
  projectId: string,
  databaseId: string,
  source: DedicatedDatabaseSource,
  limit: number = DEDICATED_PITR_RESTORATIONS_LIMIT,
): Promise<Models.DedicatedDatabaseRestorationList> {
  if (!projectId || !databaseId) {
    return { restorations: [], total: 0 }
  }
  return dedicatedDatabaseService(
    sdk.forProject(projectId),
    normalizePitrSource(source),
  ).listRestorations({
    databaseId,
    limit,
    offset: 0,
  })
}

/**
 * Start a point-in-time restoration. Without a target it replays over the
 * database in place; with one it restores into that database. Product sources
 * restore in place only.
 */
export async function createDedicatedDatabasePitrRestoration(
  projectId: string,
  databaseId: string,
  source: DedicatedDatabaseSource,
  params: { targetTime: string; targetDatabaseId?: string },
): Promise<Models.DedicatedDatabaseRestoration> {
  return dedicatedDatabaseService(
    sdk.forProject(projectId),
    normalizePitrSource(source),
  ).createRestoration({
    databaseId,
    type: 'pitr',
    targetTime: params.targetTime,
    ...(params.targetDatabaseId
      ? { targetDatabaseId: params.targetDatabaseId }
      : {}),
  })
}

export function dedicatedDatabasePitrWindowsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  source: DedicatedDatabaseSource,
  enabled: boolean = true,
) {
  const normalizedSource = normalizePitrSource(source)
  return queryOptions({
    queryKey: [
      'dedicated-pitr-windows',
      'project',
      projectId,
      databaseId,
      dedicatedDatabaseSourceKey(normalizedSource),
    ],
    queryFn: () =>
      fetchDedicatedDatabasePitrWindows(
        projectId!,
        databaseId!,
        normalizedSource,
      ),
    enabled: enabled && !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
    meta: {
      skipInitialLoader: true,
    },
  })
}

export function dedicatedDatabaseRestorationsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  source: DedicatedDatabaseSource,
  enabled: boolean = true,
) {
  const normalizedSource = normalizePitrSource(source)
  return queryOptions({
    queryKey: [
      'dedicated-restorations',
      'project',
      projectId,
      databaseId,
      dedicatedDatabaseSourceKey(normalizedSource),
    ],
    queryFn: () =>
      fetchDedicatedDatabaseRestorations(
        projectId!,
        databaseId!,
        normalizedSource,
      ),
    enabled: enabled && !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
    meta: {
      skipInitialLoader: true,
    },
  })
}

export function useDedicatedDatabasePitrWindows(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  source: DedicatedDatabaseSource,
  enabled: boolean = true,
) {
  return useQuery(
    dedicatedDatabasePitrWindowsQueryOptions(
      projectId,
      databaseId,
      source,
      enabled,
    ),
  )
}

export function useDedicatedDatabaseRestorations(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  source: DedicatedDatabaseSource,
  enabled: boolean = true,
) {
  return useQuery(
    dedicatedDatabaseRestorationsQueryOptions(
      projectId,
      databaseId,
      source,
      enabled,
    ),
  )
}

/** Starts PITR queries in the background; does not block navigation. */
export function prefetchDedicatedDatabasePitrSettingsData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  source: DedicatedDatabaseSource,
  database: Models.DedicatedDatabase | null | undefined,
): void {
  if (!getActiveProfileFeatures().databasePitrRestore) return
  if (database?.pitr !== true) return

  void queryClient
    .prefetchQuery(
      dedicatedDatabasePitrWindowsQueryOptions(
        projectId,
        databaseId,
        source,
        true,
      ),
    )
    .catch(() => {
      /* PITR cards still render with per-query error states */
    })

  void queryClient
    .prefetchQuery(
      dedicatedDatabaseRestorationsQueryOptions(
        projectId,
        databaseId,
        source,
        true,
      ),
    )
    .catch(() => {
      /* PITR cards still render with per-query error states */
    })
}

export function syncDedicatedDatabasePitrCachesAfterUpdate(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  database: Models.DedicatedDatabase,
): void {
  if (!getActiveProfileFeatures().databasePitrRestore) return

  if (database.pitr !== true) {
    queryClient.removeQueries({
      queryKey: ['dedicated-pitr-windows', 'project', projectId, databaseId],
    })
    queryClient.removeQueries({
      queryKey: ['dedicated-restorations', 'project', projectId, databaseId],
    })
    return
  }

  prefetchDedicatedDatabasePitrSettingsData(
    queryClient,
    projectId,
    databaseId,
    dedicatedDatabaseSourceFromEngine(database.engine ?? ''),
    database,
  )
}
