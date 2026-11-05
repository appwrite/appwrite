/**
 * Engine-agnostic backup policy helpers for native dedicated databases.
 * Pass the database `engine` string; this module does not assume Postgres or MySQL.
 */

import { useQuery, queryOptions } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console' // pragma: allowlist secret
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { sdk } from '@/lib/appwrite/sdk' // pragma: allowlist secret
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import { DEFAULT_STALE_TIME } from './constants'

function normalizeDedicatedEngine(
  engine: string | null | undefined,
): string {
  const normalized = (engine ?? '').toLowerCase().trim()
  if (normalized === 'mongodb' || normalized === 'mongo') return 'mongodb'
  if (normalized === 'mysql' || normalized === 'mariadb') return 'mysql'
  return 'postgresql'
}

/** Backup policies for a native dedicated database (any engine). */
export async function fetchDedicatedBackupPolicies(
  projectId: string,
  databaseId: string,
  engine?: string | null,
): Promise<Models.BackupPolicyList> {
  if (!projectId || !databaseId) {
    return { policies: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  return dedicatedEngineService(projectSdk, engine).listBackupPolicies({
    databaseId,
    queries: [Query.orderDesc('$createdAt')],
  })
}

export function dedicatedBackupPoliciesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engine?: string | null,
) {
  const normalizedEngine = normalizeDedicatedEngine(engine)
  return queryOptions({
    queryKey: [
      'dedicated-backup-policies',
      'project',
      projectId,
      databaseId,
      normalizedEngine,
    ],
    queryFn: () =>
      fetchDedicatedBackupPolicies(projectId!, databaseId!, normalizedEngine),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function useDedicatedBackupPolicies(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  engine?: string | null,
) {
  return useQuery(
    dedicatedBackupPoliciesQueryOptions(projectId, databaseId, engine),
  )
}
