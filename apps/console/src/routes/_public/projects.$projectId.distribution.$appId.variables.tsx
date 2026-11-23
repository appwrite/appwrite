import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/distribution/$appId/variables/View'
import { canAccessDistributionAppSettings } from '@/lib/console-rbac-loader'
import {
  distributionAppQueryOptions,
  distributionVariablesQueryOptions,
  projectQueryOptions,
  projectVariablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/variables',
)({
  head: () => ({ meta: [{ title: pageTitle('Variables', 'Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, appId } = params
    const { queryClient } = context

    const canAccess = await canAccessDistributionAppSettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/distribution/$appId/targets',
        params: { projectId, appId },
        replace: true,
      })
    }

    try {
      await Promise.all([
        queryClient.ensureQueryData(
          distributionAppQueryOptions(projectId, appId),
        ),
        queryClient.ensureQueryData(projectQueryOptions(projectId)),
        queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
        queryClient.ensureQueryData(
          distributionVariablesQueryOptions(projectId, appId),
        ),
      ])
    } catch (error) {
      console.warn('Failed to load the distribution app variables:', error)
    }
  },
  component: VariablesPage,
})

function VariablesPage() {
  const { appId } = Route.useParams()
  return <View key={`distribution-app-${appId}-variables`} />
}
