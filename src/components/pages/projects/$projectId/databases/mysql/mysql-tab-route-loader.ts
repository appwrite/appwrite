import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { mysqlDatabaseQueryOptions, projectQueryOptions } from '@/lib/react-query/hooks'

export type MysqlTabLoaderData = {
  database: Models.DedicatedDatabase | null
}

export async function prefetchMysqlShellData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
): Promise<MysqlTabLoaderData> {
  await queryClient.ensureQueryData(projectQueryOptions(projectId))
  const database = await queryClient.ensureQueryData(
    mysqlDatabaseQueryOptions(projectId, databaseId),
  )

  return { database }
}
