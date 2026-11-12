import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/postgres/settings/Replication'
import {
  postgresDatabaseQueryOptions,
  postgresDatabaseReplicasQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/settings/replication',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    const database = await queryClient.ensureQueryData(
      postgresDatabaseQueryOptions(projectId, databaseId),
    )

    if ((database?.replicas ?? 0) > 0) {
      await queryClient.ensureQueryData(
        postgresDatabaseReplicasQueryOptions(projectId, databaseId),
      )
    }
  },
  component: View,
})
