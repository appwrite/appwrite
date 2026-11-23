import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/mysql/settings/Replication'
import {
  mysqlDatabaseQueryOptions,
  mysqlDatabaseReplicasQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/settings/replication',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    const database = await queryClient.ensureQueryData(
      mysqlDatabaseQueryOptions(projectId, databaseId),
    )

    if ((database?.replicas ?? 0) > 0) {
      await queryClient.ensureQueryData(
        mysqlDatabaseReplicasQueryOptions(projectId, databaseId),
      )
    }
  },
  component: View,
})
