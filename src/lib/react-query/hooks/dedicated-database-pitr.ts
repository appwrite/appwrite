/**
 * React Query hooks for dedicated-database PITR windows and restorations.
 */

import { queryOptions, useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import { DEFAULT_STALE_TIME } from './constants'

function normalizeDedicatedEngine(engine: string | null | undefined): string {
  const normalized = (engine ?? '').toLowerCase().trim()
  if (normalized === 'mongodb' || normalized === 'mongo') return 'mongodb'
  if (normalized === 'mysql' || normalized === 'mariadb') return 'mysql'
  return 'postgresql'
}

export const DEDICATED_PITR_RESTORATIONS_LIMIT = 10

export async function fetchDedicatedDatabasePitrWindows(
  projectId: string,
  databaseId: string,
  engine?: string | null,
): Promise<Models.DedicatedDatabasePITRWindows | null> {
  if (!projectId || !databaseId) return null
  const projectSdk = sdk.forProject(projectId)
  return dedicatedEngineService(projectSdk, engine).getPitr({ databaseId })
}

export async function fetchDedicatedDatabaseRestorations(
  projectId: string,
  databaseId: string,
  engine?: string | null,
  limit: number = DEDICATED_PITR_RESTORATIONS_LIMIT,
): Promise<Models.DedicatedDatabaseRestorationList> {
  if (!projectId || !databaseId) {
    return { restorations: [], total: 0 }
  }
  const projectSdk = sdk.forProject(projectId)
  return dedicatedEngineService(projectSdk, engine).listRestorations({
    databaseId,
    limit,
    offset: 0,
  })
}

export function dedicatedDatabasePitrWindowsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engine?: string | null,
  enabled: boolean = true,
) {
  const normalizedEngine = normalizeDedicatedEngine(engine)
  return queryOptions({
    queryKey: [
      'dedicated-pitr-windows',
      'project',
      projectId,
      databaseId,
      normalizedEngine,
    ],
    queryFn: () =>
      fetchDedicatedDatabasePitrWindows(
        projectId!,
        databaseId!,
        normalizedEngine,
      ),
    enabled: enabled && !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function dedicatedDatabaseRestorationsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engine?: string | null,
  enabled: boolean = true,
) {
  const normalizedEngine = normalizeDedicatedEngine(engine)
  return queryOptions({
    queryKey: [
      'dedicated-restorations',
      'project',
      projectId,
      databaseId,
      normalizedEngine,
    ],
    queryFn: () =>
      fetchDedicatedDatabaseRestorations(
        projectId!,
        databaseId!,
        normalizedEngine,
      ),
    enabled: enabled && !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function useDedicatedDatabasePitrWindows(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engine?: string | null,
  enabled: boolean = true,
) {
  return useQuery(
    dedicatedDatabasePitrWindowsQueryOptions(
      projectId,
      databaseId,
      engine,
      enabled,
    ),
  )
}

export function useDedicatedDatabaseRestorations(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engine?: string | null,
  enabled: boolean = true,
) {
  return useQuery(
    dedicatedDatabaseRestorationsQueryOptions(
      projectId,
      databaseId,
      engine,
      enabled,
    ),
  )
}
