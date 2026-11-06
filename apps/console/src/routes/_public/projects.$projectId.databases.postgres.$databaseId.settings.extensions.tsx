import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/postgres/settings/Extensions'
import { postgresDatabaseExtensionsQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/settings/extensions',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(
      postgresDatabaseExtensionsQueryOptions(projectId, databaseId),
    )
  },
  component: View,
})
