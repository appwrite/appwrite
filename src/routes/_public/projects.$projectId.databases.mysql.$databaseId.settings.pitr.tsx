import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { View } from '@/components/pages/projects/$projectId/databases/mysql/settings/Pitr'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/settings/pitr',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseBackups) {
      throw redirect({
        to: '/projects/$projectId/databases/mysql/$databaseId/settings',
        params: {
          projectId: params.projectId,
          databaseId: params.databaseId,
        },
        replace: true,
      })
    }
  },
  component: View,
})
