import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { View } from '@/components/pages/projects/$projectId/databases/postgres/settings/Pitr'
import {
  postgresDatabaseQueryOptions,
  prefetchDedicatedDatabasePitrSettingsData,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/settings/pitr',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseBackups) {
      throw redirect({
        to: '/projects/$projectId/databases/postgres/$databaseId/settings',
        params: {
          projectId: params.projectId,
          databaseId: params.databaseId,
        },
        replace: true,
      })
    }
  },
  loader: ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context
    const database = queryClient.getQueryData(
      postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
    )

    prefetchDedicatedDatabasePitrSettingsData(
      queryClient,
      projectId,
      databaseId,
      'postgresql',
      database ?? null,
    )
  },
  component: View,
})
