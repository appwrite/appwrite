import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'

const postgresDatabaseQueryKey = (
  projectId: string,
  databaseId: string,
) => ['postgres-database', 'project', projectId, databaseId] as const

const productDatabaseQueryKey = (projectId: string, databaseId: string) =>
  ['database', 'project', projectId, databaseId] as const

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
  if (postgres?.status) return postgres.status

  const product = queryClient.getQueryData<{ status?: string | null }>(
    productDatabaseQueryKey(projectId, databaseId),
  )
  if (product?.status) return product.status

  const dedicatedList = queryClient.getQueryData<{
    databases: Models.DedicatedDatabase[]
  }>(dedicatedDatabasesQueryKey(projectId))

  return dedicatedList?.databases?.find((db) => db.$id === databaseId)?.status
}
