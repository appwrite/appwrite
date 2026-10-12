import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { postgresDatabaseQueryOptions, projectQueryOptions } from '@/lib/react-query/hooks'

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

  return { database }
}
