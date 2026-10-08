import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { resolveDatabaseLifecycleStatus } from '@/lib/databases/dedicated-database-status'

const postgresDatabaseQueryKey = (
  projectId: string,
  databaseId: string,
) => ['postgres-database', 'project', projectId, databaseId] as const

const dedicatedDatabasesQueryKey = (projectId: string) =>
  ['dedicated-databases', 'project', projectId] as const

/**
 * Reads dedicated database status from React Query cache
 * (postgres detail, product database detail, or merged dedicated list).
 * Used by mutation guards without importing hook queryOptions (avoids circular deps).
 */
export function readDatabaseOperationalStatus(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
): string | undefined {
  const postgres = queryClient.getQueryData<Models.DedicatedDatabase>(
    postgresDatabaseQueryKey(projectId, databaseId),
  )

  let productStatus: string | null | undefined
  const productEntries = queryClient.getQueriesData<{ status?: string | null }>(
    { queryKey: ['database', 'project', projectId, databaseId] },
  )
  for (const [, data] of productEntries) {
    if (data?.status) {
      productStatus = data.status
      break
    }
  }

  const dedicatedList = queryClient.getQueryData<{
    databases: Models.DedicatedDatabase[]
  }>(dedicatedDatabasesQueryKey(projectId))

  const dedicatedStatus = dedicatedList?.databases?.find(
    (db) => db.$id === databaseId,
  )?.status

  const resolved = resolveDatabaseLifecycleStatus(
    productStatus,
    dedicatedStatus,
  )
  if (resolved) return resolved
  return postgres?.status ?? undefined
}
