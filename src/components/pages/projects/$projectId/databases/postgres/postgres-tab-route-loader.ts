import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  postgresColumnsQueryOptions,
  postgresDatabaseQueryOptions,
  postgresSchemasQueryOptions,
  postgresTablesQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

export type PostgresTabLoaderData = {
  database: Models.DedicatedDatabase | null
}

export async function prefetchPostgresShellData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
): Promise<PostgresTabLoaderData> {
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
    queryClient.ensureQueryData(
      postgresColumnsQueryOptions(projectId, databaseId),
    ),
  ])

  return { database }
}
