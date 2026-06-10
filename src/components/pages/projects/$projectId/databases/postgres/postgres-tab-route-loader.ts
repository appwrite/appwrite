import type { QueryClient } from '@tanstack/react-query'
import {
  postgresDatabaseQueryOptions,
  postgresSchemasQueryOptions,
  postgresTablesQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

export async function prefetchPostgresShellData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
) {
  await queryClient.ensureQueryData(projectQueryOptions(projectId))
  const database = await queryClient.ensureQueryData(
    postgresDatabaseQueryOptions(projectId, databaseId),
  )

  await Promise.allSettled([
    queryClient.ensureQueryData(
      postgresSchemasQueryOptions(projectId, databaseId),
    ),
    queryClient.ensureQueryData(
      postgresTablesQueryOptions(projectId, databaseId),
    ),
  ])

  return { database }
}
